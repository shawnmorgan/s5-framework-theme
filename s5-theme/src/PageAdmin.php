<?php
/**
 * S5 Framework — page create/convert admin UI (no CLI).
 *
 * Gives a plain, uploaded-and-activated theme the two entry points a WordPress
 * user expects: make a NEW S5 page, and turn an EXISTING page into an S5 page —
 * both from the admin, both landing straight in the S5 editor. Without this, the
 * editor only ever attaches to CLI-imported pages (a chicken-and-egg: the editor
 * edits framework pages, but nothing in the UI creates one).
 *
 * All writes go through {@see Seeder} (which uses the one save door). Every
 * mutating action is a POST to admin-post.php, capability- + nonce-guarded.
 *
 * @package S5
 */

declare(strict_types=1);

namespace S5;

/**
 * Admin UI: create a new S5 page, or convert an existing page to S5.
 */
final class PageAdmin {

	/**
	 * Capability required to create/convert pages (same as editing pages).
	 */
	public const CAPABILITY = 'edit_pages';

	/**
	 * Admin-post action name: create a new S5 page.
	 */
	public const ACTION_CREATE = 's5_create_page';

	/**
	 * Admin-post action name: convert an existing page to S5.
	 */
	public const ACTION_CONVERT = 's5_convert_page';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'page_row_actions', array( self::class, 'add_convert_row_action' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION_CREATE, array( self::class, 'handle_create' ) );
		add_action( 'admin_post_' . self::ACTION_CONVERT, array( self::class, 'handle_convert' ) );
		add_action( 'admin_notices', array( self::class, 'maybe_render_notice' ) );
	}

	/**
	 * Add a "Take over with S5" row action to pages that are NOT yet framework pages.
	 * (Framework pages already get "Edit with S5" from {@see Editor}.)
	 *
	 * Phase A menu contract: there is no "New S5 Page" screen — new pages are created
	 * with WordPress's own Pages → Add New; a page becomes an S5 page by being taken
	 * over here (the adopt flow), which wraps its current content as canonical and
	 * opens it in the S5 editor. The create handler ({@see self::ACTION_CREATE}) is
	 * retained for programmatic use (seeding), just not surfaced as a menu item.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_Post              $post    The row's post.
	 * @return array<string, string>
	 */
	public static function add_convert_row_action( $actions, $post ) {
		if ( ! ( $post instanceof \WP_Post ) || 'page' !== $post->post_type ) {
			return $actions;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $actions;
		}
		if ( Storage::is_framework_page( (int) $post->ID ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_CONVERT,
					'post'   => (int) $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_CONVERT . '_' . (int) $post->ID
		);

		$actions['s5_convert'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Take over with S5', 's5' )
		);

		return $actions;
	}

	/**
	 * Handle the create POST: make a new S5 page and redirect into the editor.
	 *
	 * @return void
	 */
	public static function handle_create(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to create pages.', 's5' ) );
		}
		check_admin_referer( self::ACTION_CREATE );

		$title = isset( $_POST['s5_page_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['s5_page_title'] ) ) : '';

		$result = Seeder::create_page( $title );
		if ( ! $result['id'] ) {
			self::redirect_with_notice(
				admin_url( 'edit.php?post_type=page' ),
				'error',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not create the page: %s', 's5' ),
					$result['error']
				)
			);
		}

		wp_safe_redirect( Editor::editor_url( $result['id'] ) );
		exit;
	}

	/**
	 * Handle the convert request: convert an existing page and open the editor.
	 *
	 * @return void
	 */
	public static function handle_convert(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to edit pages.', 's5' ) );
		}
		$post_id = isset( $_REQUEST['post'] ) ? (int) $_REQUEST['post'] : 0;
		check_admin_referer( self::ACTION_CONVERT . '_' . $post_id );

		if ( ! $post_id ) {
			self::redirect_with_notice(
				admin_url( 'edit.php?post_type=page' ),
				'error',
				__( 'No page specified to convert.', 's5' )
			);
		}

		$result = Seeder::convert_page( $post_id );
		if ( ! $result['ok'] ) {
			self::redirect_with_notice(
				admin_url( 'edit.php?post_type=page' ),
				'error',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not convert the page: %s', 's5' ),
					$result['error']
				)
			);
		}

		wp_safe_redirect( Editor::editor_url( $post_id ) );
		exit;
	}

	/**
	 * Redirect back to an admin URL carrying a one-shot notice, then exit.
	 *
	 * @param string $url     Base admin URL.
	 * @param string $type    'error' | 'success'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	private static function redirect_with_notice( string $url, string $type, string $message ): void {
		$url = add_query_arg(
			array(
				's5_notice'      => rawurlencode( $type ),
				's5_notice_text' => rawurlencode( $message ),
			),
			$url
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render a one-shot admin notice passed via the redirect query args.
	 *
	 * @return void
	 */
	public static function maybe_render_notice(): void {
		if ( ! isset( $_GET['s5_notice'], $_GET['s5_notice_text'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only, no state change.
			return;
		}
		$type  = sanitize_key( wp_unslash( (string) $_GET['s5_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		$text  = sanitize_text_field( wp_unslash( (string) $_GET['s5_notice_text'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only.
		$class = ( 'success' === $type ) ? 'notice-success' : 'notice-error';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}
}
