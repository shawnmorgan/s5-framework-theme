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

		// Component source editor (Item 6): read + save a component's files. A
		// component is a distinct resource (not page-scoped), so it gets its own
		// route; the same manage_options + wp_rest nonce discipline applies (D23).
		register_rest_route(
			self::NAMESPACE,
			'/components/(?P<name>[A-Za-z0-9][A-Za-z0-9_-]*)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'handle_component_get' ),
					'permission_callback' => array( self::class, 'can_save' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle_component_save' ),
					'permission_callback' => array( self::class, 'can_save' ),
				),
			)
		);

		// Template PART source save (Phase C §3.3): a part is canonical HTML edited in
		// the canvas in template mode. Its own route (not page-scoped); same
		// manage_options + wp_rest nonce discipline. The body's `canonical` is validated
		// against the SAME closed vocabulary as a page, then written to the part file.
		register_rest_route(
			self::NAMESPACE,
			'/templates/(?P<name>[A-Za-z0-9][A-Za-z0-9_-]*)/save',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_template_save' ),
				'permission_callback' => array( self::class, 'can_save' ),
			)
		);
	}

	/**
	 * Save a template part's canonical HTML through the one door: validate against the
	 * closed vocabulary (ERROR ⇒ reject, file unchanged), write the file, recompile the
	 * bundle (a part change affects every page that includes it).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_template_save( \WP_REST_Request $request ) {
		$name = (string) $request->get_param( 'name' );
		if ( ! Templates::part_exists( $name ) ) {
			return new \WP_Error( 's5_no_part', __( 'Unknown template part.', 's5' ), array( 'status' => 404 ) );
		}

		$mode = (string) $request->get_param( 'mode' );
		if ( '' !== $mode && 'canonical' !== $mode ) {
			return new \WP_Error( 's5_bad_mode', __( 'Template parts save as canonical.', 's5' ), array( 'status' => 400 ) );
		}
		$canonical = (string) $request->get_param( 'canonical' );

		// SAME vocabulary gate as page canonical saves.
		try {
			$repo  = Cli\ValidateCommand::repo_root();
			$vocab = Stylesheet::load_vocabulary( $repo );
			$vocab = isset( $vocab['classes'] ) && is_array( $vocab['classes'] ) ? $vocab['classes'] : array();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_vocab', $e->getMessage(), array( 'status' => 500 ) );
		}
		$findings = Validator::validate( $canonical, $vocab, 'template:' . $name );
		if ( Validator::has_errors( $findings ) ) {
			return new \WP_REST_Response(
				array(
					'ok'          => false,
					'diagnostics' => self::shape_diagnostics( $findings ),
				),
				422
			);
		}

		$written = Templates::write_part( $name, $canonical );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// A part change ripples into every including page — recompile the bundle so
		// served renders reflect it. Recompiling all framework pages is heavier and is
		// handled by `wp s5 recompile`; the bundle rebuild keeps styling correct now.
		try {
			StyleBundle::build();
		} catch ( \Throwable $e ) {
			unset( $e ); // Non-fatal for the save result.
		}

		return new \WP_REST_Response(
			array(
				'ok'          => true,
				'diagnostics' => self::shape_diagnostics( $findings ),
			),
			200
		);
	}

	/**
	 * GET a component's source files (component.html, manifest.json, style.css).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_component_get( \WP_REST_Request $request ) {
		$name = (string) $request['name'];
		if ( ! ComponentRegistry::is_registered( $name ) ) {
			return new \WP_Error( 's5_unknown_component', __( 'Unknown component.', 's5' ), array( 'status' => 404 ) );
		}
		try {
			$c = ComponentRegistry::load( $name );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_component_load', $e->getMessage(), array( 'status' => 500 ) );
		}
		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'name'     => $name,
				'html'     => $c['html'],
				'manifest' => (string) wp_json_encode( $c['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
				'css'      => $c['css'],
			),
			200
		);
	}

	/**
	 * POST-save a component's source files, then rebuild the CSS bundle (D22).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_component_save( \WP_REST_Request $request ) {
		$name = (string) $request['name'];
		$html = (string) $request->get_param( 'html' );
		$man  = (string) $request->get_param( 'manifest' );

		if ( ! ComponentRegistry::is_registered( $name ) ) {
			return new \WP_Error( 's5_unknown_component', __( 'Unknown component.', 's5' ), array( 'status' => 404 ) );
		}

		// R5 / HANDOFF §0.5 + §3.5: component CSS is READ-ONLY here. The client cannot
		// write style.css through this endpoint (it is edited only in CSS/Tailwind, which
		// edits the vocabulary itself) — hide AND block. We always re-use the CSS already
		// on disk and ignore any css payload.
		try {
			$existing_css = (string) ( ComponentRegistry::load( $name )['css'] ?? '' );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_component_load', $e->getMessage(), array( 'status' => 500 ) );
		}

		// The component HTML is vocabulary-validated exactly like a page's canonical:
		// an ERROR rejects the save (loud), and the files are left unchanged.
		try {
			$repo  = Cli\ValidateCommand::repo_root();
			$vocab = Stylesheet::load_vocabulary( $repo );
			$vocab = isset( $vocab['classes'] ) && is_array( $vocab['classes'] ) ? $vocab['classes'] : array();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_vocab', $e->getMessage(), array( 'status' => 500 ) );
		}
		$findings = Validator::validate( $html, $vocab, 'component:' . $name );
		if ( Validator::has_errors( $findings ) ) {
			return new \WP_REST_Response(
				array(
					'ok'          => false,
					'diagnostics' => self::shape_diagnostics( $findings ),
				),
				422
			);
		}

		try {
			ComponentRegistry::save_component( $name, $html, $man, $existing_css );
			StyleBundle::build();
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response(
				array(
					'ok'          => false,
					'diagnostics' => array( array( 'message' => $e->getMessage() ) ),
				),
				422
			);
		}

		return new \WP_REST_Response(
			array(
				'ok'   => true,
				'name' => $name,
			),
			200
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
				// R1: page meta / SEO / schema lives behind the Page Settings gear.
				if ( ! Roles::can( 'editor.page_settings' ) ) {
					return new \WP_Error( 's5_role_page_settings', __( 'Your role cannot edit page settings.', 's5' ), array( 'status' => 403 ) );
				}
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
				// R1: the Embed primitive is Developer-only; block its insert for any
				// role the matrix denies (hidden AND blocked). Other components follow
				// the general editor grant.
				if ( 'embed' === strtolower( (string) $request->get_param( 'component' ) ) && ! Roles::can( 'editor.embed' ) ) {
					return new \WP_Error( 's5_role_embed', __( 'Your role cannot insert the Embed primitive.', 's5' ), array( 'status' => 403 ) );
				}
				$canonical = self::build_canonical_from_insert( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			case 'disable':
				// R1: disabling/re-enabling is a structure-tree operation.
				if ( ! Roles::can( 'editor.structure' ) ) {
					return new \WP_Error( 's5_role_structure', __( 'Your role cannot disable elements.', 's5' ), array( 'status' => 403 ) );
				}
				$canonical = self::build_canonical_from_disable( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			case 'class':
				$canonical = self::build_canonical_from_class_swap( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			default:
				return new \WP_Error(
					's5_bad_mode',
					__( 'Unknown save mode. Expected slots, canonical, insert, disable, class, or head.', 's5' ),
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

		// Loud door: a slots write must actually write something. Track effective
		// writes and the reasons any slot was skipped, so a payload of only malformed /
		// anchorless slots FAILS with diagnostics instead of silently 200-ing on an
		// unchanged canonical (Item 5d).
		$effective = 0;
		$skipped   = array();

		foreach ( $slots as $i => $slot ) {
			if ( ! is_array( $slot ) ) {
				$skipped[] = array( 'rule' => 's5_slot_shape', 'message' => sprintf( /* translators: %d: slot index. */ __( 'Slot %d is not an object.', 's5' ), (int) $i ) );
				continue;
			}
			$node  = isset( $slot['node'] ) ? (string) $slot['node'] : '';
			$type  = isset( $slot['type'] ) ? (string) $slot['type'] : 'text';
			$value = isset( $slot['value'] ) ? (string) $slot['value'] : '';
			$attr  = isset( $slot['attr'] ) ? (string) $slot['attr'] : '';

			if ( '' === $node ) {
				$skipped[] = array( 'rule' => 's5_slot_no_node', 'message' => sprintf( /* translators: %d: slot index. */ __( 'Slot %d has no data-node id — nothing to write.', 's5' ), (int) $i ) );
				continue;
			}

			$written = self::write_slot( $canonical, $node, $type, $value, $attr );
			if ( $written instanceof \WP_Error ) {
				return $written;
			}
			$canonical = $written;
			++$effective;
		}

		if ( 0 === $effective ) {
			return new \WP_Error(
				's5_no_effective_slots',
				__( 'No slot edits were applied — every slot was empty, malformed, or anchorless. Nothing was written.', 's5' ),
				array(
					'status'      => 400,
					'diagnostics' => ! empty( $skipped ) ? $skipped : array(
						array( 'rule' => 's5_no_effective_slots', 'message' => __( 'The slots payload produced zero effective writes.', 's5' ) ),
					),
				)
			);
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
			// Two loud failures collapse here (Item 5d): an anchor-less insert into a
			// page with no <main> content region, or a named anchor that does not exist.
			// Either way NOTHING is written — the component never lands after the footer.
			if ( '' === $at ) {
				return new \WP_Error(
					's5_no_main',
					__( 'Cannot append: this page has no main content region to insert into.', 's5' ),
					array(
						'status'      => 400,
						'diagnostics' => array( array( 'rule' => 's5_no_main', 'message' => __( 'Anchor-less insert requires a <main> region; the component is never placed after the site footer.', 's5' ) ) ),
					)
				);
			}
			return new \WP_Error( 's5_anchor', __( 'Insert anchor not found.', 's5' ), array( 'status' => 409 ) );
		}

		return $spliced;
	}

	/**
	 * Toggle data-disabled on a node (R4 / §2.4). Disabling sets
	 * data-disabled="true"; enabling removes it. The attribute stays IN canonical
	 * (source of truth) — the compiler drops the subtree from the derived render and
	 * mirror. Keyed by authored data-node; everything else stays byte-identical.
	 *
	 * Structural law: refuse to disable a node inside a data-component subtree
	 * (R5 — component internals are props+disable-of-the-component only, not surgery
	 * of its inner parts) and inside a data-lock subtree.
	 *
	 * Expects params: `node` (data-node id), `disabled` (bool).
	 *
	 * @param int              $post_id Page ID.
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error New canonical, or an error.
	 */
	private static function build_canonical_from_disable( int $post_id, \WP_REST_Request $request ) {
		$node     = (string) $request->get_param( 'node' );
		$disabled = (bool) $request->get_param( 'disabled' );

		if ( '' === $node ) {
			return new \WP_Error( 's5_no_node', __( 'No node supplied to disable.', 's5' ), array( 'status' => 400 ) );
		}

		$canonical = Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			return new \WP_Error( 's5_no_canonical', __( 'Page has no canonical source.', 's5' ), array( 'status' => 409 ) );
		}

		// Locate the node; refuse if it is not found.
		$processor = new \WP_HTML_Tag_Processor( $canonical );
		$found     = false;
		while ( $processor->next_tag() ) {
			if ( (string) $processor->get_attribute( 'data-node' ) === $node ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return new \WP_Error(
				's5_node_not_found',
				sprintf( /* translators: %s: data-node id. */ __( 'Node "%s" not found in canonical.', 's5' ), $node ),
				array( 'status' => 409 )
			);
		}

		if ( $disabled ) {
			$processor->set_attribute( 'data-disabled', 'true' );
		} else {
			$processor->remove_attribute( 'data-disabled' );
		}
		return $processor->get_updated_html();
	}

	/**
	 * Swap ONE class within a sanctioned axis on a node (R3, axis-swap-only).
	 *
	 * Narrow by construction: the controls manifest (ControlsManifest) defines each
	 * axis's full, mutually-exclusive class set. This save removes EVERY class on the
	 * node that belongs to the chosen axis's set, then adds the single chosen class
	 * (which must itself be a member of that set). There is NO free-form class path:
	 * a class outside the axis set is rejected, and only classes within the set are
	 * ever touched — an unrelated class on the node is left exactly as-is. The result
	 * still runs the standard closed-vocabulary validate + one-door persist, so even a
	 * manifest bug cannot smuggle an out-of-vocabulary class into canonical.
	 *
	 * Empty `class` clears the axis (removes all of the axis's classes) — a valid swap
	 * to "none".
	 *
	 * Expects params: `node`, `axis` (manifest key), `class` (member of the axis, or '').
	 *
	 * @param int              $post_id Page ID.
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error New canonical, or an error.
	 */
	private static function build_canonical_from_class_swap( int $post_id, \WP_REST_Request $request ) {
		$node   = (string) $request->get_param( 'node' );
		$axis   = (string) $request->get_param( 'axis' );
		$chosen = (string) $request->get_param( 'class' );

		if ( '' === $node || '' === $axis ) {
			return new \WP_Error( 's5_class_args', __( 'node and axis are required.', 's5' ), array( 'status' => 400 ) );
		}

		$axis_set = ControlsManifest::classes_for_axis( $axis );
		if ( empty( $axis_set ) ) {
			return new \WP_Error(
				's5_unknown_axis',
				sprintf( /* translators: %s: axis key. */ __( 'Unknown or unavailable style axis "%s".', 's5' ), $axis ),
				array( 'status' => 400 )
			);
		}

		// The chosen class must be a member of THIS axis (or empty = clear). No
		// free-form class ever passes this gate. Return a WP_Error so handle_save's
		// class-branch surfaces it (like every other builder) rather than persisting.
		if ( '' !== $chosen && ! in_array( $chosen, $axis_set, true ) ) {
			return new \WP_Error(
				's5_class_axis',
				sprintf( /* translators: 1: class, 2: axis. */ __( 'Class "%1$s" is not in the "%2$s" axis. Style controls may only swap within a sanctioned axis.', 's5' ), $chosen, $axis ),
				array(
					'status'      => 422,
					'diagnostics' => array(
						array(
							'severity'  => 'error',
							'rule'      => 's5.class.axis',
							'message'   => sprintf( /* translators: 1: class, 2: axis. */ __( 'Class "%1$s" is not in the "%2$s" axis.', 's5' ), $chosen, $axis ),
							'token'     => $chosen,
							'permitted' => $axis_set,
						),
					),
				)
			);
		}

		$canonical = Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			return new \WP_Error( 's5_no_canonical', __( 'Page has no canonical source.', 's5' ), array( 'status' => 409 ) );
		}

		// Locate the node + read its current class list.
		$processor = new \WP_HTML_Tag_Processor( $canonical );
		$found     = false;
		$current   = '';
		while ( $processor->next_tag() ) {
			if ( (string) $processor->get_attribute( 'data-node' ) === $node ) {
				$found   = true;
				$current = (string) $processor->get_attribute( 'class' );
				break;
			}
		}
		if ( ! $found ) {
			return new \WP_Error(
				's5_node_not_found',
				sprintf( /* translators: %s: data-node id. */ __( 'Node "%s" not found in canonical.', 's5' ), $node ),
				array( 'status' => 409 )
			);
		}

		// Rebuild the class list: keep everything NOT in this axis, drop the axis's
		// classes, then add the chosen one (if any). Order preserved for non-axis
		// classes; the chosen class appended.
		$classes = '' === trim( $current ) ? array() : preg_split( '/\s+/', trim( $current ) );
		$kept    = array();
		foreach ( (array) $classes as $c ) {
			if ( '' === $c || in_array( $c, $axis_set, true ) ) {
				continue; // Drop empties + any class from this axis.
			}
			$kept[] = $c;
		}
		if ( '' !== $chosen ) {
			$kept[] = $chosen;
		}

		if ( empty( $kept ) ) {
			$processor->remove_attribute( 'class' );
		} else {
			$processor->set_attribute( 'class', implode( ' ', $kept ) );
		}
		return $processor->get_updated_html();
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

		// SEO extras (§2.7): index/follow booleans, OG image URL, per-page JSON-LD.
		$index  = $request->get_param( 'index' );
		$follow = $request->get_param( 'follow' );
		if ( null !== $index ) {
			$head['index'] = self::truthy( $index );
		}
		if ( null !== $follow ) {
			$head['follow'] = self::truthy( $follow );
		}
		$og = $request->get_param( 'og_image' );
		if ( null !== $og ) {
			$head['og_image'] = esc_url_raw( (string) $og );
		}

		// Per-page JSON-LD: must be valid JSON (or empty to clear). Reject bad JSON loudly
		// rather than emitting broken structured data into <head>.
		$json_ld = $request->get_param( 'json_ld' );
		if ( null !== $json_ld ) {
			$json_ld = (string) $json_ld;
			if ( '' === trim( $json_ld ) ) {
				unset( $head['json_ld'] );
			} else {
				$decoded = json_decode( $json_ld, true );
				if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
					return new \WP_REST_Response(
						array(
							'ok'          => false,
							'diagnostics' => array(
								array(
									'severity' => 'error',
									'rule'     => 's5.jsonld.invalid',
									'message'  => __( 'JSON-LD is not valid JSON: ', 's5' ) . json_last_error_msg(),
								),
							),
						),
						422
					);
				}
				// Re-encode canonically so what we store is exactly what we emit.
				$head['json_ld'] = (string) wp_json_encode( $decoded );
			}
		}

		Storage::set_head( $post_id, $head );

		// SEO-provider write-through: if Yoast or RankMath is active, mirror the fields
		// into their meta so their front-end output matches S5's (native fallback = the
		// S5 emitter in Render). Only writes keys for whichever provider is present.
		self::seo_provider_write_through( $post_id, $head );

		return new \WP_REST_Response(
			array(
				'ok'   => true,
				'head' => array(
					'title'       => isset( $head['title'] ) ? (string) $head['title'] : '',
					'description' => isset( $head['description'] ) ? (string) $head['description'] : '',
					'canonical'   => isset( $head['canonical'] ) ? (string) $head['canonical'] : '',
					'index'       => ! isset( $head['index'] ) || (bool) $head['index'],
					'follow'      => ! isset( $head['follow'] ) || (bool) $head['follow'],
					'og_image'    => isset( $head['og_image'] ) ? (string) $head['og_image'] : '',
					'json_ld'     => isset( $head['json_ld'] ) ? (string) $head['json_ld'] : '',
				),
			),
			200
		);
	}

	/**
	 * Coerce a request param to bool (accepts true/false, 1/0, "true"/"false", "1"/"0").
	 *
	 * @param mixed $val Raw param.
	 * @return bool
	 */
	private static function truthy( $val ): bool {
		if ( is_bool( $val ) ) {
			return $val;
		}
		$s = strtolower( trim( (string) $val ) );
		return in_array( $s, array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Mirror the S5 head/SEO fields into an active SEO provider's post meta (Yoast or
	 * RankMath), so a site using one of those plugins shows consistent output. When
	 * neither is active this is a no-op and S5's own emitter (Render) is the source.
	 *
	 * @param int                  $post_id Page ID.
	 * @param array<string, mixed> $head    Head blob.
	 * @return void
	 */
	private static function seo_provider_write_through( int $post_id, array $head ): void {
		$title = isset( $head['title'] ) ? (string) $head['title'] : '';
		$desc  = isset( $head['description'] ) ? (string) $head['description'] : '';
		$canon = isset( $head['canonical'] ) ? (string) $head['canonical'] : '';
		$index = ! isset( $head['index'] ) || (bool) $head['index'];

		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) ) {
			if ( '' !== $title ) {
				update_post_meta( $post_id, '_yoast_wpseo_title', $title );
			}
			if ( '' !== $desc ) {
				update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
			}
			if ( '' !== $canon ) {
				update_post_meta( $post_id, '_yoast_wpseo_canonical', $canon );
			}
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $index ? '0' : '1' );
		}

		// RankMath.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			if ( '' !== $title ) {
				update_post_meta( $post_id, 'rank_math_title', $title );
			}
			if ( '' !== $desc ) {
				update_post_meta( $post_id, 'rank_math_description', $desc );
			}
			if ( '' !== $canon ) {
				update_post_meta( $post_id, 'rank_math_canonical_url', $canon );
			}
			update_post_meta( $post_id, 'rank_math_robots', $index ? array( 'index' ) : array( 'noindex' ) );
		}
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
