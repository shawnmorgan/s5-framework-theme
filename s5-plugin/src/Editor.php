<?php
/**
 * S5 Framework — Editor MVP ("WordCamp cut") admin shell.
 *
 * Build Order Step 12 (scoped). Registers a single admin screen that renders a
 * live preview of a framework page in an iframe, with a top bar (viewport toggle,
 * Edit/Code lens toggle, dirty indicator, Update). The panel, REST save, code
 * lens, style tab, and L&L presentation land in later Phase B items and hook in
 * through this same class.
 *
 * Scope guarantees honored here:
 * - Gated behind `manage_options` (editing levels deferred — single user).
 * - Editor assets enqueue ONLY on this admin screen (DECISIONS.md D8) — nothing
 *   ships to logged-out visitors.
 * - The preview iframe points at the REAL canvas URL with `?s5-preview=1`
 *   (DECISIONS.md D7) — one render path, admin bar suppressed for the frame.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The admin-side editor screen for framework pages.
 */
final class Editor {

	/**
	 * Admin page slug (admin.php?page=s5-editor).
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 's5-editor';

	/**
	 * Capability required to open the editor (single-user MVP).
	 *
	 * @var string
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Query var that flags a canvas request as an in-editor preview.
	 *
	 * @var string
	 */
	public const PREVIEW_QUERY_VAR = 's5-preview';

	/**
	 * Register editor hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		// The screen itself: a hidden submenu page (no visible menu item — reached
		// from the pages-list row action and the admin bar).
		add_action( 'admin_menu', array( self::class, 'register_screen' ) );

		// Entry points into the editor.
		add_filter( 'page_row_actions', array( self::class, 'add_row_action' ), 10, 2 );
		add_action( 'admin_bar_menu', array( self::class, 'add_admin_bar_link' ), 100 );

		// Assets — enqueued on the editor screen only.
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );

		// Preview mode: when the canvas is requested with ?s5-preview=1 by a
		// logged-in editor, suppress the admin bar so the frame renders the real
		// page chrome-free; overlay/selection wiring arrives in Phase B2.
		add_action( 'template_redirect', array( self::class, 'maybe_prepare_preview' ) );
	}

	/**
	 * Register the (hidden) editor admin screen.
	 *
	 * @return void
	 */
	public static function register_screen(): void {
		add_submenu_page(
			'', // No parent, hidden from menus; reached via a direct link.
			__( 'Edit with S5', 's5' ),
			__( 'Edit with S5', 's5' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render_screen' )
		);
	}

	/**
	 * The admin URL that opens the editor for a given page.
	 *
	 * @param int $post_id Framework page ID.
	 * @return string
	 */
	public static function editor_url( int $post_id ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'post' => $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add an "Edit with S5" row action to framework pages in the pages list.
	 *
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_Post              $post    The row's post.
	 * @return array<string, string>
	 */
	public static function add_row_action( $actions, $post ) {
		if ( ! ( $post instanceof \WP_Post ) ) {
			return $actions;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return $actions;
		}
		if ( ! Storage::is_framework_page( (int) $post->ID ) ) {
			return $actions;
		}

		$actions['s5_edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::editor_url( (int) $post->ID ) ),
			esc_html__( 'Edit with S5', 's5' )
		);

		return $actions;
	}

	/**
	 * Add an "Edit with S5" node to the admin bar when viewing a framework page.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar instance.
	 * @return void
	 */
	public static function add_admin_bar_link( $bar ): void {
		if ( ! ( $bar instanceof \WP_Admin_Bar ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$post_id = 0;
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
		} elseif ( is_admin() ) {
			// On the classic page editor, the ?post= param identifies the page.
			$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin-bar link target.
		}

		if ( ! $post_id || ! Storage::is_framework_page( $post_id ) ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 's5-edit',
				'title' => __( 'Edit with S5', 's5' ),
				'href'  => self::editor_url( $post_id ),
			)
		);
	}

	/**
	 * Enqueue the editor's vanilla JS + CSS — ONLY on the editor screen.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		// add_submenu_page with an empty parent yields the hook 'admin_page_{slug}'.
		if ( 'admin_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen bootstrap; the save path is nonce-guarded in Phase B2.

		$base = constant( 'S5\\S5_PLUGIN_URL' ) . 'assets/editor/';

		wp_enqueue_style(
			's5-editor',
			$base . 'editor.css',
			array(),
			'0.0.0'
		);

		wp_enqueue_script(
			's5-editor',
			$base . 'editor.js',
			array(),
			'0.0.0',
			true
		);

		wp_localize_script(
			's5-editor',
			'S5Editor',
			array(
				'postId'     => $post_id,
				'previewUrl' => self::preview_url( $post_id ),
				'backUrl'    => $post_id ? get_edit_post_link( $post_id, 'raw' ) : admin_url( 'edit.php' ),
			)
		);
	}

	/**
	 * The canvas preview URL for a page (the real permalink + preview flag).
	 *
	 * @param int $post_id Framework page ID.
	 * @return string
	 */
	public static function preview_url( int $post_id ): string {
		if ( ! $post_id ) {
			return '';
		}
		$permalink = get_permalink( $post_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}
		return add_query_arg( self::PREVIEW_QUERY_VAR, '1', $permalink );
	}

	/**
	 * Render the editor admin screen.
	 *
	 * @return void
	 */
	public static function render_screen(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to edit framework pages.', 's5' ) );
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen bootstrap; saves are nonce-guarded (Phase B2).
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! ( $post instanceof \WP_Post ) || ! Storage::is_framework_page( $post_id ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Edit with S5', 's5' ) . '</h1>';
			echo '<p>' . esc_html__( 'Select a framework page to edit from the Pages list.', 's5' ) . '</p></div>';
			return;
		}

		// Values consumed by the template.
		$s5_editor = array(
			'post_id'     => $post_id,
			'title'       => get_the_title( $post_id ),
			'preview_url' => self::preview_url( $post_id ),
			'back_url'    => (string) get_edit_post_link( $post_id, 'raw' ),
		);

		$template = S5_PLUGIN_DIR . 'templates/editor.php';
		if ( is_readable( $template ) ) {
			include $template;
		}
	}

	/**
	 * When the canvas is requested as an in-editor preview by a logged-in editor,
	 * suppress the admin bar so the iframe shows the real page without WP chrome.
	 *
	 * The flag does nothing for anonymous visitors: it is gated on the capability,
	 * so a `?s5-preview=1` on a public request has no effect on output.
	 *
	 * @return void
	 */
	public static function maybe_prepare_preview(): void {
		if ( ! self::is_preview_request() ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// Chrome-free frame: no admin bar inside the preview.
		add_filter( 'show_admin_bar', '__return_false' );
	}

	/**
	 * Whether the current request carries the editor preview flag.
	 *
	 * @return bool
	 */
	public static function is_preview_request(): bool {
		return isset( $_GET[ self::PREVIEW_QUERY_VAR ] ) && '1' === $_GET[ self::PREVIEW_QUERY_VAR ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presentation flag; capability re-checked by the caller.
	}
}
