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
	 * Register hooks. The menu entry itself is added by {@see Admin::register_menu}
	 * (so the S5 menu stays the single owner of its children); this class owns the
	 * render + asset enqueue for the settings screen only.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
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
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
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
