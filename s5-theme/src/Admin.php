<?php
/**
 * S5 Framework — top-level admin surface, Setup/Status, notices, adoption.
 *
 * A theme installed from a zip must be a real, discoverable product in wp-admin —
 * never a dead end. This class owns:
 *
 * - The top-level **S5** menu (Editor · Style · Components · Setup / Status).
 * - The **Setup / Status** screen: environment checks (vendor, config, compiled
 *   CSS bundle, seeded corpus) and the adoption path for existing content.
 * - **Admin notices** that name exactly what is missing and the single action that
 *   fixes it (compile styles / run setup) — nothing bails silently.
 * - **Adoption**: wrap an existing non-framework page's content as canonical and
 *   open it in the S5 editor (per-page + bulk), reversible via native revisions.
 * - **On-demand rebuild** of the compiled CSS bundle.
 *
 * The Editor screen, "New S5 Page", and "Convert to S5" row action are owned by
 * {@see Editor} and {@see PageAdmin}; this class re-parents their entries under the
 * S5 top-level menu and adds what a real theme needs around them.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The S5 top-level admin menu, Setup/Status screen, notices, and adoption path.
 */
final class Admin {

	/**
	 * Capability required for the S5 admin surface.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Top-level menu / Setup screen slug.
	 */
	public const MENU_SLUG = 's5';

	/**
	 * Admin-post action: rebuild the compiled CSS bundle.
	 */
	public const ACTION_REBUILD = 's5_rebuild_styles';

	/**
	 * Admin-post action: adopt one or more existing pages into S5.
	 */
	public const ACTION_ADOPT = 's5_adopt_pages';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 9 );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
		add_action( 'admin_post_' . self::ACTION_REBUILD, array( self::class, 'handle_rebuild' ) );
		add_action( 'admin_post_' . self::ACTION_ADOPT, array( self::class, 'handle_adopt' ) );
	}

	/**
	 * Register the top-level S5 menu and its children.
	 *
	 * Priority 9 (before Editor/PageAdmin at default 10) so the parent exists when
	 * their submenu pages attach to it.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'S5', 's5' ),
			__( 'S5', 's5' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_setup_screen' ),
			'dashicons-editor-code',
			3
		);

		// Setup / Status is the landing child (same callback as the parent).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Setup & Status', 's5' ),
			__( 'Setup & Status', 's5' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_setup_screen' )
		);

		// Theme Settings — the settings app (Phase C). Style, Components, Templates,
		// Integrations, CSS/Tailwind, and Roles all live INSIDE this app as screens
		// (routed via ?screen=…), NOT as separate top-level submenus and NOT into the
		// canvas. Per HANDOFF §3 + the Phase A menu contract, the S5 menu is exactly
		// two items: Setup & Status and Theme Settings.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Theme Settings', 's5' ),
			__( 'Theme Settings', 's5' ),
			Settings::CAPABILITY,
			Settings::MENU_SLUG,
			array( Settings::class, 'render_screen' )
		);
	}

	// --- Status model ---

	/**
	 * Compute the current environment/status of the framework install.
	 *
	 * @return array{
	 *   vendor:bool, config:bool, bundle:bool, seeded:bool,
	 *   framework_pages:int, adoptable:\WP_Post[]
	 * }
	 */
	public static function status(): array {
		$theme  = rtrim( (string) get_stylesheet_directory(), '/' );
		$vendor = is_readable( $theme . '/vendor/autoload.php' );
		$config = is_readable( $theme . '/site/s5.config.json' );
		$bundle = StyleBundle::exists();

		$framework = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => -1,
				'meta_key'       => '_s5_canonical_html', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin status screen.
				'fields'         => 'ids',
			)
		);

		// Adoptable = published/draft pages that are NOT framework pages.
		$adoptable = array();
		$pages     = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 50,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $pages as $p ) {
			if ( ! Storage::is_framework_page( (int) $p->ID ) ) {
				$adoptable[] = $p;
			}
		}

		return array(
			'vendor'          => $vendor,
			'config'          => $config,
			'bundle'          => $bundle,
			'seeded'          => (bool) get_option( 's5_demo_seeded', false ),
			'framework_pages' => count( $framework ),
			'adoptable'       => $adoptable,
		);
	}

	// --- Notices ---

	/**
	 * Render admin notices for anything missing, each naming the one fix action.
	 *
	 * Shown only to capable users, and suppressed on the Setup screen itself (which
	 * already presents the same actions in context).
	 *
	 * @return void
	 */
	public static function render_notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			return; // On the Setup screen; do not duplicate.
		}

		$status = self::status();

		if ( ! $status['config'] ) {
			self::notice(
				'error',
				__( 'S5: the site design system is missing (site/s5.config.json not found).', 's5' ),
				self::MENU_SLUG,
				__( 'Open S5 Setup', 's5' ),
				false
			);
			return;
		}

		if ( ! $status['bundle'] ) {
			self::notice(
				'warning',
				__( 'S5: the design system is not compiled yet, so pages render unstyled.', 's5' ),
				self::ACTION_REBUILD,
				__( 'Compile styles now', 's5' ),
				true
			);
		}
	}

	/**
	 * Print one notice with a primary action button.
	 *
	 * @param string $type    'error' | 'warning' | 'success'.
	 * @param string $message Human-readable message.
	 * @param string $target  Either an admin page slug (is_post=false) or an
	 *                        admin-post action (is_post=true).
	 * @param string $label   Button label.
	 * @param bool   $is_post Whether $target is an admin-post action.
	 * @return void
	 */
	private static function notice( string $type, string $message, string $target, string $label, bool $is_post ): void {
		$class = 'notice-' . ( 'error' === $type ? 'error' : ( 'success' === $type ? 'success' : 'warning' ) );

		if ( $is_post ) {
			$url = wp_nonce_url(
				add_query_arg( array( 'action' => $target ), admin_url( 'admin-post.php' ) ),
				$target
			);
		} else {
			$url = admin_url( 'admin.php?page=' . $target );
		}

		printf(
			'<div class="notice %s"><p><strong>%s</strong> &nbsp; <a class="button button-primary" href="%s">%s</a></p></div>',
			esc_attr( $class ),
			esc_html( $message ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	// --- Setup / Status screen ---

	/**
	 * Render the Setup / Status screen.
	 *
	 * @return void
	 */
	public static function render_setup_screen(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this screen.', 's5' ) );
		}
		$status = self::status();

		$rebuild_url = wp_nonce_url(
			add_query_arg( array( 'action' => self::ACTION_REBUILD ), admin_url( 'admin-post.php' ) ),
			self::ACTION_REBUILD
		);

		self::maybe_render_oneshot_notice();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'S5 — Setup & Status', 's5' ); ?></h1>
			<p><?php esc_html_e( 'The S5 framework serves pages authored as canonical HTML through its own render path. This screen shows the health of the install and how to adopt existing content.', 's5' ); ?></p>

			<h2><?php esc_html_e( 'Environment', 's5' ); ?></h2>
			<table class="widefat striped" style="max-width:640px">
				<tbody>
					<?php
					self::status_row( __( 'Framework code (vendor autoloader)', 's5' ), $status['vendor'] );
					self::status_row( __( 'Design system (site/s5.config.json)', 's5' ), $status['config'] );
					self::status_row(
						__( 'Compiled CSS bundle (site/dist/s5.css)', 's5' ),
						$status['bundle'],
						$status['bundle'] ? '' : sprintf(
							'<a class="button" href="%s">%s</a>',
							esc_url( $rebuild_url ),
							esc_html__( 'Compile styles now', 's5' )
						)
					);
					?>
					<tr>
						<td><?php esc_html_e( 'Framework pages', 's5' ); ?></td>
						<td colspan="2"><strong><?php echo (int) $status['framework_pages']; ?></strong></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Existing content', 's5' ); ?></h2>
			<?php if ( empty( $status['adoptable'] ) ) : ?>
				<p><?php esc_html_e( 'No non-framework pages found. New pages you create with “Add S5 Page” open directly in the S5 editor.', 's5' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %d: number of existing pages. */
						esc_html( _n( '%d existing page found — rendering via the styled fallback until adopted.', '%d existing pages found — rendering via the styled fallback until adopted.', count( $status['adoptable'] ), 's5' ) ),
						count( $status['adoptable'] )
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ADOPT ); ?>" />
					<?php wp_nonce_field( self::ACTION_ADOPT ); ?>
					<table class="widefat striped" style="max-width:640px">
						<thead>
							<tr>
								<td class="check-column"><input type="checkbox" onclick="for(const b of this.closest('table').querySelectorAll('input[name=\'s5_pages[]\']'))b.checked=this.checked" /></td>
								<th><?php esc_html_e( 'Page', 's5' ); ?></th>
								<th><?php esc_html_e( 'Action', 's5' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $status['adoptable'] as $p ) : ?>
								<tr>
									<th class="check-column"><input type="checkbox" name="s5_pages[]" value="<?php echo (int) $p->ID; ?>" /></th>
									<td><?php echo esc_html( get_the_title( $p ) ); ?></td>
									<td>
										<?php
										$one = wp_nonce_url(
											add_query_arg(
												array(
													'action'     => self::ACTION_ADOPT,
													's5_pages[]' => (int) $p->ID,
												),
												admin_url( 'admin-post.php' )
											),
											self::ACTION_ADOPT
										);
										?>
										<a href="<?php echo esc_url( $one ); ?>"><?php esc_html_e( 'Adopt into S5', 's5' ); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<?php submit_button( __( 'Adopt selected into S5', 's5' ), 'primary', 'submit', false ); ?>
						<span class="description"><?php esc_html_e( 'Adoption wraps the page’s current content as canonical HTML. It is reversible via the page’s revisions.', 's5' ); ?></span>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Print one environment status row.
	 *
	 * @param string $label  Row label.
	 * @param bool   $ok     Whether the check passes.
	 * @param string $action Optional trailing action HTML (already escaped).
	 * @return void
	 */
	private static function status_row( string $label, bool $ok, string $action = '' ): void {
		printf(
			'<tr><td>%s</td><td style="width:6em">%s</td><td>%s</td></tr>',
			esc_html( $label ),
			$ok
				? '<span style="color:#008a20;font-weight:600">&#10003; ' . esc_html__( 'OK', 's5' ) . '</span>'
				: '<span style="color:#b32d2e;font-weight:600">&#10007; ' . esc_html__( 'Missing', 's5' ) . '</span>',
			wp_kses_post( $action )
		);
	}

	/**
	 * Render a one-shot notice passed back via redirect query args.
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

	// --- Handlers ---

	/**
	 * Handle the "compile styles" action: rebuild the bundle, then redirect back.
	 *
	 * @return void
	 */
	public static function handle_rebuild(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 's5' ) );
		}
		check_admin_referer( self::ACTION_REBUILD );

		$ok  = false;
		$msg = __( 'Could not compile the design system — check that site/s5.config.json and the declared stylesheets exist.', 's5' );
		try {
			StyleBundle::build();
			$ok  = StyleBundle::exists();
			$msg = $ok ? __( 'Design system compiled. Pages are now styled.', 's5' ) : $msg;
		} catch ( \Throwable $e ) {
			$msg = sprintf(
				/* translators: %s: error message. */
				__( 'Could not compile the design system: %s', 's5' ),
				$e->getMessage()
			);
		}

		self::redirect_back( $ok ? 'success' : 'error', $msg );
	}

	/**
	 * Handle adoption: convert selected pages to framework pages.
	 *
	 * A single-page adoption opens the editor directly; a bulk adoption returns to
	 * the Setup screen with a summary notice.
	 *
	 * @return void
	 */
	public static function handle_adopt(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 's5' ) );
		}
		check_admin_referer( self::ACTION_ADOPT );

		$ids = array();
		if ( isset( $_REQUEST['s5_pages'] ) && is_array( $_REQUEST['s5_pages'] ) ) {
			$ids = array_map( 'intval', wp_unslash( $_REQUEST['s5_pages'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- intval-mapped.
			$ids = array_values( array_filter( $ids ) );
		}

		if ( empty( $ids ) ) {
			self::redirect_back( 'error', __( 'No pages were selected to adopt.', 's5' ) );
		}

		$adopted = 0;
		$failed  = 0;
		foreach ( $ids as $id ) {
			$res = Seeder::convert_page( $id );
			if ( ! empty( $res['ok'] ) ) {
				++$adopted;
			} else {
				++$failed;
			}
		}

		// Single adoption → straight into the editor.
		if ( 1 === count( $ids ) && 1 === $adopted ) {
			wp_safe_redirect( Editor::editor_url( $ids[0] ) );
			exit;
		}

		self::redirect_back(
			$failed ? 'warning' : 'success',
			sprintf(
				/* translators: 1: adopted count, 2: failed count. */
				__( 'Adopted %1$d page(s) into S5 (%2$d failed).', 's5' ),
				$adopted,
				$failed
			)
		);
	}

	/**
	 * Redirect to the Setup screen carrying a one-shot notice.
	 *
	 * @param string $type    'error' | 'success' | 'warning'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	private static function redirect_back( string $type, string $message ): void {
		$url = add_query_arg(
			array(
				's5_notice'      => rawurlencode( $type ),
				's5_notice_text' => rawurlencode( $message ),
			),
			admin_url( 'admin.php?page=' . self::MENU_SLUG )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
