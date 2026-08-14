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
	 * Capability required to reach the settings app at all.
	 *
	 * Per the roles contract (HANDOFF §4) a Client never sees the settings page;
	 * Marketer sees a reduced set of screens; Developer sees everything. The coarse
	 * gate is `manage_options` (Developer/Administrator) with per-screen role checks
	 * layered on in Phase C. Manage_options is the correct floor for Phase A.
	 */
	public const CAPABILITY = 'manage_options';

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
		'styleguide'   => 'StyleGuide',
		'templates'    => 'Templates',
		'components'   => 'Components',
		'integrations' => 'Integrations',
		'general'      => 'General',
		'css'          => 'CSS / Tailwind',
		'roles'        => 'Roles',
	);

	/**
	 * Admin-post action: save one edited design token + recompile the bundle.
	 */
	public const ACTION_SAVE_TOKEN = 's5_save_token';

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
	}

	/**
	 * Handle a token edit: update the value in the token file, then recompile the
	 * design-system bundle so the specimen + every framework page reflect it. One door
	 * for the write (Styles::update_token) + one compile (StyleBundle::build).
	 *
	 * @return void
	 */
	public static function handle_save_token(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to edit tokens.', 's5' ) );
		}
		check_admin_referer( self::ACTION_SAVE_TOKEN );

		$name  = isset( $_POST['token_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token_name'] ) ) : '';
		$value = isset( $_POST['token_value'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token_value'] ) ) : '';

		$res = Styles::update_token( $name, $value );
		if ( is_wp_error( $res ) ) {
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
		$css = S5_PLUGIN_DIR . 'assets/settings/settings.css';
		if ( is_readable( $css ) ) {
			wp_enqueue_style(
				's5-settings',
				get_stylesheet_directory_uri() . '/assets/settings/settings.css',
				array(),
				(string) filemtime( $css )
			);
		}

		// Components screen: the Component Builder driver (only on the components screen).
		$screen = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( (string) $_GET['screen'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen router.
		$js     = S5_PLUGIN_DIR . 'assets/settings/components.js';
		if ( 'components' === $screen && is_readable( $js ) ) {
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
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view Theme Settings.', 's5' ) );
		}

		$current = self::current_screen();
		?>
		<div class="wrap s5-settings" data-s5-settings>
			<h1><?php esc_html_e( 'S5 — Theme Settings', 's5' ); ?></h1>
			<?php self::maybe_render_oneshot_notice(); ?>
			<div class="s5-settings__layout">
				<nav class="s5-settings__nav" aria-label="<?php esc_attr_e( 'Theme settings screens', 's5' ); ?>">
					<ul>
						<?php foreach ( self::SCREENS as $key => $label ) : ?>
							<li>
								<a
									class="s5-settings__navlink<?php echo $key === $current ? ' is-active' : ''; ?>"
									aria-current="<?php echo $key === $current ? 'page' : 'false'; ?>"
									href="<?php echo esc_url( self::screen_url( $key ) ); ?>"
									data-s5-screen="<?php echo esc_attr( $key ); ?>"
								><?php echo esc_html( $label ); ?></a>
							</li>
						<?php endforeach; ?>
					</ul>
				</nav>
				<section class="s5-settings__screen" data-s5-screen-body="<?php echo esc_attr( $current ); ?>">
					<?php self::render_screen_body( $current ); ?>
				</section>
			</div>
		</div>
		<?php
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
		$tokens = Styles::read_tokens();
		?>
		<h2><?php esc_html_e( 'StyleGuide', 's5' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The design tokens the site is built from, read live from tokens.css / scales.css — and a light specimen of the primitives they produce.', 's5' ); ?></p>

		<div class="s5-sg">
			<div class="s5-sg__cards">
				<?php
				self::sg_color_card( __( 'Colors', 's5' ), isset( $tokens['color'] ) ? $tokens['color'] : array() );
				self::sg_scale_card( __( 'Type scale', 's5' ), isset( $tokens['text'] ) ? $tokens['text'] : array(), 'text' );
				self::sg_scale_card( __( 'Spacing', 's5' ), isset( $tokens['space'] ) ? $tokens['space'] : array(), 'space' );
				self::sg_scale_card( __( 'Radius', 's5' ), isset( $tokens['radius'] ) ? $tokens['radius'] : array(), 'radius' );
				?>
			</div>
			<?php self::sg_specimen(); ?>
		</div>
		<?php
	}

	/**
	 * A colour token card: swatch + name + value per token.
	 *
	 * @param string                                      $title  Card title.
	 * @param array<int, array{name:string,value:string}> $tokens Colour tokens.
	 * @return void
	 */
	private static function sg_color_card( string $title, array $tokens ): void {
		?>
		<div class="s5-sg__card">
			<div class="s5-sg__card-head"><?php echo esc_html( $title ); ?></div>
			<?php if ( empty( $tokens ) ) : ?>
				<p class="description"><?php esc_html_e( 'No colour tokens declared.', 's5' ); ?></p>
			<?php else : ?>
				<div class="s5-sg__swatches">
					<?php foreach ( $tokens as $t ) : ?>
						<div class="s5-sg__swatch">
							<span class="s5-sg__chip" style="background:<?php echo esc_attr( $t['value'] ); ?>"></span>
							<span class="s5-sg__name"><?php echo esc_html( str_replace( '--s5-color-', '', $t['name'] ) ); ?></span>
							<span class="s5-sg__val"><?php echo esc_html( $t['value'] ); ?></span>
							<?php self::sg_edit_form( $t['name'], $t['value'] ); ?>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
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
	private static function sg_scale_card( string $title, array $tokens, string $kind ): void {
		$prefix = '--s5-' . ( 'text' === $kind ? 'text-' : ( 'space' === $kind ? 'space-' : 'radius-' ) );
		?>
		<div class="s5-sg__card">
			<div class="s5-sg__card-head"><?php echo esc_html( $title ); ?></div>
			<?php if ( empty( $tokens ) ) : ?>
				<p class="description"><?php esc_html_e( 'No tokens declared.', 's5' ); ?></p>
			<?php else : ?>
				<?php foreach ( $tokens as $t ) : ?>
					<div class="s5-sg__row">
						<?php if ( 'text' === $kind ) : ?>
							<span class="s5-sg__type-sample" style="font-size:<?php echo esc_attr( $t['value'] ); ?>">Ag</span>
						<?php elseif ( 'space' === $kind ) : ?>
							<span class="s5-sg__space-bar" style="width:<?php echo esc_attr( $t['value'] ); ?>"></span>
						<?php else : ?>
							<span class="s5-sg__radius-box" style="border-radius:<?php echo esc_attr( $t['value'] ); ?>"></span>
						<?php endif; ?>
						<span class="s5-sg__name"><?php echo esc_html( str_replace( $prefix, '', $t['name'] ) ); ?></span>
						<span class="s5-sg__val"><?php echo esc_html( $t['value'] ); ?></span>
						<?php self::sg_edit_form( $t['name'], $t['value'] ); ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
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
		?>
		<h2><?php esc_html_e( 'Templates', 's5' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Shared template parts and the WordPress template hierarchy. Each is canonical HTML — the pencil opens it in the canvas editor, the same way you edit any page.', 's5' ); ?></p>

		<div class="s5-tpl__card">
			<div class="s5-tpl__card-head"><?php esc_html_e( 'Template Parts', 's5' ); ?></div>
			<?php if ( empty( $parts ) ) : ?>
				<p class="description"><?php esc_html_e( 'No template parts found under site/parts.', 's5' ); ?></p>
			<?php else : ?>
				<?php foreach ( $parts as $p ) : ?>
					<div class="s5-tpl__row">
						<div class="s5-tpl__row-main">
							<span class="s5-tpl__row-name"><?php echo esc_html( $p['label'] ); ?></span>
							<span class="s5-tpl__row-file"><?php echo esc_html( $p['file'] ); ?></span>
						</div>
						<span class="s5-tpl__badge"><?php esc_html_e( 'part', 's5' ); ?></span>
						<a class="button button-small s5-tpl__edit" href="<?php echo esc_url( self::template_editor_url( $p['name'] ) ); ?>">
							<?php esc_html_e( 'Edit', 's5' ); ?>
						</a>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<div class="s5-tpl__card">
			<div class="s5-tpl__card-head"><?php esc_html_e( 'Template Hierarchy', 's5' ); ?>
				<span class="s5-tpl__note"><?php esc_html_e( 'WordPress resolves top → bottom', 's5' ); ?></span>
			</div>
			<p class="description">
				<?php esc_html_e( 'The theme currently serves pages through the canvas render path and its parts. Hierarchy templates (front-page, single, archive, 404 …) register here as they are authored — each will open in the canvas in template mode, with its own display rules & conditions.', 's5' ); ?>
			</p>
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
		?>
		<h2><?php esc_html_e( 'Components', 's5' ); ?></h2>
		<p class="description"><?php esc_html_e( 'The registered component library. Open a component to edit its props, HTML, and logic — HTML is validated against the closed class vocabulary, and CSS is read-only (edit it in CSS / Tailwind).', 's5' ); ?></p>

		<div class="s5-comp__grid" data-s5-comp-grid>
			<?php foreach ( $names as $name ) : ?>
				<?php
				try {
					$c = ComponentRegistry::load( $name );
				} catch ( \RuntimeException $e ) {
					continue;
				}
				?>
				<div class="s5-comp__card">
					<div class="s5-comp__card-glyph"><?php echo esc_html( strtoupper( substr( $name, 0, 1 ) ) ); ?></div>
					<div class="s5-comp__card-main">
						<div class="s5-comp__card-name"><?php echo esc_html( $name ); ?></div>
						<div class="s5-comp__card-desc"><?php echo esc_html( (string) $c['description'] ); ?></div>
						<div class="s5-comp__card-meta">
							<span>&lt;<?php echo esc_html( $name ); ?>&gt;</span>
							<span>v<?php echo esc_html( (string) $c['version'] ); ?></span>
						</div>
					</div>
					<button type="button" class="button button-small s5-comp__open" data-s5-comp-open="<?php echo esc_attr( $name ); ?>">
						<?php esc_html_e( 'Edit', 's5' ); ?>
					</button>
				</div>
			<?php endforeach; ?>
			<?php if ( empty( $names ) ) : ?>
				<p class="description"><?php esc_html_e( 'No components registered.', 's5' ); ?></p>
			<?php endif; ?>
		</div>

		<?php /* Component Builder modal — filled + driven by components.js via the REST route. */ ?>
		<div class="s5-cb" data-s5-cb hidden>
			<div class="s5-cb__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Component Builder', 's5' ); ?>">
				<div class="s5-cb__head">
					<button type="button" class="button-link s5-cb__back" data-s5-cb-close>&larr; <?php esc_html_e( 'All components', 's5' ); ?></button>
					<span class="s5-cb__name" data-s5-cb-name></span>
				</div>
				<div class="s5-cb__body">
					<div class="s5-cb__col">
						<div class="s5-cb__panel-head"><?php esc_html_e( 'Props', 's5' ); ?></div>
						<div class="s5-cb__props" data-s5-cb-props></div>
						<div class="s5-cb__panel-head"><?php esc_html_e( 'HTML', 's5' ); ?> <span class="s5-cb__tag"><?php esc_html_e( 'vocabulary-validated', 's5' ); ?></span></div>
						<textarea class="s5-cb__code" data-s5-cb-html spellcheck="false"></textarea>
					</div>
					<div class="s5-cb__col">
						<div class="s5-cb__panel-head"><?php esc_html_e( 'Component CSS', 's5' ); ?> <span class="s5-cb__tag s5-cb__tag--ro"><?php esc_html_e( 'read-only · from components.css', 's5' ); ?></span></div>
						<textarea class="s5-cb__code s5-cb__code--ro" data-s5-cb-css readonly spellcheck="false"></textarea>
						<div class="s5-cb__panel-head"><?php esc_html_e( 'Logic (manifest)', 's5' ); ?></div>
						<textarea class="s5-cb__code" data-s5-cb-manifest spellcheck="false"></textarea>
					</div>
				</div>
				<div class="s5-cb__foot">
					<div class="s5-cb__diagnostics" data-s5-cb-diagnostics></div>
					<button type="button" class="button button-primary s5-cb__save" data-s5-cb-save><?php esc_html_e( 'Save component', 's5' ); ?></button>
				</div>
			</div>
		</div>
		<?php
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
		<details class="s5-sg__edit">
			<summary class="s5-sg__edit-toggle" title="<?php esc_attr_e( 'Edit token', 's5' ); ?>" aria-label="<?php esc_attr_e( 'Edit token', 's5' ); ?>">&#9998;</summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="s5-sg__edit-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_TOKEN ); ?>">
				<input type="hidden" name="token_name" value="<?php echo esc_attr( $name ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE_TOKEN ); ?>
				<input type="text" name="token_value" value="<?php echo esc_attr( $value ); ?>" class="s5-sg__edit-input" spellcheck="false">
				<button type="submit" class="button button-small button-primary"><?php esc_html_e( 'Save', 's5' ); ?></button>
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
		$class = 'success' === $type ? 'notice-success' : ( 'warning' === $type ? 'notice-warning' : 'notice-error' );
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $text ) );
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
		<div class="s5-sg__specimen">
			<div class="s5-sg__specimen-bar"><span class="s5-sg__dot"></span> <?php esc_html_e( 'Live specimen · real tokens', 's5' ); ?></div>
			<div class="s5-sg__specimen-body">
				<section class="s5-sg__spec-sec">
					<div class="s5-sg__spec-tag"><?php esc_html_e( 'Type', 's5' ); ?></div>
					<p class="s5-sg__eyebrow"><?php esc_html_e( 'Eyebrow', 's5' ); ?></p>
					<h1><?php esc_html_e( 'Heading one', 's5' ); ?></h1>
					<h2><?php esc_html_e( 'Heading two', 's5' ); ?></h2>
					<p class="s5-sg__lede"><?php esc_html_e( 'A lede paragraph in the site’s real type scale and ink color.', 's5' ); ?></p>
					<p><?php esc_html_e( 'Body copy rendered with the real --s5-* tokens, so what you see here is what ships.', 's5' ); ?></p>
				</section>
				<section class="s5-sg__spec-sec">
					<div class="s5-sg__spec-tag"><?php esc_html_e( 'Buttons', 's5' ); ?></div>
					<span class="s5-sg__btn s5-sg__btn--primary"><?php esc_html_e( 'Primary', 's5' ); ?></span>
					<span class="s5-sg__btn s5-sg__btn--ghost"><?php esc_html_e( 'Ghost', 's5' ); ?></span>
				</section>
			</div>
		</div>
		<?php
	}
}
