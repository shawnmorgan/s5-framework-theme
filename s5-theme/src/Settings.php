<?php
/**
 * S5 Framework — Theme Settings app (screen host).
 *
 * The single "Theme Settings" entry under the S5 menu. This is the app the two
 * mockups call `theme-settings-mockup.html`: one WP-admin screen with a left
 * sub-nav switching between seven screens (StyleGuide · Templates · Components ·
 * Integrations · General · CSS/Tailwind · Roles), each gated by role (HANDOFF §3, §4).
 *
 * Phase A ships the SHELL: the menu entry (registered by {@see Admin}) resolves
 * here, the screen renders with the sub-nav and a per-screen placeholder, and the
 * `?screen=` router is in place. Phase C fills each screen against the mockup and
 * wires persistence. Keeping the shell here (not in the canvas) satisfies the Phase A
 * menu contract: Style/Components/etc. route INTO this app, never into the editor.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Theme Settings app: sub-nav + per-screen router (Phase C fills the screens).
 */
final class Settings {

	/**
	 * WP capability FLOOR to reach the settings app at all — the widest net any S5
	 * role needs (`edit_posts`, held by Administrator/Editor/Author).
	 *
	 * Per the roles contract (HANDOFF §4) a Client never sees the settings page;
	 * Marketer sees a reduced set of screens; Developer sees everything. This
	 * constant is NOT the real gate — {@see Roles::can()} ('settings.view' at the
	 * app level, then a per-screen capability from {@see self::screen_capability()})
	 * is what actually decides visibility and access, so a WP-Administrator pinned
	 * to a lower S5 role is still restricted (R1: hidden AND blocked).
	 */
	public const CAPABILITY = 'edit_posts';

	/**
	 * Settings app page slug (admin.php?page=s5-settings).
	 */
	public const MENU_SLUG = 's5-settings';

	/**
	 * The seven screens, in the HANDOFF §3 order. Value = human label.
	 *
	 * @var array<string, string>
	 */
	private const SCREENS = array(
		'general'      => 'General',
		'templates'    => 'Templates',
		'styleguide'   => 'StyleGuide',
		'components'   => 'Components',
		'css'          => 'CSS / Tailwind',
		'integrations' => 'Integrations',
		'roles'        => 'Roles',
	);

	/**
	 * Sub-nav metadata per screen (mockup order): FontAwesome glyph + one-line sub.
	 * TRANSCRIBED from theme-settings-mockup.html subnav (lines 618-648).
	 *
	 * @var array<string, array{icon:string, sub:string}>
	 */
	private const SUBNAV = array(
		'general'      => array( 'icon' => 'fa-sliders', 'sub' => 'Identity · homepage' ),
		'templates'    => array( 'icon' => 'fa-layer-group', 'sub' => 'Parts · WP hierarchy' ),
		'styleguide'   => array( 'icon' => 'fa-swatchbook', 'sub' => 'Tokens · live specimen' ),
		'components'   => array( 'icon' => 'fa-cubes', 'sub' => 'Registered library' ),
		'css'          => array( 'icon' => 'fa-code', 'sub' => 'Tokens · classes · custom' ),
		'integrations' => array( 'icon' => 'fa-plug', 'sub' => 'Snippets · analytics · pixels' ),
		'roles'        => array( 'icon' => 'fa-user-shield', 'sub' => 'Who sees & edits what' ),
	);

	/**
	 * Admin-post action: save one edited design token + recompile the bundle.
	 */
	public const ACTION_SAVE_TOKEN = 's5_save_token';

	/**
	 * Admin-post action: save the Roles matrix + per-user overrides.
	 */
	public const ACTION_SAVE_ROLES = 's5_save_roles';

	/**
	 * Admin-post action: save integrations.
	 */
	public const ACTION_SAVE_INTEGRATIONS = 's5_save_integrations';

	/**
	 * Admin-post action: save redirects.
	 */
	public const ACTION_SAVE_REDIRECTS = 's5_save_redirects';

	/**
	 * Admin-post action: save one CSS layer file + recompile the bundle.
	 */
	public const ACTION_SAVE_CSS = 's5_save_css';

	/**
	 * Admin-post action: save one edited element STYLE rule (Styles tab, Element
	 * styles part) + recompile the bundle.
	 */
	public const ACTION_SAVE_ELEMENT = 's5_save_element';

	/**
	 * The editable CSS files, keyed by the short name the form posts. Each maps to a
	 * real file under site/styles/. This is the ALLOW-LIST — a save is refused for any
	 * name not in here, so the surface can never write outside the design-system dir.
	 *
	 * @var array<string, string>
	 */
	private const CSS_FILES = array(
		'tokens'     => 'tokens.css',
		'scales'     => 'scales.css',
		'components' => 'components.css',
		'utilities'  => 'utilities.css',
		'custom'     => 'custom.css',
	);

	/**
	 * Register hooks. The menu entry itself is added by {@see Admin::register_menu}
	 * (so the S5 menu stays the single owner of its children); this class owns the
	 * render + asset enqueue + token-save handler for the settings screen only.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_TOKEN, array( self::class, 'handle_save_token' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_ROLES, array( self::class, 'handle_save_roles' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_INTEGRATIONS, array( self::class, 'handle_save_integrations' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_REDIRECTS, array( self::class, 'handle_save_redirects' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_CSS, array( self::class, 'handle_save_css' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_ELEMENT, array( self::class, 'handle_save_element' ) );
	}

	/**
	 * Save integrations (Developer per the matrix).
	 *
	 * @return void
	 */
	public static function handle_save_integrations(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Roles::can( 'settings.integrations' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit integrations.', 's5' ) );
		}
		check_admin_referer( self::ACTION_SAVE_INTEGRATIONS );

		$rows = isset( $_POST['int'] ) && is_array( $_POST['int'] ) ? wp_unslash( $_POST['int'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shaped in Integrations::save; code is verbatim admin-authored snippet by design.
		$list = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = trim( (string) ( $row['name'] ?? '' ) );
			$code = trim( (string) ( $row['code'] ?? '' ) );
			// A totally empty card (no name AND no snippet) is a no-op row someone opened
			// and didn't fill in — drop it. But a card with a pasted snippet and no name
			// carries real user data (the "New integration" title is a placeholder, not a
			// static label — this mirrors the mockup's own transparent-until-hover input,
			// which reads as plain text until you focus it): silently discarding the row
			// on save would lose the snippet behind a false "Integrations saved" success
			// notice. Loud-door rule: never drop authored content silently.
			if ( '' === $name && '' === $code ) {
				continue;
			}
			$list[] = array(
				'id'         => (string) ( $row['id'] ?? '' ),
				'name'       => '' !== $name ? $name : __( 'Untitled integration', 's5' ),
				'point'      => (string) ( $row['point'] ?? 'head' ),
				'enabled'    => ! empty( $row['enabled'] ),
				'code'       => $code,
				'conditions' => self::shape_conditions( isset( $row['conditions'] ) && is_array( $row['conditions'] ) ? $row['conditions'] : array() ),
			);
		}
		Integrations::save( $list );
		self::redirect_screen( 'integrations', 'success', __( 'Integrations saved.', 's5' ) );
	}

	/**
	 * Shape a posted condition block ({mode, rules[]}) into the stored model that
	 * Integrations::conditions_pass evaluates: {} for "Entire site" (runs everywhere),
	 * { include:[…] } for "Only these pages", { exclude:[…] } for "Everywhere except".
	 * Rules are clamped to the closed vocabulary rule_matches actually supports, so a
	 * saved condition can never reference a type/op/value the injector won't honor.
	 *
	 * @param array<string,mixed> $cond Raw posted conditions.
	 * @return array<string,mixed>
	 */
	private static function shape_conditions( array $cond ): array {
		$mode = isset( $cond['mode'] ) ? (string) $cond['mode'] : 'all';
		if ( 'include' !== $mode && 'exclude' !== $mode ) {
			return array(); // "Entire site" — no rules, runs everywhere.
		}
		$raw   = isset( $cond['rules'] ) && is_array( $cond['rules'] ) ? $cond['rules'] : array();
		$rules = array();
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$type = isset( $r['type'] ) ? (string) $r['type'] : '';
			$op   = ( isset( $r['op'] ) && 'is not' === $r['op'] ) ? 'is not' : 'is';
			$val  = isset( $r['value'] ) ? (string) $r['value'] : '';
			if ( 'front_page' === $type ) {
				$rules[] = array( 'type' => 'front_page', 'op' => $op, 'value' => '' );
			} elseif ( 'post_type' === $type && in_array( $val, array( 'page', 'post' ), true ) ) {
				$rules[] = array( 'type' => 'post_type', 'op' => $op, 'value' => $val );
			} elseif ( 'post' === $type && (int) $val > 0 ) {
				// A specific page/post by ID (value is the post ID as a string).
				$rules[] = array( 'type' => 'post', 'op' => $op, 'value' => (string) (int) $val );
			}
		}
		if ( empty( $rules ) ) {
			return array(); // mode chosen but no valid rules → treat as everywhere.
		}
		return array( $mode => $rules );
	}

	/**
	 * Save redirects (Developer/Marketer per the matrix — General screen).
	 *
	 * @return void
	 */
	public static function handle_save_redirects(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Roles::can( 'settings.general' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit redirects.', 's5' ) );
		}
		check_admin_referer( self::ACTION_SAVE_REDIRECTS );

		$rows = isset( $_POST['rdr'] ) && is_array( $_POST['rdr'] ) ? wp_unslash( $_POST['rdr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shaped + escaped in Redirects::save.
		$list = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$list[] = array(
				'from' => (string) ( $row['from'] ?? '' ),
				'to'   => (string) ( $row['to'] ?? '' ),
				'code' => (int) ( $row['code'] ?? 301 ),
			);
		}
		Redirects::save( $list );
		self::redirect_screen( 'general', 'success', __( 'Redirects saved.', 's5' ) );
	}

	/**
	 * Handle the Roles matrix + overrides save (Developer only, per the matrix).
	 *
	 * @return void
	 */
	public static function handle_save_roles(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Roles::can( 'settings.roles' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit roles.', 's5' ) );
		}
		check_admin_referer( self::ACTION_SAVE_ROLES );

		// Matrix: matrix[cap][roleIndex] present = granted.
		$posted = isset( $_POST['matrix'] ) && is_array( $_POST['matrix'] ) ? wp_unslash( $_POST['matrix'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- shaped + cast in Roles::save_matrix.
		$matrix = array();
		foreach ( Roles::capabilities() as $key => $def ) {
			$row            = isset( $posted[ $key ] ) && is_array( $posted[ $key ] ) ? $posted[ $key ] : array();
			$matrix[ $key ] = array(
				true, // developer always all.
				! empty( $row[1] ),
				! empty( $row[2] ),
			);
		}
		Roles::save_matrix( $matrix );

		// Per-user overrides: overrides[user_id] = role_key ('' clears).
		if ( isset( $_POST['override'] ) && is_array( $_POST['override'] ) ) {
			$overrides = wp_unslash( $_POST['override'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys intval'd, values validated in save_override.
			foreach ( $overrides as $uid => $role ) {
				Roles::save_override( (int) $uid, sanitize_key( (string) $role ) );
			}
		}

		self::redirect_screen( 'roles', 'success', __( 'Roles updated.', 's5' ) );
	}

	/**
	 * Redirect to a settings screen with a one-shot notice.
	 *
	 * @param string $screen  Screen key.
	 * @param string $type    'success'|'error'|'warning'.
	 * @param string $message Message.
	 * @return void
	 */
	private static function redirect_screen( string $screen, string $type, string $message ): void {
		$url = add_query_arg(
			array(
				's5_notice'      => rawurlencode( $type ),
				's5_notice_text' => rawurlencode( $message ),
			),
			self::screen_url( $screen )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handle a token edit: update the value in the token file, then recompile the
	 * design-system bundle so the specimen + every framework page reflect it. One door
	 * for the write (Styles::update_token) + one compile (StyleBundle::build).
	 *
	 * @return void
	 */
	public static function handle_save_token(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Roles::can( 'settings.styleguide_edit' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit tokens.', 's5' ) );
		}
		check_admin_referer( self::ACTION_SAVE_TOKEN );

		$name  = isset( $_POST['token_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token_name'] ) ) : '';
		$value = isset( $_POST['token_value'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token_value'] ) ) : '';

		$res = Styles::update_token( $name, $value );
		if ( is_wp_error( $res ) ) {
			// A no-op (value identical to what's stored) is not a failure — don't
			// scare the user with a red error toast. Treat it as an informational
			// success and return without recompiling.
			if ( 's5_token_noop' === $res->get_error_code() ) {
				self::redirect_styleguide( 'success', __( 'No change — that token already has this value.', 's5' ) );
			}
			self::redirect_styleguide( 'error', $res->get_error_message() );
		}

		try {
			StyleBundle::build();
			self::redirect_styleguide( 'success', __( 'Token updated and design system recompiled.', 's5' ) );
		} catch ( \Throwable $e ) {
			self::redirect_styleguide( 'warning', __( 'Token saved, but the recompile failed: ', 's5' ) . $e->getMessage() );
		}
	}

	/**
	 * Save one CSS layer file (Tokens / Scales / Classes / Utilities / Custom).
	 *
	 * Every layer is directly editable — the CSS screen is the design-system source and
	 * the developer owns it. The write is allow-listed to the known files under
	 * site/styles/ (never an arbitrary path), then the bundle is recompiled so the edit
	 * takes effect. Capability + Roles gated (settings.css), nonce-checked.
	 *
	 * @return void
	 */
	public static function handle_save_css(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Roles::can( 'settings.css' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit CSS.', 's5' ) );
		}
		check_admin_referer( self::ACTION_SAVE_CSS );

		$which = isset( $_POST['css_file'] ) ? sanitize_key( wp_unslash( (string) $_POST['css_file'] ) ) : '';

		// Raw CSS by design (this IS the stylesheet source). Only unslash — no HTML
		// sanitizer, which would corrupt selectors like a[href] or content:"\201C".
		$code = isset( $_POST['css_code'] ) ? (string) wp_unslash( $_POST['css_code'] ) : ''; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- verbatim stylesheet source, developer-authored, allow-listed target file.

		$res = self::write_css_layer( $which, $code );
		if ( is_wp_error( $res ) ) {
			self::redirect_css( 'error', $res->get_error_message() );
		}

		try {
			StyleBundle::build();
			self::redirect_css( 'success', __( 'CSS saved and design system recompiled.', 's5' ) );
		} catch ( \Throwable $e ) {
			self::redirect_css( 'warning', __( 'CSS saved, but the recompile failed: ', 's5' ) . $e->getMessage() );
		}
	}

	/**
	 * Handle an element-style edit (Styles tab → Element styles part): rewrite the
	 * one registered element's rule in components.css, then recompile the bundle so
	 * the change reaches the served site and the live specimen.
	 *
	 * The write goes through {@see ElementStyles::update_element} (surgical, validated,
	 * allow-listed to the components layer), then the one recompile door
	 * ({@see StyleBundle::build}) — identical discipline to token + CSS-layer saves.
	 * Capability + Roles gated (settings.css — element styling is Developer-tier design
	 * work, the same gate as raw CSS), nonce-checked.
	 *
	 * @return void
	 */
	public static function handle_save_element(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! Roles::can( 'settings.css' ) ) {
			wp_die( esc_html__( 'You do not have permission to edit element styles.', 's5' ) );
		}
		check_admin_referer( self::ACTION_SAVE_ELEMENT );

		$key = isset( $_POST['element'] ) ? sanitize_key( wp_unslash( (string) $_POST['element'] ) ) : '';

		// Raw declarations by design (this IS stylesheet source). Only unslash — an HTML
		// sanitizer would corrupt values like content:"\201C" or url(a[href]). Injection
		// past the rule is blocked inside ElementStyles::update_element (no braces, no '<').
		$css = isset( $_POST['declarations'] ) ? (string) wp_unslash( $_POST['declarations'] ) : ''; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- verbatim CSS declarations, developer-authored, validated + allow-listed in ElementStyles.

		$res = ElementStyles::update_element( $key, $css );
		if ( is_wp_error( $res ) ) {
			self::redirect_screen( 'styleguide', 'error', $res->get_error_message() );
		}

		try {
			StyleBundle::build();
			self::redirect_screen( 'styleguide', 'success', __( 'Element style saved and design system recompiled.', 's5' ) );
		} catch ( \Throwable $e ) {
			self::redirect_screen( 'styleguide', 'warning', __( 'Element style saved, but the recompile failed: ', 's5' ) . $e->getMessage() );
		}
	}

	/**
	 * Write one allow-listed CSS layer file verbatim. Shared by the admin-post UI door
	 * and the headless REST door (B1) so both write identically. Does NOT recompile —
	 * the caller rebuilds the bundle. Returns true or a WP_Error.
	 *
	 * @param string $which Short file key (see CSS_FILES).
	 * @param string $code  Raw CSS source.
	 * @return true|\WP_Error
	 */
	public static function write_css_layer( string $which, string $code ) {
		if ( ! isset( self::CSS_FILES[ $which ] ) ) {
			return new \WP_Error( 's5_css_unknown', __( 'Unknown CSS file — nothing was written.', 's5' ), array( 'status' => 400 ) );
		}
		$code = str_replace( "\r\n", "\n", $code );
		$dir  = trailingslashit( get_stylesheet_directory() ) . 'site/styles/';
		$path = $dir . self::CSS_FILES[ $which ];

		if ( file_exists( $path ) && ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- theme-local declared stylesheet, not an upload.
			return new \WP_Error( 's5_css_readonly', __( 'That stylesheet is not writable on disk.', 's5' ), array( 'status' => 500 ) );
		}
		$written = file_put_contents( $path, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a local declared stylesheet (allow-listed).
		if ( false === $written ) {
			return new \WP_Error( 's5_css_write', __( 'Could not write the stylesheet.', 's5' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Redirect back to the CSS screen with a one-shot notice.
	 *
	 * @param string $type    'success' | 'error' | 'warning'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	private static function redirect_css( string $type, string $message ): void {
		$url = add_query_arg(
			array(
				's5_notice'      => rawurlencode( $type ),
				's5_notice_text' => rawurlencode( $message ),
			),
			self::screen_url( 'css' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Redirect back to the StyleGuide screen with a one-shot notice.
	 *
	 * @param string $type    'success' | 'error' | 'warning'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	private static function redirect_styleguide( string $type, string $message ): void {
		$url = add_query_arg(
			array(
				's5_notice'      => rawurlencode( $type ),
				's5_notice_text' => rawurlencode( $message ),
			),
			self::screen_url( 'styleguide' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * The admin URL for a settings screen.
	 *
	 * @param string $screen Screen key (see SCREENS); defaults to the first screen.
	 * @return string
	 */
	public static function screen_url( string $screen = '' ): string {
		$args = array( 'page' => self::MENU_SLUG );
		if ( '' !== $screen && isset( self::SCREENS[ $screen ] ) ) {
			$args['screen'] = $screen;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * The current screen key from the request, or the first screen as default.
	 *
	 * @return string
	 */
	private static function current_screen(): string {
		$screen = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( (string) $_GET['screen'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen router.
		if ( isset( self::SCREENS[ $screen ] ) ) {
			return $screen;
		}
		return (string) array_key_first( self::SCREENS );
	}

	/**
	 * The Roles capability key that governs viewing a settings screen ('' = the
	 * coarse settings.view gate suffices).
	 *
	 * @param string $screen Screen key.
	 * @return string
	 */
	private static function screen_capability( string $screen ): string {
		$map = array(
			'styleguide'   => 'settings.styleguide_view',
			'templates'    => 'settings.templates',
			'components'   => 'settings.components',
			'integrations' => 'settings.integrations',
			'general'      => 'settings.general',
			'css'          => 'settings.css',
			'roles'        => 'settings.roles',
		);
		return isset( $map[ $screen ] ) ? $map[ $screen ] : '';
	}

	/**
	 * Enqueue settings-app assets — only on the settings screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		// The submenu registered under the top-level S5 menu yields the hook
		// 's5_page_' . self::MENU_SLUG.
		if ( 's5_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}
		// FontAwesome 6.5.1 (Free), bundled locally — the settings mockup's icon system.
		$fa = S5_PLUGIN_DIR . 'assets/vendor/fontawesome/css/all.min.css';
		if ( is_readable( $fa ) ) {
			wp_enqueue_style(
				's5-fontawesome',
				get_stylesheet_directory_uri() . '/assets/vendor/fontawesome/css/all.min.css',
				array(),
				'6.5.1'
			);
		}

		$css = S5_PLUGIN_DIR . 'assets/settings/settings.css';
		if ( is_readable( $css ) ) {
			wp_enqueue_style(
				's5-settings',
				get_stylesheet_directory_uri() . '/assets/settings/settings.css',
				array( 's5-fontawesome' ),
				(string) filemtime( $css )
			);
		}

		// The settings app driver (screen toggle, token edit, templates, css layers,
		// integrations, roles). Enqueued on every settings screen so all interactions work.
		$app = S5_PLUGIN_DIR . 'assets/settings/settings.js';
		if ( is_readable( $app ) ) {
			wp_enqueue_script(
				's5-settings-app',
				get_stylesheet_directory_uri() . '/assets/settings/settings.js',
				array(),
				(string) filemtime( $app ),
				true
			);
		}

		// Component Builder driver. Enqueued on EVERY settings-app load (not gated to
		// $_GET['screen']==='components'): the left sub-nav switches screens client-side
		// via history.replaceState (settings.js), so a user who reaches Components by
		// clicking the tab — the normal path — never re-requests the page and would
		// otherwise get a dead pencil button if this script only loaded on a server-side
		// components deep-link. Matches how settings.js itself is unconditionally enqueued.
		$js = S5_PLUGIN_DIR . 'assets/settings/components.js';
		if ( is_readable( $js ) ) {
			wp_enqueue_script(
				's5-settings-components',
				get_stylesheet_directory_uri() . '/assets/settings/components.js',
				array(),
				(string) filemtime( $js ),
				true
			);
			wp_localize_script(
				's5-settings-components',
				'S5Settings',
				array(
					'restBase' => rest_url( Rest::NAMESPACE . '/components/' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
				)
			);
		}

		// StyleGuide "View Styleguide" fullscreen modal driver (defect #3). Same
		// unconditional-enqueue reasoning as components.js above: settings.js
		// switches screens client-side, so this must be present on every load.
		// No REST calls here — the specimen is static markup already in the page,
		// so there is nothing to localize.
		$sg_js = S5_PLUGIN_DIR . 'assets/settings/styleguide.js';
		if ( is_readable( $sg_js ) ) {
			wp_enqueue_script(
				's5-settings-styleguide',
				get_stylesheet_directory_uri() . '/assets/settings/styleguide.js',
				array(),
				(string) filemtime( $sg_js ),
				true
			);
		}
	}

	/**
	 * Render the settings app: sub-nav + the current screen.
	 *
	 * Phase A: a real shell with the seven-screen sub-nav and a per-screen
	 * placeholder body. Phase C replaces each placeholder with the mockup screen and
	 * wires persistence.
	 *
	 * @return void
	 */
	public static function render_screen(): void {
		// R1: the S5 role gate — not just current_user_can — so a WP-Administrator pinned
		// to Client is blocked from the settings page entirely (hidden AND blocked).
		if ( ! current_user_can( self::CAPABILITY ) || ! Roles::can( 'settings.view' ) ) {
			wp_die( esc_html__( 'You do not have permission to view Theme Settings.', 's5' ) );
		}

		$current = self::current_screen();

		// Per-screen role gate: a screen the role cannot reach 403s even by direct URL.
		$screen_cap = self::screen_capability( $current );
		if ( '' !== $screen_cap && ! Roles::can( $screen_cap ) ) {
			// Fall back to the first screen the role CAN see, so a deep-link to a blocked
			// screen lands on an allowed one instead of dying.
			$current = self::first_allowed_screen();
		}

		// The app renders ALL role-visible screens at once; the left sub-nav toggles them
		// client-side (settings.js), exactly like theme-settings-mockup.html. Server-side
		// role gating still decides which screens exist in the DOM at all — a blocked
		// screen is neither rendered nor reachable by the `?screen=` URL (it 403s above).
		?>
		<div class="wrap s5set-wrap s5set" data-s5-settings>
			<h1 class="screen-reader-text"><?php esc_html_e( 'S5 — Theme Settings', 's5' ); ?></h1>
			<?php
			// Inline the REAL --s5-* token values on the app root so the StyleGuide specimen
			// renders from the actual tokens.css / scales.css, never drifting from the site.
			self::render_real_token_style();
			self::maybe_render_oneshot_notice();
			?>

			<div class="s5set-page">
				<header class="page-head">
					<div class="page-logo">S5</div>
					<div class="page-title">
						<h1><?php esc_html_e( 'S5 Framework — Theme Settings', 's5' ); ?></h1>
						<p><?php esc_html_e( 'Agent-native theme · single source of truth for tokens, components & vocabulary', 's5' ); ?></p>
					</div>
					<div class="status-pill"><span class="dot"></span> <?php echo StyleBundle::exists() ? esc_html__( 'Compiled · in sync', 's5' ) : esc_html__( 'Not compiled', 's5' ); ?></div>
				</header>

				<div class="page-body">
					<aside class="subnav" aria-label="<?php esc_attr_e( 'Theme settings screens', 's5' ); ?>">
						<div class="subnav-label"><?php esc_html_e( 'Settings', 's5' ); ?></div>
						<?php
						foreach ( self::SCREENS as $key => $label ) :
							$cap = self::screen_capability( $key );
							if ( '' !== $cap && ! Roles::can( $cap ) ) {
								continue; // Hidden AND blocked (server-side).
							}
							$meta = self::SUBNAV[ $key ] ?? array( 'icon' => 'fa-circle', 'sub' => '' );
							?>
							<a
								class="snav<?php echo $key === $current ? ' active' : ''; ?>"
								href="<?php echo esc_url( self::screen_url( $key ) ); ?>"
								data-screen="<?php echo esc_attr( $key ); ?>"
								aria-current="<?php echo $key === $current ? 'page' : 'false'; ?>"
							>
								<i class="fa-solid <?php echo esc_attr( $meta['icon'] ); ?>"></i>
								<span><?php echo esc_html( $label ); ?><span class="snav-sub"><?php echo esc_html( $meta['sub'] ); ?></span></span>
							</a>
						<?php endforeach; ?>
					</aside>

					<main class="screen-wrap">
						<?php
						foreach ( self::SCREENS as $key => $label ) :
							$cap = self::screen_capability( $key );
							if ( '' !== $cap && ! Roles::can( $cap ) ) {
								continue;
							}
							?>
							<section class="screen<?php echo $key === $current ? ' active' : ''; ?>" data-screen="<?php echo esc_attr( $key ); ?>">
								<?php self::render_screen_body( $key ); ?>
							</section>
						<?php endforeach; ?>
					</main>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Emit an inline <style> setting the REAL --s5-* token values on the app root, read
	 * live from the token files. The StyleGuide specimen and color-role swatches then
	 * render from the actual design system — the mockup hardcodes these, but the build
	 * pulls them from tokens.css / scales.css so a token edit shows immediately.
	 *
	 * @return void
	 */
	private static function render_real_token_style(): void {
		$decls = array();
		foreach ( Styles::read_tokens() as $group ) {
			foreach ( $group as $prop ) {
				$name = (string) $prop['name'];
				$val  = (string) $prop['value'];
				// Only --s5-* custom props; values are already file-sourced. Guard against
				// stray CSS-structural chars so this inline block can't be broken out of.
				if ( 0 === strpos( $name, '--s5-' ) && ! preg_match( '/[{}<;]/', $val ) ) {
					$decls[] = $name . ':' . $val;
				}
			}
		}
		if ( empty( $decls ) ) {
			return;
		}
		printf(
			'<style id="s5set-real-tokens">.s5set{%s;}</style>',
			esc_html( implode( ';', $decls ) )
		);
	}

	/**
	 * The first screen the current role is allowed to view (fallback for a blocked deep-link).
	 *
	 * @return string
	 */
	private static function first_allowed_screen(): string {
		foreach ( self::SCREENS as $key => $label ) {
			$cap = self::screen_capability( $key );
			if ( '' === $cap || Roles::can( $cap ) ) {
				return $key;
			}
		}
		return (string) array_key_first( self::SCREENS );
	}

	/**
	 * Dispatch to a per-screen body renderer. Screens not yet wired show a stub.
	 *
	 * @param string $screen Screen key.
	 * @return void
	 */
	private static function render_screen_body( string $screen ): void {
		switch ( $screen ) {
			case 'styleguide':
				self::render_styleguide();
				return;
			case 'templates':
				self::render_templates();
				return;
			case 'components':
				self::render_components();
				return;
			case 'roles':
				self::render_roles();
				return;
			case 'integrations':
				self::render_integrations();
				return;
			case 'general':
				self::render_general();
				return;
			case 'css':
				self::render_css();
				return;
			default:
				printf( '<h2>%s</h2>', esc_html( self::SCREENS[ $screen ] ?? '' ) );
				echo '<p class="description">' . esc_html__( 'This screen is being wired to the design contract. The sub-nav and routing are live; its controls land in Phase C.', 's5' ) . '</p>';
		}
	}

	/**
	 * StyleGuide screen (HANDOFF §3.4): token cards (read from the real token files)
	 * + a LIGHT specimen of the real primitives, so a non-dev sees what the tokens
	 * produce and swatches never drift. Token EDITING (rewrite files + recompile)
	 * lands as a gated follow-up; this is the read + specimen surface.
	 *
	 * @return void
	 */
	private static function render_styleguide(): void {
		$tokens   = Styles::read_tokens();
		$can_edit = Roles::can( 'settings.styleguide_edit' );
		// Element styling is Developer-tier design work — same gate as raw CSS editing.
		$can_edit_elements = Roles::can( 'settings.css' );
		?>
		<div class="screen-head sg-screen-head">
			<div>
				<h2><?php esc_html_e( 'Styles', 's5' ); ?></h2>
				<p><?php esc_html_e( 'The design system, in three layers: raw Tokens feed the Scales, and the Scales feed the Element styles. Edit a layer and every primitive built on it updates. Open the live specimen to see the real headings, buttons, and cards the site is built from.', 's5' ); ?></p>
			</div>
			<button type="button" class="btn btn--primary" data-s5-sg-view>
				<i class="fa-solid fa-eye"></i> <?php esc_html_e( 'View Styleguide', 's5' ); ?>
			</button>
		</div>

		<?php /* ============================ PART 1 · TOKENS ============================
		 * Raw values (colors, fonts). The floor of the system — everything else
		 * references these. Editable within contract (S5-ATOMIC-DESIGN-SPEC §4). */ ?>
		<section class="sg-part" data-sg-part="tokens">
			<div class="sg-part-head">
				<span class="sg-part-num">1</span>
				<div>
					<h3 class="sg-part-title"><?php esc_html_e( 'Tokens', 's5' ); ?></h3>
					<p class="sg-part-sub"><?php esc_html_e( 'Raw values — colors and fonts. The floor of the design system; everything below references these.', 's5' ); ?></p>
				</div>
			</div>
			<div class="sg-cards-full">
				<?php /* FONTS — informational (families come from the token layer). */ ?>
				<div class="card">
					<div class="card-head"><i class="fa-solid fa-font"></i><h3><?php esc_html_e( 'Fonts', 's5' ); ?></h3><span class="card-note"><?php esc_html_e( 'family & source', 's5' ); ?></span></div>
					<div class="card-body">
						<div class="field">
							<label class="field-label"><?php esc_html_e( 'Font Source', 's5' ); ?></label>
							<div class="seg wide"><div class="seg-opt active"><?php esc_html_e( 'System', 's5' ); ?></div><div class="seg-opt"><?php esc_html_e( 'Google Fonts', 's5' ); ?></div><div class="seg-opt"><?php esc_html_e( 'Self-hosted', 's5' ); ?></div></div>
							<p class="field-hint"><?php esc_html_e( 'Self-hosted keeps fonts on your infrastructure (no third-party requests).', 's5' ); ?></p>
						</div>
						<?php
						$fonts = isset( $tokens['font'] ) ? $tokens['font'] : array();
						foreach ( $fonts as $f ) :
							?>
							<div class="field">
								<label class="field-label"><?php echo esc_html( ucwords( str_replace( array( '--s5-font-', '-' ), array( '', ' ' ), $f['name'] ) ) ); ?> <span class="opt"><?php echo esc_html( $f['name'] ); ?></span></label>
								<input class="input" value="<?php echo esc_attr( $f['value'] ); ?>" style="font-family:var(--s5e-mono);font-size:11.5px" readonly>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<?php self::sg_color_card( __( 'Colors', 's5' ), isset( $tokens['color'] ) ? $tokens['color'] : array(), $can_edit ); ?>
			</div>
		</section>

		<?php /* ============================ PART 2 · SCALES ============================
		 * Type / spacing / radius presets that consume the tokens. The ladder the
		 * element styles reach for as var(--s5-text-…) etc. */ ?>
		<section class="sg-part" data-sg-part="scales">
			<div class="sg-part-head">
				<span class="sg-part-num">2</span>
				<div>
					<h3 class="sg-part-title"><?php esc_html_e( 'Scales', 's5' ); ?></h3>
					<p class="sg-part-sub"><?php esc_html_e( 'Type, spacing and radius ladders built from the tokens. These are the values the element styles reach for.', 's5' ); ?></p>
				</div>
			</div>
			<div class="sg-cards-full">
				<?php
				self::sg_scale_card( __( 'Type Scale', 's5' ), isset( $tokens['text'] ) ? $tokens['text'] : array(), 'text', $can_edit );
				self::sg_scale_card( __( 'Space Scale', 's5' ), isset( $tokens['space'] ) ? $tokens['space'] : array(), 'space', $can_edit );
				self::sg_scale_card( __( 'Radius', 's5' ), isset( $tokens['radius'] ) ? $tokens['radius'] : array(), 'radius', $can_edit );
				?>
			</div>
		</section>

		<?php /* ======================= PART 3 · ELEMENT STYLES =========================
		 * The registered primitives (heading, button, eyebrow, card…) with their REAL
		 * CSS rules, fully editable. This is the Developer control plane where the
		 * closed vocabulary's actual declarations are authored (S5-ATOMIC-DESIGN §4/§5;
		 * PRD §9 scopes "no free CSS" to the page canvas / Content level, not here). */ ?>
		<section class="sg-part" data-sg-part="elements">
			<div class="sg-part-head">
				<span class="sg-part-num">3</span>
				<div>
					<h3 class="sg-part-title"><?php esc_html_e( 'Element styles', 's5' ); ?></h3>
					<p class="sg-part-sub"><?php esc_html_e( 'The registered primitives and their real CSS. Edit any property — use var(--s5-…) to pull from the tokens and scales above. Saved edits recompile the site and every page that uses the element.', 's5' ); ?></p>
				</div>
			</div>
			<div class="sg-elements">
				<?php foreach ( ElementStyles::list_elements() as $el ) : ?>
					<?php self::sg_element_card( $el, $can_edit_elements ); ?>
				<?php endforeach; ?>
			</div>
		</section>

		<div class="sg-modal" data-s5-sg-modal aria-hidden="true">
			<div class="sg-modal-bar">
				<button type="button" class="tpl-modal-back" data-s5-sg-close>
					<i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'Back to Styles', 's5' ); ?>
				</button>
				<div class="tpl-modal-title"><i class="fa-solid fa-swatchbook"></i> <?php esc_html_e( 'Live Styleguide', 's5' ); ?></div>
			</div>
			<div class="sg-modal-stage">
				<?php self::sg_specimen(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * One Element-styles card: the primitive's label + selector, a live preview using
	 * the real class, and (for Developers) an editable textarea of its actual CSS
	 * declarations that posts to {@see self::handle_save_element}. Read-only viewers
	 * see the rule as a static code block.
	 *
	 * @param array{key:string,label:string,selector:string,icon:string,sample:string,rule:string,defined:bool} $el       Element.
	 * @param bool                                                                                               $can_edit Developer-tier.
	 * @return void
	 */
	private static function sg_element_card( array $el, bool $can_edit ): void {
		// The preview element uses the REAL class(es) so the live bundle styles it.
		// '.btn--primary' → class="btn btn--primary"; '.page-hero_title' → that class.
		$classes = self::selector_to_classes( $el['selector'] );
		?>
		<div class="card sg-el-card" data-sg-element="<?php echo esc_attr( $el['key'] ); ?>">
			<div class="card-head">
				<i class="fa-solid <?php echo esc_attr( $el['icon'] ); ?>"></i>
				<h3><?php echo esc_html( $el['label'] ); ?></h3>
				<span class="card-note sg-el-selector"><?php echo esc_html( $el['selector'] ); ?></span>
				<?php if ( ! $el['defined'] ) : ?>
					<span class="pill pill--muted"><?php esc_html_e( 'not yet defined', 's5' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="card-body">
				<?php /* LIVE PREVIEW — the real class, styled by the compiled bundle. */ ?>
				<div class="sg-el-preview">
					<span class="<?php echo esc_attr( $classes ); ?>"><?php echo esc_html( $el['sample'] ); ?></span>
				</div>

				<?php if ( $can_edit ) : ?>
					<form class="sg-el-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::ACTION_SAVE_ELEMENT ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_ELEMENT ); ?>">
						<input type="hidden" name="element" value="<?php echo esc_attr( $el['key'] ); ?>">
						<label class="field-label sg-el-rulelabel">
							<code><?php echo esc_html( $el['selector'] ); ?> {</code>
						</label>
						<textarea class="input sg-el-code" name="declarations" rows="<?php echo (int) max( 4, substr_count( $el['rule'], "\n" ) + 2 ); ?>" spellcheck="false" data-s5-css-editor><?php echo esc_textarea( $el['rule'] ); ?></textarea>
						<div class="sg-el-rulelabel"><code>}</code></div>
						<div class="sg-el-actions">
							<button type="submit" class="btn btn--primary"><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Save & recompile', 's5' ); ?></button>
							<span class="field-hint"><?php esc_html_e( 'Any CSS property. Use var(--s5-…) for tokens.', 's5' ); ?></span>
						</div>
					</form>
				<?php else : ?>
					<pre class="sg-el-readonly"><code><?php echo esc_html( $el['selector'] . " {\n" . $el['rule'] . "\n}" ); ?></code></pre>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Convert a single-rule selector into the space-separated class list a preview
	 * element should carry so the compiled bundle styles it. A BEM modifier selector
	 * ('.btn--primary') needs BOTH the base and the modifier ('btn btn--primary').
	 *
	 * @param string $selector CSS selector (e.g. '.btn--primary', '.page-hero_title').
	 * @return string Space-separated class attribute value.
	 */
	private static function selector_to_classes( string $selector ): string {
		$class = ltrim( $selector, '.' );
		if ( false !== strpos( $class, '--' ) ) {
			$base = substr( $class, 0, strpos( $class, '--' ) );
			return $base . ' ' . $class;
		}
		return $class;
	}

	/**
	 * A colour token card: swatch + name + value per token.
	 *
	 * @param string                                      $title  Card title.
	 * @param array<int, array{name:string,value:string}> $tokens Colour tokens.
	 * @return void
	 */
	private static function sg_color_card( string $title, array $tokens, bool $can_edit = false ): void {
		?>
		<div class="card" data-tokencard="colors">
			<?php /* No card-level pencil — each token row carries its own inline edit
			 * pencil (sg_edit_form); a header button here did nothing but confuse. */ ?>
			<div class="card-head"><i class="fa-solid fa-palette"></i><h3><?php echo esc_html( $title ); ?></h3></div>
			<div class="card-body" id="colorTokens">
				<?php if ( empty( $tokens ) ) : ?>
					<p class="field-hint"><?php esc_html_e( 'No colour tokens declared.', 's5' ); ?></p>
				<?php else : ?>
					<?php foreach ( $tokens as $t ) : ?>
						<div class="token-color">
							<span class="token-sw" style="background:<?php echo esc_attr( $t['value'] ); ?>"></span>
							<span class="token-meta">
								<span class="token-name"><?php echo esc_html( str_replace( '--s5-color-', '', $t['name'] ) ); ?></span>
								<br><span class="token-var"><?php echo esc_html( $t['name'] ); ?></span>
							</span>
							<?php if ( $can_edit ) : ?>
								<?php self::sg_edit_form( $t['name'], $t['value'] ); ?>
							<?php else : ?>
								<span class="token-hex"><?php echo esc_html( $t['value'] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * A scale token card (type / space / radius): a visual sample + name + value.
	 *
	 * @param string                                      $title  Card title.
	 * @param array<int, array{name:string,value:string}> $tokens Scale tokens.
	 * @param string                                      $kind   'text' | 'space' | 'radius'.
	 * @return void
	 */
	private static function sg_scale_card( string $title, array $tokens, string $kind, bool $can_edit = false ): void {
		$prefix = '--s5-' . ( 'text' === $kind ? 'text-' : ( 'space' === $kind ? 'space-' : 'radius-' ) );
		$icons  = array( 'text' => 'fa-text-height', 'space' => 'fa-arrows-left-right-to-line', 'radius' => 'fa-border-top-left' );
		$card   = array( 'text' => 'type', 'space' => 'space', 'radius' => 'radius' );
		// Largest space value → used to scale the .scale-bar width readout (mockup: maxSpace≈4rem).
		$max = 4.0;
		?>
		<div class="card" data-tokencard="<?php echo esc_attr( $card[ $kind ] ); ?>">
			<?php /* No card-level pencil — each scale row carries its own inline edit
			 * pencil (sg_edit_form); a header button here did nothing but confuse. */ ?>
			<div class="card-head"><i class="fa-solid <?php echo esc_attr( $icons[ $kind ] ); ?>"></i><h3><?php echo esc_html( $title ); ?></h3></div>
			<div class="card-body">
				<?php if ( empty( $tokens ) ) : ?>
					<p class="field-hint"><?php esc_html_e( 'No tokens declared.', 's5' ); ?></p>
				<?php else : ?>
					<?php foreach ( $tokens as $t ) : ?>
						<div class="scale-row">
							<span class="scale-key"><?php echo esc_html( $t['name'] ); ?></span>
							<?php if ( 'space' === $kind ) : ?>
								<span class="scale-bar" style="width:<?php echo esc_attr( (string) max( 6, min( 100, ( (float) $t['value'] / $max ) * 100 ) ) ); ?>px"></span>
							<?php elseif ( 'radius' === $kind ) : ?>
								<span class="scale-bar" style="width:26px;height:26px;background:var(--s5e-bg-3);border:1px solid var(--s5e-line);border-radius:<?php echo esc_attr( $t['value'] ); ?>;opacity:1"></span>
							<?php endif; ?>
							<?php if ( $can_edit ) : ?>
								<?php self::sg_edit_form( $t['name'], $t['value'] ); ?>
							<?php else : ?>
								<span class="scale-val"><?php echo esc_html( $t['value'] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Templates screen (HANDOFF §3.2): the shared template PARTS (real files under
	 * site/parts) and the WordPress template HIERARCHY. Each part is canonical HTML in
	 * the framework — the pencil opens it in the canvas editor in ?template mode (the
	 * SAME editor, HANDOFF §3.3). Parts are driven by the real files; hierarchy
	 * templates appear as they are authored (the theme ships parts today).
	 *
	 * @return void
	 */
	private static function render_templates(): void {
		$parts = Templates::list_parts();
		// Part-name → mockup glyph (theme-settings-mockup.html TPL_PARTS). Unknown parts
		// use the mockup's generic puzzle-piece.
		$part_icons = array(
			'site-header' => 'fa-window-maximize',
			'site-footer' => 'fa-window-minimize',
		);
		?>
		<div class="screen-head">
			<h2><?php esc_html_e( 'Templates', 's5' ); ?></h2>
			<p><?php esc_html_e( 'The shared template parts and the WordPress template hierarchy. Each template is canonical HTML in the framework — the edit pencil opens it in the canvas editor, the same way you edit any page.', 's5' ); ?></p>
		</div>

		<?php /* TEMPLATE PARTS — the real files under site/parts. Pencil → canvas ?template mode. */ ?>
		<div class="card">
			<div class="card-head"><i class="fa-solid fa-puzzle-piece"></i><h3><?php esc_html_e( 'Template Parts', 's5' ); ?></h3></div>
			<div class="card-body" data-s5-tpl-parts>
				<?php if ( empty( $parts ) ) : ?>
					<p class="field-hint"><?php esc_html_e( 'No template parts found under site/parts.', 's5' ); ?></p>
				<?php else : ?>
					<?php
					foreach ( $parts as $p ) :
						$ico = $part_icons[ $p['name'] ] ?? 'fa-puzzle-piece';
						?>
						<div class="tpl-row">
							<div class="tpl-ico"><i class="fa-solid <?php echo esc_attr( $ico ); ?>"></i></div>
							<div class="tpl-meta">
								<div class="tpl-name"><?php echo esc_html( $p['label'] ); ?><span class="tpl-file"><?php echo esc_html( $p['file'] ); ?></span></div>
								<div class="tpl-applies"><?php echo esc_html( 'site-header' === $p['name'] ? __( 'Top of every page', 's5' ) : ( 'site-footer' === $p['name'] ? __( 'Bottom of every page', 's5' ) : __( 'Shared template part', 's5' ) ) ); ?></div>
							</div>
							<button type="button" class="switch on tpl-switch" role="switch" aria-checked="true"><span class="knob"></span></button>
							<a class="tpl-edit" href="<?php echo esc_url( self::template_editor_url( $p['name'] ) ); ?>" title="<?php esc_attr_e( 'Edit in canvas editor', 's5' ); ?>"><i class="fa-solid fa-pen"></i></a>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<?php
		/* TEMPLATE HIERARCHY — the standard WP hierarchy resolved to S5 canonical-HTML
		 * templates (TemplateRouter, S5-TEMPLATES-SPEC v0.1). Each row's `key` maps to a
		 * site/templates/<key>.html file; when that file exists the row is ACTIVE (S5
		 * renders it), else it reports "Using default" (WordPress renders it). `page` is a
		 * framework PAGE (canvas), and category/tag/date/author all resolve through the
		 * shared `archive` template. */
		$hierarchy = array(
			array( 'key' => 'front-page', 'name' => __( 'Front Page', 's5' ), 'ico' => 'fa-house', 'applies' => __( 'The site homepage', 's5' ) ),
			array( 'key' => 'single', 'name' => __( 'Single Post', 's5' ), 'ico' => 'fa-file-lines', 'applies' => __( 'All blog posts', 's5' ) ),
			array( 'key' => 'page', 'name' => __( 'Page', 's5' ), 'ico' => 'fa-file', 'applies' => __( 'Framework pages (canvas)', 's5' ) ),
			array( 'key' => 'archive', 'name' => __( 'Archive', 's5' ), 'ico' => 'fa-box-archive', 'applies' => __( 'Date, author & category/tag archives', 's5' ) ),
			array( 'key' => 'search', 'name' => __( 'Search Results', 's5' ), 'ico' => 'fa-magnifying-glass', 'applies' => __( 'The search results page', 's5' ) ),
			array( 'key' => '404', 'name' => __( '404 Not Found', 's5' ), 'ico' => 'fa-triangle-exclamation', 'applies' => __( 'Missing / unresolved URLs', 's5' ) ),
			array( 'key' => 'index', 'name' => __( 'Blog / Index', 's5' ), 'ico' => 'fa-list', 'applies' => __( 'Posts index & catch-all fallback', 's5' ) ),
		);
		?>
		<div class="card">
			<div class="card-head"><i class="fa-solid fa-sitemap"></i><h3><?php esc_html_e( 'Template Hierarchy', 's5' ); ?></h3>
				<span class="card-note"><?php esc_html_e( 'WordPress resolves top → bottom', 's5' ); ?></span>
			</div>
			<div class="card-body" data-s5-tpl-hierarchy>
				<?php
				foreach ( $hierarchy as $t ) :
					$key    = (string) $t['key'];
					// `page` is a framework-page canvas (always active, not a template file).
					$active = ( 'page' === $key ) || ( '' !== TemplateRouter::template_path( $key ) );
					$file   = 'page' === $key ? __( 'canvas (framework pages)', 's5' ) : 'templates/' . $key . '.html';
					?>
					<div class="tpl-row">
						<div class="tpl-ico"><i class="fa-solid <?php echo esc_attr( $t['ico'] ); ?>"></i></div>
						<div class="tpl-meta">
							<div class="tpl-name"><?php echo esc_html( $t['name'] ); ?><span class="tpl-file"><?php echo esc_html( $file ); ?></span></div>
							<div class="tpl-applies"><?php echo esc_html( $t['applies'] ); ?></div>
						</div>
						<?php if ( $active ) : ?>
							<span class="tpl-status active"><span class="d"></span><?php esc_html_e( 'Active', 's5' ); ?></span>
						<?php else : ?>
							<span class="tpl-status default"><span class="d"></span><?php esc_html_e( 'Using default', 's5' ); ?></span>
						<?php endif; ?>
						<?php /* Canvas editing of hierarchy templates (as opposed to parts) is v1.x —
						 * the ?template= editor mode currently authors PARTS. Until it extends to
						 * site/templates/*.html, these render but are edited as files, so no pencil
						 * here (a pencil pointing at a non-part would dead-end). Parts remain
						 * editable via the parts card below. */ ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Components screen (HANDOFF §3.5): a grid of the registered components + a
	 * Component Builder modal (Props / HTML / Logic editable, HTML vocabulary-validated,
	 * CSS READ-ONLY). Cards come from the real registry; the builder reads/writes through
	 * the existing /components REST route (which now enforces CSS-read-only + HTML
	 * validation server-side).
	 *
	 * @return void
	 */
	private static function render_components(): void {
		$names = array();
		try {
			$names = ComponentRegistry::list();
		} catch ( \RuntimeException $e ) {
			$names = array();
		}
		// Component-name → mockup glyph (theme-settings-mockup.html COMPONENTS). Unknown
		// names use the mockup's neutral fa-cube.
		$glyphs = array(
			'hero'         => 'fa-star',
			'cta'          => 'fa-bullhorn',
			'feature-grid' => 'fa-table-columns',
			'pricing'      => 'fa-tags',
			'pricing-card' => 'fa-tags',
			'testimonial'  => 'fa-quote-left',
			'faq'          => 'fa-circle-question',
			'faq-item'     => 'fa-circle-question',
			'card'         => 'fa-square',
			'rich-text'    => 'fa-heading',
			'richtext'     => 'fa-heading',
			'media'        => 'fa-image',
			'button'       => 'fa-hand-pointer',
			'query-loop'   => 'fa-infinity',
			'query'        => 'fa-infinity',
			'nav-menu'     => 'fa-bars',
			'nav'          => 'fa-bars',
			'embed'        => 'fa-code',
		);
		?>
		<div class="screen-head">
			<h2><?php esc_html_e( 'Components', 's5' ); ?></h2>
			<p><?php esc_html_e( 'The registered library — these are the only elements the canvas editor can drop onto a page. Enabling something here makes it available to non-dev authors; disabling removes it from their palette. Each ships with its own registered variants.', 's5' ); ?></p>
		</div>

		<div class="registered-note">
			<i class="fa-solid fa-shield-halved"></i>
			<div><?php echo wp_kses_post( __( '<strong>This is the whole vocabulary a client can build with.</strong> No page-builder primitives, no arbitrary blocks — only what&rsquo;s registered here (plus the single <span class="mono">Embed</span> escape hatch for third-party HTML). New component? A developer registers it, and it appears in the canvas.', 's5' ) ); ?></div>
		</div>

		<div class="comp-grid" data-s5-comp-grid>
			<?php
			foreach ( $names as $name ) :
				try {
					$c = ComponentRegistry::load( $name );
				} catch ( \RuntimeException $e ) {
					continue;
				}
				$glyph    = $glyphs[ strtolower( $name ) ] ?? 'fa-cube';
				$variants = isset( $c['variants'] ) && is_array( $c['variants'] ) ? $c['variants'] : array();
				?>
				<div class="comp-card" data-s5-comp-open="<?php echo esc_attr( $name ); ?>">
					<div class="comp-top">
						<div class="comp-ico"><i class="fa-solid <?php echo esc_attr( $glyph ); ?>"></i></div>
						<div style="flex:1;min-width:0">
							<div class="comp-name"><?php echo esc_html( $name ); ?></div>
							<div class="comp-slug">&lt;s5-<?php echo esc_html( $name ); ?>&gt;<?php echo isset( $c['version'] ) ? ' · v' . esc_html( (string) $c['version'] ) : ''; ?></div>
						</div>
						<button type="button" class="comp-open" data-s5-comp-open="<?php echo esc_attr( $name ); ?>" title="<?php esc_attr_e( 'Open builder', 's5' ); ?>"><i class="fa-solid fa-pen"></i></button>
						<button type="button" class="switch on" role="switch" aria-checked="true"><span class="knob"></span></button>
					</div>
					<div class="comp-desc"><?php echo esc_html( (string) $c['description'] ); ?></div>
					<?php if ( ! empty( $variants ) ) : ?>
						<div class="comp-variants"><?php foreach ( $variants as $v ) : ?><span class="vchip"><?php echo esc_html( (string) $v ); ?></span><?php endforeach; ?></div>
					<?php endif; ?>
					<div class="comp-meta">
						<span class="m"><i class="fa-solid fa-cube"></i> &lt;s5-<?php echo esc_html( $name ); ?>&gt;</span>
						<?php if ( ! empty( $variants ) ) : ?>
							<span class="m"><i class="fa-solid fa-sliders"></i> <?php echo (int) count( $variants ); ?> <?php echo esc_html( _n( 'variant', 'variants', count( $variants ), 's5' ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
			<?php if ( empty( $names ) ) : ?>
				<p class="field-hint"><?php esc_html_e( 'No components registered.', 's5' ); ?></p>
			<?php endif; ?>
		</div>

		<?php /* Component Builder — full-screen modal, driven by components.js via the REST route. */ ?>
		<div class="cb-modal" data-s5-cb aria-hidden="true">
			<header class="cb-top">
				<div class="cb-top-l">
					<button type="button" class="cb-back" data-s5-cb-close><i class="fa-solid fa-arrow-left"></i> <?php esc_html_e( 'All components', 's5' ); ?></button>
					<input class="cb-name" data-s5-cb-name value="">
					<span class="cb-slug mono" data-s5-cb-slug></span>
				</div>
				<div class="cb-top-r">
					<div class="cb-lens">
						<button type="button" class="cb-lens-btn active" data-cblens="dev"><i class="fa-solid fa-code"></i> <?php esc_html_e( 'Dev', 's5' ); ?></button>
						<button type="button" class="cb-lens-btn" data-cblens="preview"><i class="fa-solid fa-eye"></i> <?php esc_html_e( 'Preview', 's5' ); ?></button>
					</div>
					<button type="button" class="cb-save" data-s5-cb-save><i class="fa-solid fa-check"></i> <?php esc_html_e( 'Save changes', 's5' ); ?></button>
					<button type="button" class="cb-icon" data-s5-cb-close title="<?php esc_attr_e( 'Close', 's5' ); ?>"><i class="fa-solid fa-xmark"></i></button>
				</div>
			</header>
			<div class="cb-body">
				<aside class="cb-props">
					<div class="cb-props-head"><span><?php esc_html_e( 'Properties', 's5' ); ?></span></div>
					<div class="cb-fields" data-s5-cb-props></div>
				</aside>
				<main class="cb-center">
					<section class="cb-html">
						<div class="cb-panel-head"><span><?php esc_html_e( 'HTML template', 's5' ); ?></span><span class="cb-panel-note"><?php esc_html_e( 'Use component properties with {{ double braces }} · validated against the closed vocabulary', 's5' ); ?></span></div>
						<div class="cb-code"><textarea data-s5-cb-html spellcheck="false"></textarea></div>
						<div class="cb-code-foot"><span class="ok"><span class="dot"></span><?php esc_html_e( 'Vocabulary OK', 's5' ); ?></span><span class="cb-panel-note" style="margin-left:auto" data-s5-cb-diagnostics></span></div>
					</section>
				</main>
				<aside class="cb-right">
					<section class="cb-css">
						<div class="cb-panel-head"><span><?php esc_html_e( 'Component CSS', 's5' ); ?></span><span class="cb-tag readonly"><?php esc_html_e( 'read-only · from components.css', 's5' ); ?></span></div>
						<div class="cb-code"><textarea data-s5-cb-css readonly spellcheck="false"></textarea></div>
						<div class="cb-code-foot"><i class="fa-solid fa-lock" style="font-size:10px;color:var(--s5e-warning)"></i><span class="cb-panel-note"><?php esc_html_e( 'Scoped styles are owned by the design system — edit in the CSS / Tailwind tab.', 's5' ); ?></span></div>
					</section>
					<section class="cb-logic">
						<div class="cb-panel-head"><span><?php esc_html_e( 'Logic (manifest)', 's5' ); ?></span><span class="cb-panel-note"><?php esc_html_e( 'runs when the component loads', 's5' ); ?></span></div>
						<div class="cb-code"><textarea data-s5-cb-manifest spellcheck="false"></textarea></div>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Roles screen (§3.7 / §4 / R1): the capability matrix + per-user overrides. The
	 * matrix is the spec for which surfaces each S5 role reaches; it is enforced
	 * server-side everywhere (Roles::can). Developer is always all-granted (the owner
	 * role) so this screen can never lock itself out.
	 *
	 * @return void
	 */
	private static function render_roles(): void {
		$caps      = Roles::capabilities();
		$matrix    = Roles::matrix();
		$roles     = Roles::ROLES; // developer, marketer, client.
		$role_meta = array(
			'developer' => array( 'icon' => 'fa-code', 'sub' => __( 'Full control · maps to WP Administrator', 's5' ), 'desc' => __( 'Sees and does everything. The only role that touches the closed vocabulary, template structure, CSS/Tailwind, integrations, roles, and deploy.', 's5' ) ),
			'marketer'  => array( 'icon' => 'fa-bullhorn', 'sub' => __( 'Content + Style + Advanced · maps to WP Editor', 's5' ), 'desc' => __( 'Edits content, component variants & sizing, plus per-element Advanced (SEO, tag, visibility). Can open Theme Settings, but not Roles, CSS/Tailwind, or Deploy.', 's5' ) ),
			'client'    => array( 'icon' => 'fa-user', 'sub' => __( 'Content + Style only · maps to WP Author', 's5' ), 'desc' => __( 'In the canvas, sees only the Content and Style tabs — no Advanced, no structure surgery. Never sees this settings page at all.', 's5' ) ),
		);
		?>
		<div class="screen-head">
			<h2><?php esc_html_e( 'Roles & Access', 's5' ); ?></h2>
			<p><?php esc_html_e( 'Three built-in roles decide who sees what — in this settings area and inside the canvas editor. Like Webflow or Sanity: the further from Developer, the fewer surfaces are exposed. This is the guardrail that keeps a client from touching structure, CSS, or deploy.', 's5' ); ?></p>
		</div>

		<div class="roles-grid">
			<?php
			foreach ( $roles as $r ) :
				$m = $role_meta[ $r ] ?? array( 'icon' => 'fa-user', 'sub' => '', 'desc' => '' );
				?>
				<article class="role-card" data-role="<?php echo esc_attr( $r ); ?>">
					<div class="role-top">
						<span class="role-ico"><i class="fa-solid <?php echo esc_attr( $m['icon'] ); ?>"></i></span>
						<div>
							<h3><?php echo esc_html( ucfirst( $r ) ); ?></h3>
							<p class="role-sub"><?php echo esc_html( $m['sub'] ); ?></p>
						</div>
					</div>
					<p class="role-desc"><?php echo esc_html( $m['desc'] ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_ROLES ); ?>">
			<?php wp_nonce_field( self::ACTION_SAVE_ROLES ); ?>

			<div class="card">
				<div class="card-head"><i class="fa-solid fa-table-cells"></i><h3><?php esc_html_e( 'Permission matrix', 's5' ); ?></h3></div>
				<div class="card-body">
					<p class="card-hint"><?php esc_html_e( 'What each role can reach — click any cell to toggle it. The canvas rows control which editor tabs render when that role opens a page.', 's5' ); ?></p>
					<div class="perm-scroll">
						<table class="perm-table" data-s5-perm-table>
							<thead>
								<tr>
									<th class="perm-cap"><?php esc_html_e( 'Capability', 's5' ); ?></th>
									<?php
									$role_icons = array( 'developer' => 'fa-code', 'marketer' => 'fa-bullhorn', 'client' => 'fa-user' );
									foreach ( $roles as $r ) :
										?>
										<th class="perm-role"><i class="fa-solid <?php echo esc_attr( $role_icons[ $r ] ?? 'fa-user' ); ?>"></i> <?php echo esc_html( ucfirst( $r ) ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $caps as $key => $def ) : ?>
									<tr>
										<td class="perm-cap"><?php echo esc_html( $def['label'] ); ?></td>
										<?php foreach ( $roles as $ci => $r ) : ?>
											<?php $granted = isset( $matrix[ $key ][ $ci ] ) ? (bool) $matrix[ $key ][ $ci ] : false; ?>
											<td class="perm-mark">
												<?php /* Real submit value = a hidden checkbox; the visible mockup button mirrors + toggles it (settings.js). Developer locked-on. */ ?>
												<input type="checkbox" class="screen-reader-text" name="matrix[<?php echo esc_attr( $key ); ?>][<?php echo (int) $ci; ?>]" value="1" <?php checked( $granted ); ?> <?php disabled( 0 === $ci ); ?> data-s5-perm-input>
												<button type="button" class="perm-mark-btn <?php echo $granted ? 'on' : 'off'; ?>" aria-pressed="<?php echo $granted ? 'true' : 'false'; ?>" <?php echo 0 === $ci ? 'disabled aria-disabled="true"' : ''; ?> data-s5-perm-btn title="<?php echo esc_attr( $r ); ?>">
													<i class="fa-solid <?php echo $granted ? 'fa-check' : 'fa-minus'; ?>"></i>
												</button>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p class="perm-legend"><span class="perm-yes"><i class="fa-solid fa-check"></i></span> <?php esc_html_e( 'allowed', 's5' ); ?> &nbsp;·&nbsp; <span class="perm-no"><i class="fa-solid fa-minus"></i></span> <?php esc_html_e( 'hidden / blocked', 's5' ); ?></p>
				</div>
			</div>

			<div class="card">
				<div class="card-head"><i class="fa-solid fa-users"></i><h3><?php esc_html_e( 'Users & role assignment', 's5' ); ?></h3></div>
				<div class="card-body">
					<p class="card-hint"><?php esc_html_e( 'Roles do nothing until they’re assigned. Map each WordPress user to an S5 role — this is the actual gate that decides what they see in the editor and here in settings.', 's5' ); ?></p>
					<div class="user-list">
						<?php
						$users     = get_users( array( 'number' => 50 ) );
						$overrides = get_option( Roles::OPTION_OVERRIDES, array() );
						$overrides = is_array( $overrides ) ? $overrides : array();
						$avatars   = array( '#5b8cff', '#e0a617', '#2a9d5c', '#a855c7', '#d63638', '#0aa2c0' );
						foreach ( $users as $idx => $u ) :
							$uid      = (int) $u->ID;
							$resolved = Roles::role_for_user( $uid );
							$override = isset( $overrides[ $uid ] ) ? (string) $overrides[ $uid ] : '';
							$active   = '' !== $override ? $override : $resolved;
							$name     = (string) $u->display_name;
							$parts    = preg_split( '/\s+/', trim( $name ) ) ?: array();
							$initials = strtoupper( substr( ( $parts[0] ?? '' ), 0, 1 ) . ( isset( $parts[1] ) ? substr( $parts[1], 0, 1 ) : '' ) );
							$color    = $avatars[ $idx % count( $avatars ) ];
							?>
							<div class="user-row">
								<span class="user-avatar" style="background:<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $initials ); ?></span>
								<div class="user-id">
									<div class="user-name"><?php echo esc_html( $name ); ?></div>
									<div class="user-email"><?php echo esc_html( (string) $u->user_email ); ?></div>
								</div>
								<div class="user-wp"><?php esc_html_e( 'WP role', 's5' ); ?><br><span class="user-wp-role"><?php echo esc_html( implode( ', ', (array) $u->roles ) ); ?></span></div>
								<select class="select user-role-sel role-<?php echo esc_attr( $active ); ?>" name="override[<?php echo esc_attr( (string) $uid ); ?>]" data-s5-user-role>
									<?php foreach ( $roles as $r ) : ?>
										<option value="<?php echo esc_attr( $r ); ?>"<?php selected( $r, $active ); ?>><?php echo esc_html( ucfirst( $r ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</div>

			<p style="margin-top:6px"><button type="submit" class="deploy-btn" style="height:34px;padding:0 14px"><i class="fa-solid fa-check"></i> <?php esc_html_e( 'Save roles', 's5' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Integrations screen (§3.6): the snippets list + a raw add/edit form. All
	 * head/analytics code lives here; the theme injects each enabled snippet at its
	 * point on matching conditions (S5\Integrations).
	 *
	 * @return void
	 */
	private static function render_integrations(): void {
		$list   = Integrations::all();
		$points = Integrations::point_labels();
		// Integration-name → mockup glyph (heuristic on the name; unknown → fa-code).
		$icon_for = static function ( string $name ): string {
			$n = strtolower( $name );
			if ( false !== strpos( $n, 'analytic' ) || false !== strpos( $n, 'ga' ) ) {
				return 'fa-chart-line';
			}
			if ( false !== strpos( $n, 'pixel' ) || false !== strpos( $n, 'meta' ) || false !== strpos( $n, 'facebook' ) ) {
				return 'fa-magnifying-glass-chart';
			}
			return 'fa-code';
		};
		?>
		<div class="screen-head css-head">
			<div>
				<h2><?php esc_html_e( 'Integrations', 's5' ); ?></h2>
				<p><?php echo wp_kses_post( __( 'Third-party snippets — analytics, pixels, chat widgets, verification tags. Paste the code, choose where it injects, toggle it on, and scope which pages it runs on. This is where anything that used to go in <span class="mono">&lt;head&gt;</span> lives.', 's5' ) ); ?></p>
			</div>
			<button type="button" class="deploy-btn" data-s5-int-add><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Add integration', 's5' ); ?></button>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_INTEGRATIONS ); ?>">
			<?php wp_nonce_field( self::ACTION_SAVE_INTEGRATIONS ); ?>
			<div data-s5-int-list>
				<?php
				foreach ( $list as $i => $row ) :
					$on = ! empty( $row['enabled'] );
					?>
					<div class="int-card <?php echo $on ? '' : 'off'; ?>" data-int-row>
						<div class="int-head">
							<div class="int-ico"><i class="fa-solid <?php echo esc_attr( $icon_for( (string) $row['name'] ) ); ?>"></i></div>
							<input type="hidden" name="int[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
							<input class="int-name-input" name="int[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( (string) $row['name'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Google Analytics 4', 's5' ); ?>">
							<input type="hidden" name="int[<?php echo (int) $i; ?>][enabled]" value="<?php echo $on ? '1' : '0'; ?>" data-s5-int-enabled>
							<button type="button" class="switch <?php echo $on ? 'on' : ''; ?>" role="switch" aria-checked="<?php echo $on ? 'true' : 'false'; ?>" data-s5-int-switch><span class="knob"></span></button>
							<button type="button" class="int-del" data-s5-int-del title="<?php esc_attr_e( 'Delete', 's5' ); ?>"><i class="fa-solid fa-trash-can"></i></button>
						</div>
						<div class="int-body">
							<textarea class="int-code" name="int[<?php echo (int) $i; ?>][code]" spellcheck="false" placeholder="<?php esc_attr_e( '<script>…</script>', 's5' ); ?>"><?php echo esc_textarea( (string) $row['code'] ); ?></textarea>
							<div class="int-controls">
								<div class="int-ctrl">
									<label><?php esc_html_e( 'Injection point', 's5' ); ?></label>
									<select class="select" name="int[<?php echo (int) $i; ?>][point]">
										<?php foreach ( $points as $pk => $pl ) : ?>
											<option value="<?php echo esc_attr( $pk ); ?>" <?php selected( $pk, $row['point'] ); ?>><?php echo esc_html( $pl ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
							</div>
							<?php self::int_conditions( (string) $i, isset( $row['conditions'] ) && is_array( $row['conditions'] ) ? $row['conditions'] : array() ); ?>
						</div>
					</div>
				<?php endforeach; ?>
				<?php if ( empty( $list ) ) : ?>
					<p class="field-hint" data-s5-int-empty><?php esc_html_e( 'No integrations yet. Click “Add integration” to paste your first snippet.', 's5' ); ?></p>
				<?php endif; ?>
			</div>
			<p style="margin-top:6px"><button type="submit" class="deploy-btn" style="height:34px;padding:0 14px"><i class="fa-solid fa-check"></i> <?php esc_html_e( 'Save integrations', 's5' ); ?></button></p>
		</form>

		<?php /* Template for a new integration row (cloned by settings.js on Add). */ ?>
		<template data-s5-int-template>
			<div class="int-card off" data-int-row>
				<div class="int-head">
					<div class="int-ico"><i class="fa-solid fa-code"></i></div>
					<input type="hidden" name="int[__i__][id]" value="">
					<input class="int-name-input" name="int[__i__][name]" value="" placeholder="<?php esc_attr_e( 'New integration', 's5' ); ?>">
					<input type="hidden" name="int[__i__][enabled]" value="0" data-s5-int-enabled>
					<button type="button" class="switch" role="switch" aria-checked="false" data-s5-int-switch><span class="knob"></span></button>
					<button type="button" class="int-del" data-s5-int-del title="<?php esc_attr_e( 'Delete', 's5' ); ?>"><i class="fa-solid fa-trash-can"></i></button>
				</div>
				<div class="int-body">
					<textarea class="int-code" name="int[__i__][code]" spellcheck="false" placeholder="<?php esc_attr_e( '<script>…</script>', 's5' ); ?>"></textarea>
					<div class="int-controls">
						<div class="int-ctrl">
							<label><?php esc_html_e( 'Injection point', 's5' ); ?></label>
							<select class="select" name="int[__i__][point]">
								<?php foreach ( $points as $pk => $pl ) : ?>
									<option value="<?php echo esc_attr( $pk ); ?>"><?php echo esc_html( $pl ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
					</div>
					<?php self::int_conditions( '__i__' ); ?>
				</div>
			</div>
		</template>
		<?php
	}

	/**
	 * The shared per-integration condition builder (mockup intConditions()).
	 *
	 * @return void
	 */
	private static function int_conditions( string $i, array $conditions = array() ): void {
		// Backend model (Integrations::conditions_pass): {} = runs everywhere;
		// { include:[…] } = only where a rule matches; { exclude:[…] } = everywhere
		// except. We surface that as three radio modes so "Entire site" is the
		// zero-config default and nobody has to stack rules to cover the whole site.
		$include = isset( $conditions['include'] ) && is_array( $conditions['include'] ) ? $conditions['include'] : array();
		$exclude = isset( $conditions['exclude'] ) && is_array( $conditions['exclude'] ) ? $conditions['exclude'] : array();
		$mode    = ! empty( $include ) ? 'include' : ( ! empty( $exclude ) ? 'exclude' : 'all' );
		$rules   = 'exclude' === $mode ? $exclude : $include;
		if ( empty( $rules ) ) {
			$rules = array( array( 'type' => 'post_type', 'op' => 'is', 'value' => 'page' ) ); // one blank prototype row
		}
		$base = 'int[' . $i . '][conditions]';
		?>
		<div class="int-cond" data-s5-cond>
			<label class="int-cond-label"><?php esc_html_e( 'Show on', 's5' ); ?></label>
			<div class="cond-modes">
				<label class="cond-mode"><input type="radio" name="<?php echo esc_attr( $base ); ?>[mode]" value="all" <?php checked( 'all', $mode ); ?> data-s5-cond-mode><i class="fa-solid fa-globe"></i> <?php esc_html_e( 'Entire site', 's5' ); ?></label>
				<label class="cond-mode"><input type="radio" name="<?php echo esc_attr( $base ); ?>[mode]" value="include" <?php checked( 'include', $mode ); ?> data-s5-cond-mode><i class="fa-solid fa-filter"></i> <?php esc_html_e( 'Only these pages', 's5' ); ?></label>
				<label class="cond-mode"><input type="radio" name="<?php echo esc_attr( $base ); ?>[mode]" value="exclude" <?php checked( 'exclude', $mode ); ?> data-s5-cond-mode><i class="fa-solid fa-ban"></i> <?php esc_html_e( 'Everywhere except', 's5' ); ?></label>
			</div>
			<div class="cond-group" data-s5-cond-rules <?php echo 'all' === $mode ? 'hidden' : ''; ?>>
				<?php
				$ri = 0;
				foreach ( $rules as $r ) :
					self::int_cond_row( $base, (string) $ri, (array) $r );
					++$ri;
				endforeach;
				?>
				<button type="button" class="cond-add" data-s5-cond-add data-cond-base="<?php echo esc_attr( $base ); ?>" data-cond-seq="<?php echo (int) $ri; ?>"><i class="fa-solid fa-plus"></i> <?php esc_html_e( 'Add rule', 's5' ); ?></button>
			</div>
			<?php /* Prototype row for JS "Add rule" (index token __r__ swapped on clone). */ ?>
			<template data-s5-cond-template><?php self::int_cond_row( $base, '__r__', array() ); ?></template>
		</div>
		<?php
	}

	/**
	 * One integration condition rule row (a genuine, name-carrying set of selects so
	 * the rule actually submits). Value vocabulary is deliberately small — it mirrors
	 * exactly what Integrations::rule_matches evaluates (post_type is/is not page|post,
	 * or front_page). $idx is the array index, or the literal '__r__' clone token.
	 *
	 * @param string              $base Field-name base, e.g. int[3][conditions].
	 * @param string              $idx  Numeric index or '__r__'.
	 * @param array<string,mixed> $r    Stored rule (type/op/value).
	 * @return void
	 */
	private static function int_cond_row( string $base, string $idx, array $r ): void {
		$rtype = isset( $r['type'] ) ? (string) $r['type'] : 'post_type';
		$rop   = isset( $r['op'] ) ? (string) $r['op'] : 'is';
		$rval  = isset( $r['value'] ) ? (string) $r['value'] : 'page';
		$n     = $base . '[rules][' . $idx . ']';
		// 'post' = a specific page/post by ID; its value select lists real pages. Only the
		// value control matching the chosen type submits (the other is disabled), so a rule
		// is always one clean {type, op, value}. JS (bindConditions) toggles them on change.
		$is_specific = ( 'post' === $rtype );
		?>
		<div class="cond-row" data-s5-cond-row>
			<select class="select cond-sel" name="<?php echo esc_attr( $n ); ?>[type]" data-s5-cond-type>
				<option value="post_type" <?php selected( 'post_type', $rtype ); ?>><?php esc_html_e( 'Post type', 's5' ); ?></option>
				<option value="post" <?php selected( 'post', $rtype ); ?>><?php esc_html_e( 'Specific page', 's5' ); ?></option>
				<option value="front_page" <?php selected( 'front_page', $rtype ); ?>><?php esc_html_e( 'Homepage', 's5' ); ?></option>
			</select>
			<select class="select cond-op" name="<?php echo esc_attr( $n ); ?>[op]">
				<option value="is" <?php selected( 'is', $rop ); ?>><?php esc_html_e( 'is', 's5' ); ?></option>
				<option value="is not" <?php selected( 'is not', $rop ); ?>><?php esc_html_e( 'is not', 's5' ); ?></option>
			</select>
			<?php /* Post-type value (page/post) — active unless the type is a specific page. */ ?>
			<select class="select cond-val cond-val-type" name="<?php echo esc_attr( $n ); ?>[value]" data-s5-cond-val="post_type"<?php echo $is_specific ? ' hidden disabled' : ''; ?>>
				<option value="page" <?php selected( 'page', $rval ); ?>><?php esc_html_e( 'Page', 's5' ); ?></option>
				<option value="post" <?php selected( 'post', $rval ); ?>><?php esc_html_e( 'Post', 's5' ); ?></option>
			</select>
			<?php /* Specific-page value — a real list of pages by title → ID. */ ?>
			<select class="select cond-val cond-val-page" name="<?php echo esc_attr( $n ); ?>[value]" data-s5-cond-val="post"<?php echo $is_specific ? '' : ' hidden disabled'; ?>>
				<?php foreach ( self::condition_pages() as $pid => $ptitle ) : ?>
					<option value="<?php echo (int) $pid; ?>" <?php selected( (string) $pid, $rval ); ?>><?php echo esc_html( $ptitle ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="cond-del" title="<?php esc_attr_e( 'Remove', 's5' ); ?>"><i class="fa-solid fa-xmark"></i></button>
		</div>
		<?php
	}

	/**
	 * Published pages as id => title, for the "Specific page" condition value select.
	 * Cached per-request; titles are trimmed for the compact select.
	 *
	 * @return array<int, string>
	 */
	private static function condition_pages(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		$pages = get_pages( array( 'sort_column' => 'post_title', 'number' => 200 ) );
		if ( is_array( $pages ) ) {
			foreach ( $pages as $p ) {
				$title = trim( (string) $p->post_title );
				$cache[ (int) $p->ID ] = '' !== $title ? $title : sprintf( '#%d', (int) $p->ID );
			}
		}
		return $cache;
	}

	/**
	 * General screen (§3.1): identity (site name/tagline — WP core), redirects manager
	 * (→ 301s via Redirects), and a Build & Deploy status card (recompile now).
	 *
	 * @return void
	 */
	private static function render_general(): void {
		$redirects  = Redirects::all();
		$rebuild    = wp_nonce_url(
			add_query_arg( array( 'action' => Admin::ACTION_REBUILD ), admin_url( 'admin-post.php' ) ),
			Admin::ACTION_REBUILD
		);
		$site_name  = get_bloginfo( 'name' );
		$tagline    = get_bloginfo( 'description' );
		$compiled   = StyleBundle::exists();
		$logo_id    = (int) get_theme_mod( 'custom_logo' );
		$logo_url   = $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '';
		$reading    = admin_url( 'options-general.php' );
		?>
		<div class="screen-head">
			<h2><?php esc_html_e( 'General', 's5' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: <strong>Settings → General</strong>, 2: <strong>Templates</strong>. */
					wp_kses_post( __( 'Theme identity, redirects, and how the framework builds. Site name &amp; tagline are set in WordPress&rsquo; native %1$s. Template parts &amp; the WP hierarchy live on the %2$s tab.', 's5' ) ),
					'<strong>' . esc_html__( 'Settings → General', 's5' ) . '</strong>',
					'<strong>' . esc_html__( 'Templates', 's5' ) . '</strong>'
				);
				?>
			</p>
		</div>

		<?php /* IDENTITY — read from WP core (site name / tagline / custom logo). */ ?>
		<div class="card">
			<div class="card-head"><i class="fa-solid fa-fingerprint"></i><h3><?php esc_html_e( 'Identity', 's5' ); ?></h3><span class="card-note"><a href="<?php echo esc_url( $reading ); ?>" style="color:var(--s5e-text-faint)"><?php esc_html_e( 'edit in Settings → General', 's5' ); ?></a></span></div>
			<div class="card-body">
				<div class="field-row">
					<div class="field"><label class="field-label"><?php esc_html_e( 'Site Name', 's5' ); ?></label><input class="input" value="<?php echo esc_attr( $site_name ); ?>" readonly></div>
					<div class="field"><label class="field-label"><?php esc_html_e( 'Tagline', 's5' ); ?></label><input class="input" value="<?php echo esc_attr( $tagline ); ?>" readonly></div>
				</div>
				<div class="field-row">
					<div class="field">
						<label class="field-label"><?php esc_html_e( 'Logo', 's5' ); ?></label>
						<div class="media-field">
							<div class="media-thumb<?php echo $logo_url ? '' : ' noimg'; ?>"><img src="<?php echo esc_url( $logo_url ); ?>" alt=""><span class="media-thumb-ph"><i class="fa-solid fa-image"></i></span></div>
							<div class="media-meta"><div class="media-name mono"><?php echo esc_html( $logo_url ? basename( $logo_url ) : __( 'no logo set', 's5' ) ); ?></div><a class="media-lib" href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>"><i class="fa-regular fa-images"></i> <?php esc_html_e( 'Customizer', 's5' ); ?></a></div>
						</div>
					</div>
					<div class="field">
						<label class="field-label"><?php esc_html_e( 'Favicon', 's5' ); ?></label>
						<div class="media-field">
							<div class="media-thumb<?php echo get_site_icon_url() ? '' : ' noimg'; ?>"><img src="<?php echo esc_url( (string) get_site_icon_url() ); ?>" alt=""><span class="media-thumb-ph"><i class="fa-solid fa-star"></i></span></div>
							<div class="media-meta"><div class="media-name mono"><?php echo esc_html( get_site_icon_url() ? basename( (string) get_site_icon_url() ) : __( 'no favicon', 's5' ) ); ?></div><a class="media-lib" href="<?php echo esc_url( admin_url( 'customize.php' ) ); ?>"><i class="fa-regular fa-images"></i> <?php esc_html_e( 'Customizer', 's5' ); ?></a></div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<?php /* REDIRECTS — real 301/302 store (Redirects). */ ?>
		<div class="card">
			<div class="card-head"><i class="fa-solid fa-signs-post"></i><h3><?php esc_html_e( 'Redirects', 's5' ); ?></h3><span class="card-note"><?php esc_html_e( '301 · old URL → new', 's5' ); ?></span></div>
			<div class="card-body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_REDIRECTS ); ?>">
					<?php wp_nonce_field( self::ACTION_SAVE_REDIRECTS ); ?>
					<div data-s5-redirects>
						<?php
						$rrows   = $redirects;
						$rrows[] = array(
							'from' => '',
							'to'   => '',
							'code' => 301,
						);
						foreach ( $rrows as $i => $r ) :
							?>
							<div class="rdr-row">
								<input type="text" name="rdr[<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr( (string) $r['from'] ); ?>" placeholder="/old-path">
								<span class="arrow"><i class="fa-solid fa-arrow-right"></i></span>
								<input type="text" name="rdr[<?php echo (int) $i; ?>][to]" value="<?php echo esc_attr( (string) $r['to'] ); ?>" placeholder="/new-path or https://…">
								<select name="rdr[<?php echo (int) $i; ?>][code]" class="rdr-code" style="appearance:none;border:none">
									<option value="301" <?php selected( 301, (int) $r['code'] ); ?>>301</option>
									<option value="302" <?php selected( 302, (int) $r['code'] ); ?>>302</option>
								</select>
								<button type="button" class="token-del" data-s5-rdr-del title="<?php esc_attr_e( 'Remove', 's5' ); ?>"><i class="fa-solid fa-xmark"></i></button>
							</div>
						<?php endforeach; ?>
					</div>
					<p style="margin-top:14px"><button type="submit" class="deploy-btn" style="height:34px;padding:0 14px"><i class="fa-solid fa-check"></i> <?php esc_html_e( 'Save redirects', 's5' ); ?></button></p>
				</form>
			</div>
		</div>

		<?php /* BUILD & DEPLOY — real compile status + recompile. */ ?>
		<div class="card">
			<div class="card-head"><i class="fa-solid fa-gears"></i><h3><?php esc_html_e( 'Build & Deploy', 's5' ); ?></h3><span class="card-note"><?php esc_html_e( 'compile status', 's5' ); ?></span></div>
			<div class="card-body">
				<div class="deploy-status">
					<div class="ds-item"><span class="ds-k"><?php esc_html_e( 'Status', 's5' ); ?></span><span class="ds-v <?php echo $compiled ? 'ok' : ''; ?>"><?php echo $compiled ? '<span class="d"></span>' : ''; ?><?php echo $compiled ? esc_html__( 'Compiled · in sync', 's5' ) : esc_html__( 'Not compiled', 's5' ); ?></span></div>
					<div class="ds-item"><span class="ds-k"><?php esc_html_e( 'Bundle', 's5' ); ?></span><span class="ds-v"><?php echo $compiled ? esc_html__( 'present', 's5' ) : esc_html__( 'missing', 's5' ); ?></span></div>
					<div class="ds-item"><span class="ds-k"><?php esc_html_e( 'Environment', 's5' ); ?></span><span class="ds-v"><select class="select ds-env"><option><?php esc_html_e( 'Production', 's5' ); ?></option><option><?php esc_html_e( 'Staging', 's5' ); ?></option></select></span></div>
					<a class="deploy-btn" href="<?php echo esc_url( $rebuild ); ?>"><i class="fa-solid fa-rocket"></i> <?php esc_html_e( 'Recompile now', 's5' ); ?></a>
				</div>
				<div class="switch-row" style="border-top:1px solid var(--s5e-line);padding-top:14px;margin-top:6px">
					<div class="switch-label"><?php esc_html_e( 'Validate against closed vocabulary on save', 's5' ); ?> <span class="sub"><?php esc_html_e( 'Blocks unknown classes before compile', 's5' ); ?></span></div>
					<button type="button" class="switch on" role="switch" aria-checked="true"><span class="knob"></span></button>
				</div>
				<div class="switch-row">
					<div class="switch-label"><?php esc_html_e( 'Strip disabled elements from output', 's5' ); ?> <span class="sub"><?php esc_html_e( '[data-disabled] never reaches the served HTML', 's5' ); ?></span></div>
					<button type="button" class="switch on" role="switch" aria-checked="true"><span class="knob"></span></button>
				</div>
				<div class="switch-row">
					<div class="switch-label"><?php esc_html_e( 'Minify & fingerprint assets on deploy', 's5' ); ?></div>
					<button type="button" class="switch" role="switch" aria-checked="false"><span class="knob"></span></button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * CSS / Tailwind screen (§3.6): the source layers — Tokens (locked), Classes, Custom —
	 * with an engine toggle (Tailwind is coming-soon). Read views of the real layers.
	 *
	 * @return void
	 */
	private static function render_css(): void {
		$dir    = trailingslashit( get_stylesheet_directory() ) . 'site/styles/';
		$custom = self::css_read_file( $dir . 'custom.css' );
		// Every layer is fully editable — the CSS screen is the design-system source and
		// the developer owns it. Each layer groups its real file(s); each file gets its own
		// editable textarea + Save (writing back to exactly that file — no lossy blob split).
		$can_edit = Roles::can( 'settings.css' );
		$layers   = array(
			'tokens'  => array(
				'tab'   => array( 'icon' => 'fa-palette', 'label' => __( 'Tokens', 's5' ) ),
				'note'  => __( 'Design-system values (colors, spacing, type scale). Editing a token updates every primitive built from it.', 's5' ),
				'files' => array( 'tokens', 'scales' ),
			),
			'classes' => array(
				'tab'   => array( 'icon' => 'fa-shapes', 'label' => __( 'Classes', 's5' ) ),
				'note'  => __( 'The class vocabulary. Pages may use any class defined here; the validator rejects classes that are not.', 's5' ),
				'files' => array( 'components', 'utilities' ),
			),
			'custom'  => array(
				'tab'   => array( 'icon' => 'fa-pen-nib', 'label' => __( 'Custom CSS', 's5' ) ),
				'note'  => __( 'The escape hatch — site-scoped CSS kept out of the validator. Use sparingly.', 's5' ),
				'files' => array( 'custom' ),
			),
		);
		// Real file contents keyed by short name (the allow-list keys).
		$file_code = array(
			'tokens'     => self::css_read_file( $dir . 'tokens.css' ),
			'scales'     => self::css_read_file( $dir . 'scales.css' ),
			'components' => self::css_read_file( $dir . 'components.css' ),
			'utilities'  => self::css_read_file( $dir . 'utilities.css' ),
			'custom'     => '' !== trim( $custom ) ? $custom : "/* Custom CSS — the escape hatch. Site-authored, NOT vocabulary.\n   Kept out of the validator; use sparingly. */\n",
		);
		?>
		<div class="screen-head css-head">
			<div>
				<h2><?php esc_html_e( 'CSS / Tailwind', 's5' ); ?></h2>
				<p><?php echo wp_kses_post( __( 'The design system source. <strong>Tokens</strong> are values, <strong>Classes</strong> are the closed vocabulary the validator enforces, <strong>Custom CSS</strong> is the escape hatch. The output engine toggle decides whether the framework emits plain CSS or Tailwind.', 's5' ) ); ?></p>
			</div>
			<?php /* ENGINE TOGGLE — CSS active; Tailwind an honest coming-soon (hold #2). */ ?>
			<div class="engine-toggle" data-s5-engine title="<?php esc_attr_e( 'Output engine', 's5' ); ?>">
				<button type="button" class="engine-btn active" data-engine="css"><?php esc_html_e( 'CSS', 's5' ); ?></button>
				<button type="button" class="engine-btn" data-engine="tailwind" disabled aria-disabled="true" title="<?php esc_attr_e( 'Tailwind output is on the roadmap.', 's5' ); ?>" style="opacity:.55;cursor:not-allowed"><?php esc_html_e( 'Tailwind · soon', 's5' ); ?></button>
			</div>
		</div>

		<?php /* LAYER TABS — Tokens · Classes · Custom CSS (all editable). */ ?>
		<div class="layer-tabs" data-s5-layer-tabs>
			<?php $ti = 0; foreach ( $layers as $key => $L ) : ?>
				<button type="button" class="layer-tab<?php echo 0 === $ti ? ' active' : ''; ?>" data-layer="<?php echo esc_attr( $key ); ?>"><i class="fa-solid <?php echo esc_attr( $L['tab']['icon'] ); ?>"></i> <?php echo esc_html( $L['tab']['label'] ); ?></button>
			<?php ++$ti; endforeach; ?>
		</div>

		<?php /* One editable panel per layer; each layer holds a Save-able form per real file. */ ?>
		<?php $pi = 0; foreach ( $layers as $key => $L ) : ?>
			<div class="css-layer-panel" data-s5-css-layer="<?php echo esc_attr( $key ); ?>"<?php echo 0 === $pi ? '' : ' hidden'; ?>>
				<p class="css-layer-note"><?php echo esc_html( $L['note'] ); ?></p>
				<?php foreach ( $L['files'] as $fkey ) : ?>
					<form class="code-panel css-file-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_CSS ); ?>">
						<input type="hidden" name="css_file" value="<?php echo esc_attr( $fkey ); ?>">
						<?php wp_nonce_field( self::ACTION_SAVE_CSS ); ?>
						<div class="code-panel-head">
							<span class="path"><i class="fa-solid fa-file-code"></i> site/styles/<?php echo esc_html( self::CSS_FILES[ $fkey ] ); ?></span>
							<button type="submit" class="button button-primary code-btn"<?php echo $can_edit ? '' : ' disabled'; ?>><i class="fa-solid fa-floppy-disk"></i> <?php esc_html_e( 'Save', 's5' ); ?></button>
						</div>
						<div class="code-body">
							<label class="screen-reader-text" for="s5-css-<?php echo esc_attr( $fkey ); ?>"><?php echo esc_html( self::CSS_FILES[ $fkey ] ); ?></label>
							<textarea id="s5-css-<?php echo esc_attr( $fkey ); ?>" class="code-area css-area" name="css_code" spellcheck="false"<?php echo $can_edit ? '' : ' readonly'; ?>><?php echo esc_textarea( $file_code[ $fkey ] ); ?></textarea>
						</div>
					</form>
				<?php endforeach; ?>
			</div>
		<?php ++$pi; endforeach; ?>

		<div class="vocab-note">
			<i class="fa-solid fa-triangle-exclamation"></i>
			<div><?php echo wp_kses_post( __( '<strong>Every layer is editable.</strong> Saving recompiles the bundle. The <strong>Classes</strong> layer is the closed vocabulary the validator enforces on page save — keep class names in lockstep with what pages actually use.', 's5' ) ); ?></div>
		</div>
		<?php
	}

	/**
	 * Read a site style file for the CSS/Tailwind screen (empty string if absent).
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	private static function css_read_file( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local declared stylesheet for display.
	}

	/**
	 * The canvas editor URL for a template/part in ?template mode.
	 *
	 * Opens the SAME editor (Editor::PAGE_SLUG) with a `template` flag — the editor's
	 * gear then shows Display & Conditions instead of Page Settings (HANDOFF §2.7/§3.3).
	 *
	 * @param string $name Part/template name.
	 * @return string
	 */
	private static function template_editor_url( string $name ): string {
		return add_query_arg(
			array(
				'page'     => Editor::PAGE_SLUG,
				'template' => $name,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A per-token inline editor: a native <details> "pencil" that reveals a real POST
	 * form to the one-door token save. No JS — the disclosure is the browser's own.
	 *
	 * @param string $name  Token name.
	 * @param string $value Current value.
	 * @return void
	 */
	private static function sg_edit_form( string $name, string $value ): void {
		?>
		<details class="s5set-tokenedit" style="margin-left:auto">
			<summary class="edit-btn pencil" title="<?php esc_attr_e( 'Edit token', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Edit token', 's5' ); ?>" style="list-style:none;cursor:pointer"><i class="fa-solid fa-pen"></i></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;align-items:center;margin-top:6px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_TOKEN ); ?>">
				<input type="hidden" name="token_name" value="<?php echo esc_attr( $name ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE_TOKEN ); ?>
				<input type="text" name="token_value" value="<?php echo esc_attr( $value ); ?>" class="token-hex-input" spellcheck="false">
				<button type="submit" class="edit-btn save" style="width:auto;padding:0 10px"><i class="fa-solid fa-check"></i></button>
			</form>
		</details>
		<?php
	}

	/**
	 * Render a one-shot success/error notice passed back via redirect query args.
	 *
	 * @return void
	 */
	private static function maybe_render_oneshot_notice(): void {
		if ( ! isset( $_GET['s5_notice'], $_GET['s5_notice_text'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
			return;
		}
		$type  = sanitize_key( wp_unslash( (string) $_GET['s5_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		$text  = sanitize_text_field( wp_unslash( (string) $_GET['s5_notice_text'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		// A bare WP .notice renders in the normal admin flow — but .s5set-page is a
		// position:fixed layer covering the viewport, so the notice paints BEHIND the
		// app and the user never sees it (reads as "saved with no feedback"). Emit it
		// as a self-dismissing toast pinned INSIDE the app's stacking context instead.
		$kind = in_array( $type, array( 'success', 'warning', 'error' ), true ) ? $type : 'success';
		printf(
			'<div class="s5set-toast %s" role="status" data-s5-toast><i class="fa-solid %s"></i><span>%s</span></div>',
			esc_attr( $kind ),
			esc_attr( 'success' === $kind ? 'fa-circle-check' : ( 'warning' === $kind ? 'fa-triangle-exclamation' : 'fa-circle-xmark' ) ),
			esc_html( $text )
		);
	}

	/**
	 * The light specimen: real primitives rendered with the real --s5-* tokens so a
	 * non-dev sees what the design system produces (Client-First style, HANDOFF §3.4).
	 * The specimen inlines the token stylesheet so it renders with real values inside
	 * the dark admin without leaking those tokens to the rest of wp-admin.
	 *
	 * @return void
	 */
	private static function sg_specimen(): void {
		?>
		<div class="specimen">
			<div class="specimen-bar"><span class="dot"></span> <?php esc_html_e( 'Live specimen · rendered from the tokens on the left', 's5' ); ?></div>
			<div class="specimen-body">

				<div class="specimen-section">
					<div class="sg-tag"><?php esc_html_e( 'Type ramp', 's5' ); ?></div>
					<div class="type-ramp">
						<p class="eyebrow"><?php esc_html_e( 'Eyebrow · label', 's5' ); ?></p>
						<h1><?php esc_html_e( 'Heading 1 — the page title', 's5' ); ?></h1>
						<h2><?php esc_html_e( 'Heading 2 — a section', 's5' ); ?></h2>
						<h3><?php esc_html_e( 'Heading 3 — a subsection', 's5' ); ?></h3>
						<h4><?php esc_html_e( 'Heading 4 — a group', 's5' ); ?></h4>
						<p class="lede"><?php esc_html_e( 'Lede paragraph — the opening line that sets up the page in a slightly larger, softer voice.', 's5' ); ?></p>
						<p><?php esc_html_e( 'Body copy. The workhorse paragraph size, tuned for reading. Humans & agents author the same canonical HTML, so what you approve is exactly what ships.', 's5' ); ?></p>
						<small><?php esc_html_e( 'Small print / captions and meta.', 's5' ); ?></small>
					</div>
				</div>

				<div class="specimen-section">
					<div class="sg-tag"><?php esc_html_e( 'Buttons', 's5' ); ?></div>
					<div class="sg-btns">
						<span class="btn btn--primary"><?php esc_html_e( 'Primary', 's5' ); ?></span>
						<span class="btn btn--strong"><?php esc_html_e( 'Strong', 's5' ); ?></span>
						<span class="btn btn--accent"><?php esc_html_e( 'Accent', 's5' ); ?></span>
						<span class="btn btn--ghost"><?php esc_html_e( 'Ghost', 's5' ); ?></span>
					</div>
					<div class="sg-btns" style="margin-top:12px">
						<span class="btn btn--primary btn--sm"><?php esc_html_e( 'Small', 's5' ); ?></span>
						<span class="btn btn--primary"><?php esc_html_e( 'Default', 's5' ); ?></span>
						<span class="btn btn--primary btn--lg"><?php esc_html_e( 'Large', 's5' ); ?></span>
					</div>
				</div>

				<div class="specimen-section">
					<div class="sg-tag"><?php esc_html_e( 'Cards', 's5' ); ?></div>
					<div class="sg-cards">
						<div class="sg-card"><h4><?php esc_html_e( 'Strategy', 's5' ); ?></h4><p><?php esc_html_e( 'Shortest path from goal to running system.', 's5' ); ?></p></div>
						<div class="sg-card"><h4><?php esc_html_e( 'Automation', 's5' ); ?></h4><p><?php esc_html_e( 'Agent workflows for the repetitive build.', 's5' ); ?></p></div>
						<div class="sg-card"><h4><?php esc_html_e( 'Delivery', 's5' ); ?></h4><p><?php esc_html_e( 'Production sites on infra you own.', 's5' ); ?></p></div>
					</div>
				</div>

				<div class="specimen-section">
					<div class="sg-tag"><?php esc_html_e( 'Color roles', 's5' ); ?></div>
					<div class="sg-swatches">
						<div class="sg-swatch" style="background:var(--s5-color-brand)"><?php esc_html_e( 'brand', 's5' ); ?></div>
						<div class="sg-swatch" style="background:var(--s5-color-brand-strong)"><?php esc_html_e( 'strong', 's5' ); ?></div>
						<div class="sg-swatch" style="background:var(--s5-color-accent);color:#1e1e1e;text-shadow:none"><?php esc_html_e( 'accent', 's5' ); ?></div>
						<div class="sg-swatch" style="background:var(--s5-color-ink)"><?php esc_html_e( 'ink', 's5' ); ?></div>
						<div class="sg-swatch" style="background:var(--s5-color-muted)"><?php esc_html_e( 'muted', 's5' ); ?></div>
					</div>
				</div>

				<div class="specimen-section">
					<div class="sg-tag"><?php esc_html_e( 'Badges & status', 's5' ); ?></div>
					<div class="sg-badges">
						<span class="badge badge--success"><?php esc_html_e( 'Success', 's5' ); ?></span>
						<span class="badge badge--warning"><?php esc_html_e( 'Warning', 's5' ); ?></span>
						<span class="badge badge--danger"><?php esc_html_e( 'Danger', 's5' ); ?></span>
						<span class="badge badge--muted"><?php esc_html_e( 'Muted', 's5' ); ?></span>
					</div>
				</div>

				<div class="specimen-section">
					<div class="sg-tag"><?php esc_html_e( 'Form inputs', 's5' ); ?></div>
					<div class="sg-inputs">
						<input class="sg-input" placeholder="<?php esc_attr_e( 'Text input', 's5' ); ?>">
						<input class="sg-input" value="<?php esc_attr_e( 'Filled value', 's5' ); ?>">
					</div>
				</div>

			</div>
		</div>
		<?php
	}
}
