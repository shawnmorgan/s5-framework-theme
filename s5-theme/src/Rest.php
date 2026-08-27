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
 * to the editor (loud, never silent). Gated behind the edit_posts capability
 * floor + the R1 Roles::can() resolution + nonce.
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

		// Component CREATE (collection-level): POST /components with { name } mints a new
		// components/<name>/ with a valid starter, so it is registered + openable in the
		// builder. Separate from the per-name save route (which only EDITS existing).
		register_rest_route(
			self::NAMESPACE,
			'/components',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_component_create' ),
				'permission_callback' => array( self::class, 'can_save' ),
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

		// HEADLESS AUTHORING DOORS (audit blocker B1). Tokens, the class-vocabulary CSS,
		// and tracking-script integrations were only writable through admin-post nonce
		// handlers a login session must satisfy — an Application-Password / CLI / MCP agent
		// could not reach them, so the agent could not self-provision the design system its
		// own pages depend on. These REST routes expose the SAME writes, capability-gated
		// (Application Passwords satisfy current_user_can without a cookie nonce).
		register_rest_route(
			self::NAMESPACE,
			'/tokens',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_rest_save_token' ),
				'permission_callback' => static function () {
					return current_user_can( Editor::CAPABILITY ) && Roles::can( 'settings.styleguide_edit' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/css',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle_rest_save_css' ),
				'permission_callback' => static function () {
					return current_user_can( Editor::CAPABILITY ) && Roles::can( 'settings.css' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/integrations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'handle_rest_get_integrations' ),
					'permission_callback' => static function () {
						return current_user_can( Editor::CAPABILITY ) && Roles::can( 'settings.integrations' );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'handle_rest_save_integrations' ),
					'permission_callback' => static function () {
						return current_user_can( Editor::CAPABILITY ) && Roles::can( 'settings.integrations' );
					},
				),
			)
		);
	}

	/**
	 * Headless token save (B1). Body: { name: "--s5-color-brand", value: "#123456" }.
	 * Reuses Styles::update_token + a bundle rebuild — identical effect to the UI door.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_rest_save_token( \WP_REST_Request $request ) {
		$name  = (string) $request->get_param( 'name' );
		$value = (string) $request->get_param( 'value' );

		$res = Styles::update_token( $name, $value );
		if ( is_wp_error( $res ) ) {
			// A no-op (unchanged value) is a success for an idempotent agent write.
			if ( 's5_token_noop' === $res->get_error_code() ) {
				return new \WP_REST_Response( array( 'ok' => true, 'changed' => false ), 200 );
			}
			return $res;
		}

		try {
			StyleBundle::build();
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( array( 'ok' => true, 'changed' => true, 'recompiled' => false, 'message' => $e->getMessage() ), 200 );
		}
		return new \WP_REST_Response( array( 'ok' => true, 'changed' => true, 'recompiled' => true ), 200 );
	}

	/**
	 * Headless CSS-layer save (B1). Body: { file: "components"|"utilities"|"tokens"|
	 * "scales"|"custom", code: "..." }. Writes the allow-listed site style file + rebuilds.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_rest_save_css( \WP_REST_Request $request ) {
		$which = sanitize_key( (string) $request->get_param( 'file' ) );
		$code  = (string) $request->get_param( 'code' );

		$res = Settings::write_css_layer( $which, $code );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		try {
			StyleBundle::build();
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( array( 'ok' => true, 'recompiled' => false, 'message' => $e->getMessage() ), 200 );
		}
		return new \WP_REST_Response( array( 'ok' => true, 'recompiled' => true ), 200 );
	}

	/**
	 * Headless integrations read (B1) — the current list, so an agent can diff/patch.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_rest_get_integrations( \WP_REST_Request $request ) {
		unset( $request );
		return new \WP_REST_Response( array( 'ok' => true, 'integrations' => Integrations::all() ), 200 );
	}

	/**
	 * Headless integrations save (B1). Body: { integrations: [ { id?, name, enabled,
	 * point, code, conditions? }, ... ] }. Persists the full list verbatim (raw scripts).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_rest_save_integrations( \WP_REST_Request $request ) {
		$rows = $request->get_param( 'integrations' );
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 's5_bad_integrations', __( 'Body must include an "integrations" array.', 's5' ), array( 'status' => 400 ) );
		}
		Integrations::save( $rows );
		return new \WP_REST_Response( array( 'ok' => true, 'integrations' => Integrations::all() ), 200 );
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
		// R1: Templates is a Theme Settings screen (settings.templates); the same
		// resource-level gate the router uses to hide/show the screen also gates
		// its own save route, so a direct REST call cannot do what the UI hides.
		if ( ! Roles::can( 'settings.templates' ) ) {
			return new \WP_Error( 's5_role_templates', __( 'Your role cannot edit template parts.', 's5' ), array( 'status' => 403 ) );
		}
		$name = (string) $request->get_param( 'name' );
		if ( ! Templates::part_exists( $name ) ) {
			return new \WP_Error( 's5_no_part', __( 'Unknown template part.', 's5' ), array( 'status' => 404 ) );
		}

		$mode = (string) $request->get_param( 'mode' );
		// A template part is canonical HTML edited in the SAME canvas as a page, so it must
		// accept the SAME granular edit modes — otherwise text/class/disable/reorder edits
		// in template mode fail with a blank "Save rejected" (audit blocker B3). Every
		// structural mode runs the shared page builder against the PART's canonical
		// (passed as $source) instead of a post's, then validates + writes the part file.
		$allowed = array( '', 'canonical', 'insert', 'slots', 'class', 'disable', 'reorder' );
		if ( ! in_array( $mode, $allowed, true ) ) {
			return new \WP_Error( 's5_bad_mode', __( 'Unsupported template save mode.', 's5' ), array( 'status' => 400 ) );
		}

		$part_source = Templates::read_part( $name );

		if ( 'insert' === $mode ) {
			// Component insert into a template part (defect #8: the palette insert
			// previously only existed on the page-scoped route — a part had no way
			// to receive a dropped component at all). Same embed-primitive gate as
			// the page insert path.
			$component_name = (string) $request->get_param( 'component' );
			if ( 'embed' === strtolower( $component_name ) && ! Roles::can( 'editor.embed' ) ) {
				return new \WP_Error( 's5_role_embed', __( 'Your role cannot insert the Embed primitive.', 's5' ), array( 'status' => 403 ) );
			}
			$at        = (string) $request->get_param( 'at' );
			$expanded  = self::expand_component_into( $part_source, $component_name, $at, false );
			if ( $expanded instanceof \WP_Error ) {
				return $expanded;
			}
			$canonical = $expanded;
		} elseif ( 'slots' === $mode ) {
			$built = self::build_canonical_from_slots( 0, $request, $part_source );
			if ( $built instanceof \WP_Error ) {
				return $built;
			}
			$canonical = $built;
		} elseif ( 'class' === $mode ) {
			$built = self::build_canonical_from_class_swap( 0, $request, $part_source );
			if ( $built instanceof \WP_Error ) {
				return $built;
			}
			$canonical = $built;
		} elseif ( 'disable' === $mode ) {
			if ( ! Roles::can( 'editor.structure' ) ) {
				return new \WP_Error( 's5_role_structure', __( 'Your role cannot disable elements.', 's5' ), array( 'status' => 403 ) );
			}
			$built = self::build_canonical_from_disable( 0, $request, $part_source );
			if ( $built instanceof \WP_Error ) {
				return $built;
			}
			$canonical = $built;
		} elseif ( 'reorder' === $mode ) {
			if ( ! Roles::can( 'editor.structure' ) ) {
				return new \WP_Error( 's5_role_structure', __( 'Your role cannot reorder elements.', 's5' ), array( 'status' => 403 ) );
			}
			$built = self::build_canonical_from_reorder( 0, $request, $part_source );
			if ( $built instanceof \WP_Error ) {
				return $built;
			}
			$canonical = $built;
		} else {
			$canonical = (string) $request->get_param( 'canonical' );
		}

		// SAME vocabulary gate as page canonical saves.
		try {
			$repo  = Cli\ValidateCommand::repo_root();
			$vocab = Stylesheet::load_vocabulary( $repo );
			$vocab = isset( $vocab['classes'] ) && is_array( $vocab['classes'] ) ? $vocab['classes'] : array();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_vocab', $e->getMessage(), array( 'status' => 500 ) );
		}
		// Role-aware, same as the page save door (audit M3): a Developer DEFINES the
		// vocabulary, so a not-yet-declared class is a WARNING here rather than the
		// hard wall the closed vocabulary presents to marketers/clients. Security
		// rules stay ERROR for everyone regardless — see Validator's own docblock.
		$dev      = ( 'developer' === Roles::role_for_user() );
		$findings = Validator::validate( $canonical, $vocab, 'template:' . $name, $dev );
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

		// A part change ripples into every including page. Fresh re-audit finding:
		// this used to ONLY rebuild the CSS bundle and leave it at that -- the
		// comment here literally said page recompile was "handled by `wp s5
		// recompile`", but a browser user editing a part via Theme Settings ->
		// Templates has no way to know that CLI command exists, so every served
		// page kept showing the OLD part content indefinitely (each page's
		// _s5_render_html is a compile-time expansion of <s5-part>, not a
		// request-time include). Recompile every framework page now, the same
		// way `wp s5 recompile --all` does, so the save is actually visible.
		$recompiled = 0;
		foreach ( self::all_framework_page_ids() as $page_id ) {
			$page_canonical = Storage::get_canonical_html( $page_id );
			if ( '' === $page_canonical ) {
				continue;
			}
			try {
				Compiler::persist_page( $page_id, $page_canonical );
				++$recompiled;
			} catch ( \RuntimeException $e ) {
				unset( $e ); // One page's recompile failure must not fail the part save.
			}
		}

		try {
			StyleBundle::build();
		} catch ( \Throwable $e ) {
			unset( $e ); // Non-fatal for the save result.
		}

		return new \WP_REST_Response(
			array(
				'ok'          => true,
				'diagnostics' => self::shape_diagnostics( $findings ),
				'recompiled'  => $recompiled,
			),
			200
		);
	}

	/**
	 * Every framework page's post ID (same query `wp s5 recompile --all` uses).
	 *
	 * @return array<int, int>
	 */
	private static function all_framework_page_ids(): array {
		$ids = get_posts(
			array(
				'post_type'      => Storage::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => Storage::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- framework identity lookup; small dataset, mirrors RecompileCommand.
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			if ( Storage::is_framework_page( (int) $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
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
	 * POST-create a NEW component (collection route): mint components/<name>/ with a
	 * valid starter, rebuild the bundle, and return the new name so the UI can open it
	 * in the builder. Same role gate as editing (settings.components).
	 *
	 * @param \WP_REST_Request $request Request. Body: { name, description? }.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_component_create( \WP_REST_Request $request ) {
		if ( ! Roles::can( 'settings.components' ) ) {
			return new \WP_Error( 's5_role_components', __( 'Your role cannot add components.', 's5' ), array( 'status' => 403 ) );
		}
		$name = trim( (string) $request->get_param( 'name' ) );
		$desc = (string) $request->get_param( 'description' );

		if ( '' === $name ) {
			return new \WP_REST_Response( array( 'ok' => false, 'diagnostics' => array( array( 'message' => __( 'Give the component a name.', 's5' ) ) ) ), 400 );
		}
		if ( ! ComponentRegistry::is_safe_name( $name ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'diagnostics' => array( array( 'message' => __( 'Use letters, numbers, - or _ only (e.g. pricing-card).', 's5' ) ) ) ), 400 );
		}
		if ( ComponentRegistry::is_registered( $name ) ) {
			return new \WP_REST_Response( array( 'ok' => false, 'diagnostics' => array( array( 'message' => __( 'A component with that name already exists.', 's5' ) ) ) ), 409 );
		}

		try {
			ComponentRegistry::create_component( $name, $desc );
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
			201
		);
	}

	/**
	 * POST-save a component's source files, then rebuild the CSS bundle (D22).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_component_save( \WP_REST_Request $request ) {
		// R1: Components is a Theme Settings screen (settings.components); GET stays
		// open to any capable editor (the palette needs to load a component's HTML/
		// manifest to insert or preview it), but writing the source is gated the
		// same way the settings screen itself is.
		if ( ! Roles::can( 'settings.components' ) ) {
			return new \WP_Error( 's5_role_components', __( 'Your role cannot edit components.', 's5' ), array( 'status' => 403 ) );
		}
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
		// AUDIT H4: this endpoint deliberately ignores any `css` payload (CSS is
		// read-only here), then validated the HTML against a vocabulary compiled
		// from the bundle. If the component's own style.css declared a class the
		// bundle had not picked up yet, correct markup was rejected 422 as an
		// "unknown class" with no hint that the CSS door was the one to use. Fold
		// the component's OWN on-disk classes into its vocabulary: they are real,
		// declared, and already sitting in the file being validated against.
		foreach ( array_keys( Stylesheet::parse_classes( $existing_css ) ) as $own_class ) {
			$vocab[ $own_class ] = true;
		}

		// Role-aware, same as the page save door (audit M3).
		$dev      = ( 'developer' === Roles::role_for_user() );
		$findings = Validator::validate( $html, $vocab, 'component:' . $name, $dev );
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
	 * Permission check for every /s5/v1 write route: the WP capability FLOOR
	 * ({@see Editor::CAPABILITY}, edit_posts) plus 'editor.open' — the R1
	 * resolution — so a role the matrix denies is blocked here regardless of
	 * WP capability, before any handler runs. Mode-specific and resource-
	 * specific Roles::can() checks (page settings, embed, structure, components,
	 * templates) layer on inside the individual handlers below. The REST nonce
	 * (X-WP-Nonce) is validated by core before this runs.
	 *
	 * @return bool
	 */
	public static function can_save(): bool {
		return current_user_can( Editor::CAPABILITY ) && Roles::can( 'editor.open' );
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

		// PER-OBJECT authorization (audit C1). `can_save()` proves only that the caller
		// may use the editor AT ALL: it checks the SITE-WIDE `edit_posts` capability
		// plus the role matrix's `editor.open`. Neither says anything about THIS post.
		// Without the check below, any `edit_posts` holder — a Contributor, who cannot
		// edit a published page in wp-admin — could rewrite EVERY framework page on the
		// site through this door. Worse, `Compiler::write_post_content_raw()` suspends
		// kses for the write, so that content would also bypass the `unfiltered_html`
		// restriction their role normally carries. WordPress's `edit_post` meta-cap is
		// the authority on "may this user edit THIS post" (it resolves post status,
		// authorship and post-type caps); defer to it rather than re-deriving the rule.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				's5_cannot_edit_post',
				__( 'You are not allowed to edit this page.', 's5' ),
				array( 'status' => 403 )
			);
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
				// R1 (audit H3): editing text/link/image slots IS the Content grant. The
				// matrix key `editor.content` existed but was never enforced here, so a
				// role the admin had denied Content still saved slots by POSTing directly
				// — hidden in the UI, not blocked at the door. Both, now.
				if ( ! Roles::can( 'editor.content' ) ) {
					return new \WP_Error( 's5_role_content', __( 'Your role cannot edit content.', 's5' ), array( 'status' => 403 ) );
				}
				$canonical = self::build_canonical_from_slots( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			case 'canonical':
				// R1: a raw full-fragment (Code lens) save is structure-level editing —
				// no capability key exists specifically for "Code lens", so this maps
				// onto the nearest existing one, 'editor.advanced', matching the Roles
				// screen's own documented boundary that Client gets Content+Style only.
				if ( ! Roles::can( 'editor.advanced' ) ) {
					return new \WP_Error( 's5_role_advanced', __( 'Your role cannot edit canonical HTML directly.', 's5' ), array( 'status' => 403 ) );
				}
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
				// R1 (audit H3): an axis class-swap IS the Style grant. Same gap as
				// `slots` above — `editor.style` existed in the matrix and was enforced
				// nowhere, so denying Style hid the tab without closing the door.
				if ( ! Roles::can( 'editor.style' ) ) {
					return new \WP_Error( 's5_role_style', __( 'Your role cannot edit styles.', 's5' ), array( 'status' => 403 ) );
				}
				$canonical = self::build_canonical_from_class_swap( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			case 'reorder':
				// R1: reordering is a structure-tree operation, same key as disable.
				if ( ! Roles::can( 'editor.structure' ) ) {
					return new \WP_Error( 's5_role_structure', __( 'Your role cannot reorder elements.', 's5' ), array( 'status' => 403 ) );
				}
				$canonical = self::build_canonical_from_reorder( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			case 'delete':
				// R1: deleting a node is a structure-tree operation, same key as disable/
				// reorder. (Disable soft-hides; delete removes the bytes entirely.)
				if ( ! Roles::can( 'editor.structure' ) ) {
					return new \WP_Error( 's5_role_structure', __( 'Your role cannot delete elements.', 's5' ), array( 'status' => 403 ) );
				}
				$canonical = self::build_canonical_from_delete( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			case 'duplicate':
				// R1: duplicating a node is a structure-tree operation, same key.
				if ( ! Roles::can( 'editor.structure' ) ) {
					return new \WP_Error( 's5_role_structure', __( 'Your role cannot duplicate elements.', 's5' ), array( 'status' => 403 ) );
				}
				$canonical = self::build_canonical_from_duplicate( $post_id, $request );
				if ( $canonical instanceof \WP_Error ) {
					return $canonical;
				}
				return self::persist_canonical( $post_id, $canonical );
			default:
				return new \WP_Error(
					's5_bad_mode',
					__( 'Unknown save mode. Expected slots, canonical, insert, disable, class, reorder, delete, duplicate, or head.', 's5' ),
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

		// SAME gate as import, but ROLE-AWARE: a DEVELOPER authoring in the code lens
		// defines the vocabulary, so an unknown class is a WARNING (proceeds), not an
		// ERROR — the closed-vocabulary wall protects marketers/clients, not the dev who
		// owns the stylesheet. Security rules (inline style, on*, javascript:, <script>)
		// stay ERROR for everyone regardless of $dev. ERROR => reject; canonical UNCHANGED.
		$dev      = ( 'developer' === Roles::role_for_user() );
		$findings = Validator::validate( $canonical, $vocab, 'editor', $dev );
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
	private static function build_canonical_from_slots( int $post_id, \WP_REST_Request $request, ?string $source = null ) {
		$slots = $request->get_param( 'slots' );
		if ( ! is_array( $slots ) || empty( $slots ) ) {
			return new \WP_Error( 's5_no_slots', __( 'No slot edits supplied.', 's5' ), array( 'status' => 400 ) );
		}

		// $source lets a template-part save reuse this builder on the part's canonical
		// (not a post's). null = page mode, fetch the post's stored canonical.
		$canonical = null !== $source ? $source : Storage::get_canonical_html( $post_id );
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
	private static function build_canonical_from_insert( int $post_id, \WP_REST_Request $request, ?string $source = null ) {
		$name = (string) $request->get_param( 'component' );
		$at   = (string) $request->get_param( 'at' );

		$canonical = null !== $source ? $source : Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			return new \WP_Error( 's5_no_canonical', __( 'Page has no canonical source.', 's5' ), array( 'status' => 409 ) );
		}

		return self::expand_component_into( $canonical, $name, $at, true );
	}

	/**
	 * Expand a registered component into a canonical HTML string at an anchor —
	 * the shared core of both the page palette insert (mode:"insert" on
	 * /pages/{id}/save) and the template-part insert (mode:"insert" on
	 * /templates/{name}/save, defect #8's "still can't [insert] in the template
	 * parts" gap). Server-assigned recursive node ids (ComponentInsert::rekey),
	 * refusing a locked anchor, byte-surgical splice — identical rules regardless
	 * of which canonical string (a page's or a part's) is being expanded into.
	 *
	 * @param string $canonical      Current canonical HTML (page body or part file).
	 * @param string $name           Registered component name.
	 * @param string $at             data-node anchor; '' = append.
	 * @param bool   $requires_main  Whether an anchor-less append needs a <main>
	 *                               region to append before (true for pages; a
	 *                               template part is its own small fragment with
	 *                               no <main>, so a part caller passes false and
	 *                               anchor-less means "append at the very end").
	 * @return string|\WP_Error New canonical, or an error.
	 */
	private static function expand_component_into( string $canonical, string $name, string $at, bool $requires_main ) {
		if ( '' === $name || ! ComponentRegistry::is_registered( $name ) ) {
			return new \WP_Error( 's5_unknown_component', __( 'Unknown or unregistered component.', 's5' ), array( 'status' => 400 ) );
		}

		try {
			$component = ComponentRegistry::load( $name );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 's5_component_load', $e->getMessage(), array( 'status' => 400 ) );
		}

		// Structural law (Amendment A): refuse insert on/inside a data-lock subtree.
		if ( ComponentInsert::anchor_is_locked( $canonical, $at ) ) {
			return new \WP_Error( 's5_locked', __( 'Cannot insert into a locked (data-lock) region.', 's5' ), array( 'status' => 409 ) );
		}

		// Server-assigned recursive node ids, then byte-surgical splice.
		$existing = ComponentInsert::existing_ids( $canonical );
		$rekeyed  = ComponentInsert::rekey( $component['html'], $component['name'], $existing );

		if ( '' === $at && ! $requires_main ) {
			// A template part is its own small fragment (no <main>) — anchor-less
			// simply means "append at the end of the part's markup".
			return rtrim( $canonical ) . "\n" . $rekeyed['markup'] . "\n";
		}

		$spliced = ComponentInsert::splice( $canonical, $rekeyed['markup'], $at );
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
	private static function build_canonical_from_disable( int $post_id, \WP_REST_Request $request, ?string $source = null ) {
		$node     = (string) $request->get_param( 'node' );
		$disabled = (bool) $request->get_param( 'disabled' );

		if ( '' === $node ) {
			return new \WP_Error( 's5_no_node', __( 'No node supplied to disable.', 's5' ), array( 'status' => 400 ) );
		}

		$canonical = null !== $source ? $source : Storage::get_canonical_html( $post_id );
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
	 * Move a node to become the sibling immediately after (or before) an anchor
	 * node, both keyed by data-node (Item 5 / defect #8: Structure-panel
	 * drag-and-drop). Byte-surgical, same philosophy as every other mode here:
	 * the moved subtree's exact bytes are cut and re-inserted elsewhere;
	 * everything else in canonical stays byte-identical.
	 *
	 * Scoped narrow on purpose: the moved node and the anchor must currently be
	 * SIBLINGS (same parent). Cross-parent moves (dragging into a different
	 * section/container) are refused loudly rather than guessed at — that needs
	 * real re-parenting logic and is out of scope for this pass.
	 *
	 * Expects params: `node` (data-node id being moved), and exactly one of
	 * `after` or `before` (data-node id of the sibling to land next to).
	 *
	 * @param int              $post_id Page ID.
	 * @param \WP_REST_Request $request Request.
	 * @return string|\WP_Error New canonical, or an error.
	 */
	private static function build_canonical_from_reorder( int $post_id, \WP_REST_Request $request, ?string $source = null ) {
		$node   = (string) $request->get_param( 'node' );
		$after  = (string) $request->get_param( 'after' );
		$before = (string) $request->get_param( 'before' );

		if ( '' === $node || ( '' === $after && '' === $before ) ) {
			return new \WP_Error( 's5_reorder_args', __( 'node and either after or before are required.', 's5' ), array( 'status' => 400 ) );
		}

		$anchor  = '' !== $after ? $after : $before;
		$current = null !== $source ? $source : Storage::get_canonical_html( $post_id );

		// Moving a node next to itself is a no-op — not an error. A drag that
		// dropped back on its own position should not surface a scary diagnostic.
		if ( $node === $anchor ) {
			return $current;
		}

		$canonical = $current;
		if ( '' === $canonical ) {
			return new \WP_Error( 's5_no_canonical', __( 'Page has no canonical source.', 's5' ), array( 'status' => 409 ) );
		}

		// Structural law: refuse a move where either end is inside a locked subtree
		// (same law ComponentInsert enforces for insert).
		if ( ComponentInsert::anchor_is_locked( $canonical, $node ) || ComponentInsert::anchor_is_locked( $canonical, $anchor ) ) {
			return new \WP_Error( 's5_locked', __( 'Cannot reorder a locked (data-lock) node.', 's5' ), array( 'status' => 409 ) );
		}

		$moved_span = self::find_node_span( $canonical, $node );
		if ( null === $moved_span ) {
			return new \WP_Error(
				's5_node_not_found',
				sprintf( /* translators: %s: data-node id. */ __( 'Node "%s" not found in canonical.', 's5' ), $node ),
				array( 'status' => 409 )
			);
		}
		$anchor_span = self::find_node_span( $canonical, $anchor );
		if ( null === $anchor_span ) {
			return new \WP_Error(
				's5_node_not_found',
				sprintf( /* translators: %s: data-node id. */ __( 'Node "%s" not found in canonical.', 's5' ), $anchor ),
				array( 'status' => 409 )
			);
		}

		if ( self::spans_overlap( $moved_span, $anchor_span ) ) {
			return new \WP_Error( 's5_reorder_nested', __( 'Cannot reorder a node relative to its own ancestor or descendant.', 's5' ), array( 'status' => 409 ) );
		}
		if ( ! self::same_parent( $canonical, $moved_span, $anchor_span ) ) {
			return new \WP_Error( 's5_reorder_siblings_only', __( 'Can only reorder within the same parent — cross-container moves aren\'t supported yet.', 's5' ), array( 'status' => 409 ) );
		}

		// find_node_span() returns only the element's own open-to-close bytes — the
		// whitespace/indentation BEFORE it (the gap since the previous sibling's
		// close tag) is not part of that span. Cutting just the bare span leaves
		// that leading gap orphaned behind, and re-inserting the bare element
		// jams it directly against the anchor's tag boundary with no separator
		// (verified live: a reorder produced "...ships.</p><h1 ..." with zero
		// bytes between). Extend the cut to include the moved node's leading
		// whitespace, so the same visual gap it had travels with it.
		$moved_cut_start = self::leading_whitespace_start( $canonical, $moved_span[0] );
		$moved_html       = substr( $canonical, $moved_cut_start, $moved_span[1] - $moved_cut_start );
		$without          = substr( $canonical, 0, $moved_cut_start ) . substr( $canonical, $moved_span[1] );
		$cut_len          = $moved_span[1] - $moved_cut_start;

		$anchor_start = $anchor_span[0];
		$anchor_end   = $anchor_span[1];
		if ( $moved_span[1] <= $anchor_span[0] ) {
			$anchor_start -= $cut_len;
			$anchor_end   -= $cut_len;
		}

		if ( '' !== $after ) {
			// Insert AFTER the anchor: since $moved_html now carries its own
			// leading gap, put that gap where the anchor's own trailing gap
			// already is (right after the anchor's close tag) so the result reads
			// anchor, gap, moved — matching normal sibling spacing.
			return substr( $without, 0, $anchor_end ) . $moved_html . substr( $without, $anchor_end );
		}

		// Insert BEFORE the anchor: the anchor's own leading gap must move to
		// follow the newly-inserted node, else two gaps collide with none
		// between the moved node and the anchor. Take the anchor's leading gap
		// with it conceptually by inserting moved_html at the anchor's own
		// leading-whitespace start, ahead of the anchor's existing gap.
		$anchor_insert_at = self::leading_whitespace_start( $without, $anchor_start );
		return substr( $without, 0, $anchor_insert_at ) . $moved_html . substr( $without, $anchor_insert_at );
	}

	/**
	 * DELETE the node carrying data-node="$node" from canonical: cut its exact bytes
	 * (plus its leading inter-tag whitespace, so no orphaned gap is left behind).
	 * Byte-surgical, same philosophy as reorder. Refuses a locked (data-lock) node.
	 *
	 * Unlike `disable` (which soft-hides by setting data-disabled, keeping the node so
	 * it can be re-enabled), delete removes the element and its subtree entirely.
	 *
	 * @param int              $post_id Page ID.
	 * @param \WP_REST_Request $request Request. Param: `node`.
	 * @param string|null      $source  Optional source canonical (template-part path).
	 * @return string|\WP_Error New canonical, or an error.
	 */
	private static function build_canonical_from_delete( int $post_id, \WP_REST_Request $request, ?string $source = null ) {
		$node = (string) $request->get_param( 'node' );
		if ( '' === $node ) {
			return new \WP_Error( 's5_no_node', __( 'No node supplied to delete.', 's5' ), array( 'status' => 400 ) );
		}

		$canonical = null !== $source ? $source : Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			return new \WP_Error( 's5_no_canonical', __( 'Page has no canonical source.', 's5' ), array( 'status' => 409 ) );
		}

		// A locked (data-lock) node — e.g. a component's internal structure — is not
		// the operator's to remove. Same law reorder/insert enforce.
		if ( ComponentInsert::anchor_is_locked( $canonical, $node ) ) {
			return new \WP_Error( 's5_locked', __( 'Cannot delete a locked (data-lock) node.', 's5' ), array( 'status' => 409 ) );
		}

		$span = self::find_node_span( $canonical, $node );
		if ( null === $span ) {
			return new \WP_Error(
				's5_node_not_found',
				sprintf( /* translators: %s: data-node id. */ __( 'Node "%s" not found in canonical.', 's5' ), $node ),
				array( 'status' => 409 )
			);
		}

		// Cut the node's bytes plus the leading whitespace gap before it, so the
		// surrounding content closes up cleanly (no double blank line, no orphan gap).
		$cut_start = self::leading_whitespace_start( $canonical, $span[0] );
		return substr( $canonical, 0, $cut_start ) . substr( $canonical, $span[1] );
	}

	/**
	 * DUPLICATE the node carrying data-node="$node": clone its exact bytes, re-key
	 * every data-node in the clone to fresh page-unique ids ({@see ComponentInsert::rekey},
	 * so the copy is independently addressable), and insert the copy immediately after
	 * the original as its next sibling (carrying the original's leading-whitespace gap
	 * as the separator). Byte-surgical; refuses a locked node.
	 *
	 * @param int              $post_id Page ID.
	 * @param \WP_REST_Request $request Request. Param: `node`.
	 * @param string|null      $source  Optional source canonical (template-part path).
	 * @return string|\WP_Error New canonical, or an error.
	 */
	private static function build_canonical_from_duplicate( int $post_id, \WP_REST_Request $request, ?string $source = null ) {
		$node = (string) $request->get_param( 'node' );
		if ( '' === $node ) {
			return new \WP_Error( 's5_no_node', __( 'No node supplied to duplicate.', 's5' ), array( 'status' => 400 ) );
		}

		$canonical = null !== $source ? $source : Storage::get_canonical_html( $post_id );
		if ( '' === $canonical ) {
			return new \WP_Error( 's5_no_canonical', __( 'Page has no canonical source.', 's5' ), array( 'status' => 409 ) );
		}

		if ( ComponentInsert::anchor_is_locked( $canonical, $node ) ) {
			return new \WP_Error( 's5_locked', __( 'Cannot duplicate a locked (data-lock) node.', 's5' ), array( 'status' => 409 ) );
		}

		$span = self::find_node_span( $canonical, $node );
		if ( null === $span ) {
			return new \WP_Error(
				's5_node_not_found',
				sprintf( /* translators: %s: data-node id. */ __( 'Node "%s" not found in canonical.', 's5' ), $node ),
				array( 'status' => 409 )
			);
		}

		// The original's own bytes (open→close), and the whitespace gap before it (which
		// becomes the separator inserted between the original and its copy).
		$element_html = substr( $canonical, $span[0], $span[1] - $span[0] );
		$gap_start    = self::leading_whitespace_start( $canonical, $span[0] );
		$gap          = substr( $canonical, $gap_start, $span[0] - $gap_start );

		// Re-key every data-node in the clone to fresh page-unique ids so the copy is a
		// distinct, independently editable element (no id collisions with the original).
		$existing = ComponentInsert::existing_ids( $canonical );
		$rekeyed  = ComponentInsert::rekey( $element_html, 'copy', $existing );

		// Insert the copy right after the original's close tag, preceded by the same gap
		// the original had — so the pair reads: original, gap, copy.
		$insert_at = $span[1];
		return substr( $canonical, 0, $insert_at ) . $gap . $rekeyed['markup'] . substr( $canonical, $insert_at );
	}

	/**
	 * Walk backward from a byte offset over pure inter-tag whitespace only
	 * (spaces, tabs, newlines) to find where that whitespace run began — the
	 * true start of "this node plus the gap before it". Stops the instant a
	 * non-whitespace byte is hit (i.e. stops at the previous sibling's `>` or
	 * the parent's `>`), so it never eats into real content.
	 *
	 * @param string $html HTML string.
	 * @param int    $pos  Offset to walk backward from.
	 * @return int Offset of the first whitespace byte in the run ending at $pos
	 *             (== $pos itself if the preceding byte is not whitespace).
	 */
	private static function leading_whitespace_start( string $html, int $pos ): int {
		while ( $pos > 0 && false !== strpos( " \t\n\r", $html[ $pos - 1 ] ) ) {
			--$pos;
		}
		return $pos;
	}

	/**
	 * Locate the byte span [start, end) of the element carrying data-node="$node"
	 * — its full outer HTML, open tag through matching close tag. Shared helper
	 * for reorder; mirrors the open/close matching already used by replace_inner()
	 * and ComponentInsert::splice(), factored out since reorder needs the span's
	 * bounds directly rather than a splice result.
	 *
	 * @param string $canonical Canonical HTML.
	 * @param string $node      data-node id to locate.
	 * @return array{0:int,1:int}|null [start, end) byte offsets, or null if not found
	 *                                 or unbalanced.
	 */
	private static function find_node_span( string $canonical, string $node ) {
		$pattern = '/<([a-zA-Z][a-zA-Z0-9]*)\b[^>]*\bdata-node=(["\'])' . preg_quote( $node, '/' ) . '\2[^>]*>/';
		if ( ! preg_match( $pattern, $canonical, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$tag        = $m[1][0];
		$open_start = (int) $m[0][1];
		$open_end   = $open_start + strlen( $m[0][0] );

		if ( '/>' === substr( $m[0][0], -2 ) ) {
			return array( $open_start, $open_end );
		}

		$depth  = 1;
		$offset = $open_end;
		$len    = strlen( $canonical );
		$re     = '/<(\/?)' . preg_quote( $tag, '/' ) . '\b[^>]*>/i';
		while ( $offset < $len && preg_match( $re, $canonical, $tm, PREG_OFFSET_CAPTURE, $offset ) ) {
			$is_close = '' !== $tm[1][0];
			$at       = (int) $tm[0][1];
			$tok_end  = $at + strlen( $tm[0][0] );
			if ( $is_close ) {
				--$depth;
				if ( 0 === $depth ) {
					return array( $open_start, $tok_end );
				}
			} else {
				++$depth;
			}
			$offset = $tok_end;
		}
		return null;
	}

	/**
	 * Whether two byte spans overlap (one contains the other, or they intersect) —
	 * used to refuse reordering a node relative to its own ancestor/descendant.
	 *
	 * @param array{0:int,1:int} $a First span.
	 * @param array{0:int,1:int} $b Second span.
	 * @return bool
	 */
	private static function spans_overlap( array $a, array $b ): bool {
		return $a[0] < $b[1] && $b[0] < $a[1];
	}

	/**
	 * Whether the elements at two spans share the same parent element, by walking
	 * outward from each span's start to the nearest enclosing open tag and
	 * comparing those enclosing tags' own start offsets. A pragmatic same-depth-
	 * ancestor check consistent with the byte-surgical approach used throughout
	 * this file (no DOM round-trip).
	 *
	 * @param string              $canonical Canonical HTML.
	 * @param array{0:int,1:int}  $a First span.
	 * @param array{0:int,1:int}  $b Second span.
	 * @return bool
	 */
	private static function same_parent( string $canonical, array $a, array $b ): bool {
		$parent_a = self::enclosing_open_tag_start( $canonical, $a[0] );
		$parent_b = self::enclosing_open_tag_start( $canonical, $b[0] );
		return null !== $parent_a && $parent_a === $parent_b;
	}

	/**
	 * A small set of void/self-closing HTML tags that never carry a matching
	 * close tag, so they must never be pushed onto the tag stack {@see
	 * enclosing_open_tag_start()} walks — an unclosed <img> or <br> would
	 * otherwise be mistaken for the parent of everything that follows it.
	 *
	 * @var array<string, true>
	 */
	private const VOID_TAGS = array(
		'area'   => true,
		'base'   => true,
		'br'     => true,
		'col'    => true,
		'embed'  => true,
		'hr'     => true,
		'img'    => true,
		'input'  => true,
		'link'   => true,
		'meta'   => true,
		'source' => true,
		'track'  => true,
		'wbr'    => true,
	);

	/**
	 * The start offset of the nearest open tag that encloses (but is not) the
	 * element starting at $pos — i.e. the immediate parent's open-tag start.
	 *
	 * A single FORWARD pass over canonical up to $pos, maintaining a stack of
	 * currently-open tags' start offsets (void/self-closing tags never pushed).
	 * The stack top at $pos is the parent. O(n) and immune to the fragility of
	 * a backward, re-sliced regex scan.
	 *
	 * @param string $canonical Canonical HTML.
	 * @param int    $pos       Start offset of the child element's open tag.
	 * @return int|null Parent open-tag start offset, or null if none (top level).
	 */
	private static function enclosing_open_tag_start( string $canonical, int $pos ) {
		$stack  = array();
		$offset = 0;
		$re     = '/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(\/?)>/';
		while ( $offset < $pos && preg_match( $re, $canonical, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$start = (int) $m[0][1];
			if ( $start >= $pos ) {
				break;
			}
			$is_close = '' !== $m[1][0];
			$tag      = strtolower( $m[2][0] );
			$is_self  = '' !== $m[3][0] || isset( self::VOID_TAGS[ $tag ] );

			if ( $is_close ) {
				// Pop until we find the matching open (defensive against malformed
				// nesting — best-effort, mirrors the rest of this file's approach).
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
					if ( $stack[ $i ]['tag'] === $tag ) {
						array_splice( $stack, $i );
						break;
					}
				}
			} elseif ( ! $is_self ) {
				$stack[] = array(
					'tag'   => $tag,
					'start' => $start,
				);
			}
			$offset = $start + strlen( $m[0][0] );
		}
		if ( empty( $stack ) ) {
			return null;
		}
		return $stack[ count( $stack ) - 1 ]['start'];
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
	private static function build_canonical_from_class_swap( int $post_id, \WP_REST_Request $request, ?string $source = null ) {
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

		$canonical = null !== $source ? $source : Storage::get_canonical_html( $post_id );
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
	/**
	 * Make square brackets inert so authored text cannot become a shortcode.
	 *
	 * AUDIT M1. `esc_html()` escapes `< > & " '` but NOT `[` or `]`, so a plain
	 * TEXT slot could carry `[gallery]`. `Compiler::needs_dynamic_pass()` matches
	 * that very pattern, flips the page to dynamic, and `templates/canvas.php`
	 * then runs `do_shortcode()` on the fragment for EVERY visitor — turning a
	 * text field into a server-side template-execution surface reachable by the
	 * lowest-privilege editing role. The Validator never saw it, because bracket
	 * text is not markup.
	 *
	 * Encoding the brackets keeps the glyphs visible and byte-stable through the
	 * round-trip harness while making them non-matching for the shortcode regex.
	 * Sanctioned dynamics (`<s5-field>`, L&L) are ELEMENTS and are unaffected.
	 *
	 * @param string $value Already-escaped slot content.
	 * @return string Same text with brackets encoded.
	 */
	private static function neutralize_shortcodes( string $value ): string {
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $value );
	}

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
				return self::replace_inner( $canonical, $node, self::neutralize_shortcodes( esc_html( $value ) ) );

			case 'rich':
				$safe = wp_kses( $value, self::RICH_ALLOWED );
				return self::replace_inner( $canonical, $node, self::neutralize_shortcodes( $safe ) );

			case 'link':
				// attr="target" is the Open-in control (audit M4); anything else is
				// the href itself. Kept as two exclusive branches so a target edit
				// never rewrites the href, and vice versa.
				if ( 'target' === $attr ) {
					if ( '_blank' === $value ) {
						$processor->set_attribute( 'target', '_blank' );
						// Never hand an opener to a cross-origin tab.
						$processor->set_attribute( 'rel', 'noopener noreferrer' );
					} else {
						$processor->remove_attribute( 'target' );
						$processor->remove_attribute( 'rel' );
					}
				} else {
					$processor->set_attribute( 'href', esc_url_raw( $value ) );
				}
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
