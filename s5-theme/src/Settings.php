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
					<h2><?php echo esc_html( self::SCREENS[ $current ] ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'This screen is being wired to the design contract. The sub-nav and routing are live; the screen’s controls land in Phase C.', 's5' ); ?>
					</p>
				</section>
			</div>
		</div>
		<?php
	}
}
