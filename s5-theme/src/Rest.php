<?php
/**
 * S5 Framework — Editor REST save endpoint (Editor MVP, Phase B2/B3).
 *
 * The editor's ONE save door: POST /s5/v1/pages/{id}/save. Every editor save —
 * Edit-lens slot write, Code-lens full-fragment write, head form — funnels
 * through the SAME pipeline the importer uses (Validator::validate ->
 * Compiler::persist_page). This class adds a REST *caller*, not a second
 * pipeline (EDITOR-DESIGN.md §2, DECISIONS.md D2).
 *
 * Modes:
 *   - "canonical": the submitted fragment replaces canonical verbatim.
 *   - "slots":     per-node values are written into the CURRENT canonical via
 *                  WP_HTML_Tag_Processor, keyed by authored data-node (D3/D4);
 *                  server-side only — the client DOM is never trusted.
 *   - "head":      _s5_head structured fields updated; canonical untouched (B3).
 *
 * On a validation ERROR the canonical is left UNCHANGED and diagnostics return
 * to the editor (loud, never silent). Gated behind manage_options + nonce.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles the editor save route.
 */
final class Rest {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 's5/v1';

	/**
	 * Rich-slot allowlist: bold, italic, link ONLY (DECISIONS.md D9).
	 *
	 * @var array<string, array<string, bool>>
	 */
	private const RICH_ALLOWED = array(
		'strong' => array(),
		'em'     => array(),
		'a'      => array(
			'href'   => true,
			'title'  => true,
			'rel'    => true,
			'target' => true,
		),
	);

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register /s5/v1/pages/{id}/save.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_save' ),
				'permission_callback' => array( self::class, 'can_save' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Permission check: single-user MVP — manage_options. The REST nonce
	 * (X-WP-Nonce) is validated by core before this runs.
	 *
	 * @return bool
	 */
	public static function can_save(): bool {
		return current_user_can( Editor::CAPABILITY );
	}

	/**
	 * Handle a save request.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_save( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];

		$post = get_post( $post_id );
		if ( ! ( $post instanceof \WP_Post ) || ! Storage::is_framework_page( $post_id ) ) {
			return new \WP_Error( 's5_not_framework_page', __( 'Not a framework page.', 's5' ), array( 'status' => 404 ) );
		}

		$mode = (string) $request->get_param( 'mode' );

		switch ( $mode ) {
			case 'head':
				return self::save_head( $post_id, $request );
			case 'slots':
				$canonical = self::build_canonical_from_slots( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			case 'canonical':
				$canonical = (string) $request->get_param( 'canonical' );
				return self::persist_canonical( $post_id, $canonical );
			case 'insert':
				$canonical = self::build_canonical_from_insert( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			default:
				return new \WP_Error(
					's5_bad_mode',
					__( 'Unknown save mode. Expected slots, canonical, insert, or head.', 's5' ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Validate + persist a canonical fragment through the ONE door.
	 *
	 * @param int    $post_id   Page ID.
	 * @param string $canonical Canonical body HTML.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function persist_canonical( int $post_id, string $canonical ) {
		// Same vocabulary the importer/validator use — reuse, do not re-derive.
		try {
			$repo  = Cli\ValidateCommand::repo_root();
			$vocab = Stylesheet::load_vocabulary( $repo );
			$vocab = isset( $vocab['classes'] ) && is_array( $vocab['classes'] ) ? $vocab['classes'] : array();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_vocab', $e->getMessage(), array( 'status' => 500 ) );
		}

		// SAME gate as import. ERROR => reject; canonical stays UNCHANGED.
		$findings = Validator::validate( $canonical, $vocab, 'editor' );
		if ( Validator::has_errors( $findings ) ) {
			return new \WP_REST_Response(
				array(
					'ok'          => false,
					'diagnostics' => self::shape_diagnostics( $findings ),
				),
				422
			);
		}

		// SAME call as import/recompile/restore — the one door.
		try {
			$result = Compiler::persist_page( $post_id, $canonical );
			unset( $result );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_persist', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'ok'          => true,
				'render'      => Storage::get_render_html( $post_id ),
				'dynamic'     => Storage::is_dynamic( $post_id ),
				'diagnostics' => self::shape_diagnostics( $findings ),
			),
			200
		);
	}

	/**
	 * Build a new canonical by writing slot values into the CURRENT canonical,
	 * server-side, via WP_HTML_Tag_Processor keyed by authored data-node (D3/D4).
	 *
	 * Expects param `slots`: [ { node, type, value, attr? }, ... ].
	 *
	 * @param int              $post_id Page ID.
	 * @param \WP_REST_Request $request The request.
	 * @return string|\WP_Error New canonical, or an error.
	 */
	private static function build_canonical_from_slots( int $post_id, \WP_REST_Request $request ) {
		$slots = $request->get_param( 'slots' );
		if ( ! is_array( $slots ) || empty( $slots ) ) {
			return new \WP_Error( 's5_no_slots', __( 'No slot edits supplied.', 's5' ), array( 'status' => 400 ) );
		}

		$canonical = Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			return new \WP_Error( 's5_no_canonical', __( 'Page has no canonical HTML.', 's5' ), array( 'status' => 409 ) );
		}

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$node  = isset( $slot['node'] ) ? (string) $slot['node'] : '';
			$type  = isset( $slot['type'] ) ? (string) $slot['type'] : 'text';
			$value = isset( $slot['value'] ) ? (string) $slot['value'] : '';
			$attr  = isset( $slot['attr'] ) ? (string) $slot['attr'] : '';

			if ( '' === $node ) {
				continue;
			}

			$written = self::write_slot( $canonical, $node, $type, $value, $attr );
			if ( $written instanceof \WP_Error ) {
				return $written;
			}
			$canonical = $written;
		}

		return $canonical;
	}

	/**
	 * Build a new canonical by inserting a registered component (palette insert).
	 *
	 * Expects params: `component` (registered name), optional `at` (data-node anchor;
	 * absent/empty = append). The component is expanded server-side with fresh,
	 * recursively re-keyed data-node ids (ComponentInsert), refusing inserts inside a
	 * data-lock subtree. Copy-on-insert: the result is plain canonical HTML, then
	 * persist_canonical() runs the SAME validate + one-door persist as every write.
	 *
	 * @param int              $post_id Page ID.
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error New canonical, or an error.
	 */
	private static function build_canonical_from_insert( int $post_id, \WP_REST_Request $request ) {
		$name = (string) $request->get_param( 'component' );
		$at   = (string) $request->get_param( 'at' );

		if ( '' === $name || ! ComponentRegistry::is_registered( $name ) ) {
			return new \WP_Error( 's5_unknown_component', __( 'Unknown or unregistered component.', 's5' ), array( 'status' => 400 ) );
		}

		try {
			$component = ComponentRegistry::load( $name );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_component_load', $e->getMessage(), array( 'status' => 400 ) );
		}

		$canonical = Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			return new \WP_Error( 's5_no_canonical', __( 'Page has no canonical source.', 's5' ), array( 'status' => 409 ) );
		}

		// Structural law (Amendment A): refuse insert on/inside a data-lock subtree.
		if ( ComponentInsert::anchor_is_locked( $canonical, $at ) ) {
			return new \WP_Error( 's5_locked', __( 'Cannot insert into a locked (data-lock) region.', 's5' ), array( 'status' => 409 ) );
		}

		// Server-assigned recursive node ids, then byte-surgical splice.
		$existing = ComponentInsert::existing_ids( $canonical );
		$rekeyed  = ComponentInsert::rekey( $component['html'], $component['name'], $existing );
		$spliced  = ComponentInsert::splice( $canonical, $rekeyed['markup'], $at );
		if ( null === $spliced ) {
			return new \WP_Error( 's5_anchor', __( 'Insert anchor not found.', 's5' ), array( 'status' => 409 ) );
		}

		return $spliced;
	}

	/**
	 * Write a single slot value into canonical at the node carrying
	 * data-node="{node}". Surgical (byte-preserving elsewhere) via the tag
	 * processor. For text/rich we replace the element's inner HTML between its
	 * open and close tags; for attribute-style slots we set the attribute.
	 *
	 * @param string $canonical Current canonical HTML.
	 * @param string $node      Target data-node id.
	 * @param string $type      Slot type (text|rich|img|link|bg-img|embed).
	 * @param string $value     New value.
	 * @param string $attr      Attribute name for attribute-style writes (link/img).
	 * @return string|\WP_Error
	 */
	private static function write_slot( string $canonical, string $node, string $type, string $value, string $attr ) {
		$processor = new \WP_HTML_Tag_Processor( $canonical );

		// Locate the node by its authored data-node attribute.
		$found = false;
		while ( $processor->next_tag() ) {
			if ( (string) $processor->get_attribute( 'data-node' ) === $node ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return new \WP_Error(
				's5_node_not_found',
				sprintf( /* translators: %s: data-node id. */ __( 'Editable node "%s" not found in canonical.', 's5' ), $node ),
				array( 'status' => 409 )
			);
		}

		switch ( $type ) {
			case 'text':
				return self::replace_inner( $canonical, $node, esc_html( $value ) );

			case 'rich':
				$safe = wp_kses( $value, self::RICH_ALLOWED );
				return self::replace_inner( $canonical, $node, $safe );

			case 'link':
				$processor->set_attribute( 'href', esc_url_raw( $value ) );
				return $processor->get_updated_html();

			case 'img':
				// Attribute name defaults to alt; src edits carry attr="src".
				$name = '' !== $attr ? $attr : 'alt';
				if ( 'src' === $name ) {
					// Logical assets/... path; media resolver owns srcset at compile.
					$processor->set_attribute( 'src', $value );
				} else {
					$processor->set_attribute( 'alt', sanitize_text_field( $value ) );
				}
				return $processor->get_updated_html();

			case 'src':
				// Embed URL: set the src attribute on the node (iframe/embed). The URL
				// is escaped; the node itself is authored (data-node) so only its src
				// attribute changes, everything else stays byte-identical.
				$processor->set_attribute( 'src', esc_url_raw( $value ) );
				return $processor->get_updated_html();

			case 'list':
				// List slot: the value is newline-separated item text. Rebuild the
				// node's inner as sanitized <li> items. Each item is plain text
				// (esc_html) — a list is structure, not rich content.
				$items = array();
				foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
					$line = trim( (string) $line );
					if ( '' !== $line ) {
						$items[] = '<li>' . esc_html( $line ) . '</li>';
					}
				}
				return self::replace_inner( $canonical, $node, implode( '', $items ) );

			default:
				return new \WP_Error(
					's5_unsupported_slot',
					sprintf( /* translators: %s: slot type. */ __( 'Slot type "%s" is not editable in this build.', 's5' ), $type ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Replace the inner HTML of the element carrying data-node="{node}".
	 *
	 * WP_HTML_Tag_Processor (WP 6.7) does not expose inner-HTML replacement, so we
	 * locate the element's bounds by re-scanning for the open tag with the matching
	 * data-node, then splice between the end of the open tag and the matching close
	 * tag. Only the inner region changes; everything else stays byte-identical.
	 *
	 * @param string $canonical Current canonical HTML.
	 * @param string $node      Target data-node id.
	 * @param string $inner     Already-sanitized inner HTML.
	 * @return string|\WP_Error
	 */
	private static function replace_inner( string $canonical, string $node, string $inner ) {
		// Find the open tag that carries data-node="{node}" (single or double quoted).
		$pattern = '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bdata-node=(["\'])' . preg_quote( $node, '/' ) . '\2[^>]*>/';
		if ( ! preg_match( $pattern, $canonical, $m, PREG_OFFSET_CAPTURE ) ) {
			return new \WP_Error(
				's5_node_not_found',
				sprintf( /* translators: %s: data-node id. */ __( 'Editable node "%s" not found for inner write.', 's5' ), $node ),
				array( 'status' => 409 )
			);
		}

		$tag        = $m[1][0];
		$open_start = (int) $m[0][1];
		$open_end   = $open_start + strlen( $m[0][0] );

		// Find the matching close tag, accounting for nested same-name tags.
		$depth       = 1;
		$offset      = $open_end;
		$len         = strlen( $canonical );
		$close_start = -1;
		$re          = '/<(\/?)' . preg_quote( $tag, '/' ) . '\b[^>]*>/i';
		while ( $offset < $len && preg_match( $re, $canonical, $tm, PREG_OFFSET_CAPTURE, $offset ) ) {
			$is_close = '' !== $tm[1][0];
			$at       = (int) $tm[0][1];
			if ( $is_close ) {
				--$depth;
				if ( 0 === $depth ) {
					$close_start = $at;
					break;
				}
			} else {
				++$depth;
			}
			$offset = $at + strlen( $tm[0][0] );
		}

		if ( $close_start < 0 ) {
			return new \WP_Error(
				's5_unbalanced',
				sprintf( /* translators: %s: data-node id. */ __( 'Could not find the closing tag for node "%s".', 's5' ), $node ),
				array( 'status' => 409 )
			);
		}

		return substr( $canonical, 0, $open_end ) . $inner . substr( $canonical, $close_start );
	}

	/**
	 * Save head structured fields (title/description/canonical). Canonical body is
	 * NOT touched — head is a separate blob (Phase B3 head sidebar).
	 *
	 * @param int              $post_id Page ID.
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function save_head( int $post_id, \WP_REST_Request $request ) {
		$head = Storage::get_head( $post_id );

		$fields = array( 'title', 'description', 'canonical' );
		foreach ( $fields as $field ) {
			$val = $request->get_param( $field );
			if ( null === $val ) {
				continue;
			}
			if ( 'canonical' === $field ) {
				$head[ $field ] = esc_url_raw( (string) $val );
			} else {
				$head[ $field ] = sanitize_text_field( (string) $val );
			}
		}

		Storage::set_head( $post_id, $head );

		return new \WP_REST_Response(
			array(
				'ok'   => true,
				'head' => array(
					'title'       => isset( $head['title'] ) ? (string) $head['title'] : '',
					'description' => isset( $head['description'] ) ? (string) $head['description'] : '',
					'canonical'   => isset( $head['canonical'] ) ? (string) $head['canonical'] : '',
				),
			),
			200
		);
	}

	/**
	 * Shape Validator findings into an agent-grade diagnostic list for the editor.
	 *
	 * @param array<int, array<string, mixed>> $findings Validator findings.
	 * @return array<int, array<string, mixed>>
	 */
	private static function shape_diagnostics( array $findings ): array {
		$out = array();
		foreach ( $findings as $f ) {
			$out[] = array(
				'severity'  => isset( $f['severity'] ) ? (string) $f['severity'] : 'error',
				'rule'      => isset( $f['rule'] ) ? (string) $f['rule'] : '',
				'message'   => isset( $f['message'] ) ? (string) $f['message'] : '',
				'line'      => isset( $f['line'] ) ? (int) $f['line'] : 0,
				'token'     => isset( $f['token'] ) ? (string) $f['token'] : '',
				'permitted' => isset( $f['permitted'] ) ? $f['permitted'] : null,
			);
		}
		return $out;
	}
}
