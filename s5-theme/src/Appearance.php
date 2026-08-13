<?php
/**
 * S5 Framework — Appearance menu cleanup (Phase A4).
 *
 * A block-theme WordPress installs several Appearance children that make no sense
 * for an S5 site: the site's look is the compiled design system, not the block
 * Customizer, block Patterns, or the theme Fonts library. Leaving them present
 * invites drift and confusion (a client "customizing" in a surface that S5 doesn't
 * read). This class removes them — menu entry AND direct-URL access — while keeping
 * the two Appearance tools that stay relevant: **Themes** (activate/switch) and the
 * **Theme File Editor** (raw file access for developers).
 *
 * Removal is done two ways so it is real, not cosmetic:
 * - `remove_submenu_page()` on a late `admin_menu` so the entries disappear;
 * - an `admin_init` guard that redirects a hand-typed/bookmarked URL to Themes,
 *   so the screens are unreachable even when the menu item is gone.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unregister and block the Appearance children S5 does not use.
 */
final class Appearance {

	/**
	 * Appearance submenu slugs to remove (parent = themes.php).
	 *
	 * - `edit.php?post_type=wp_block` — block Patterns / Pattern management.
	 * - `customize.php`               — the block/legacy Customizer.
	 * - `theme-editor.php` is KEPT (Theme File Editor).
	 * - `site-editor.php` covers Patterns/Templates on block themes; S5 is not a
	 *   block theme so it is typically absent, but we remove it defensively.
	 *
	 * @var array<int, string>
	 */
	private const REMOVE_SUBMENUS = array(
		'edit.php?post_type=wp_block', // Patterns.
		'customize.php',               // Customize.
		'site-editor.php',             // Editor (block-theme site editor: patterns/fonts live here).
	);

	/**
	 * Base admin scripts (basenames) whose direct access is redirected to Themes.
	 *
	 * The Fonts library and the Customizer have their own top-level scripts; a
	 * bookmarked link to them must not open. Patterns live under edit.php with a
	 * wp_block post type, handled by the query-var check in {@see self::block_direct_access}.
	 *
	 * @var array<int, string>
	 */
	private const BLOCK_SCRIPTS = array(
		'customize.php',
		'site-editor.php',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Late priority so it runs after core (and other plugins) have populated the
		// Appearance menu, otherwise there is nothing to remove yet.
		add_action( 'admin_menu', array( self::class, 'remove_menus' ), 999 );
		add_action( 'admin_init', array( self::class, 'block_direct_access' ) );
	}

	/**
	 * Remove the unwanted Appearance submenu entries.
	 *
	 * @return void
	 */
	public static function remove_menus(): void {
		foreach ( self::REMOVE_SUBMENUS as $slug ) {
			remove_submenu_page( 'themes.php', $slug );
		}
	}

	/**
	 * Redirect a direct hit on a removed Appearance screen to the Themes list.
	 *
	 * Keeps Themes (`themes.php`) and the Theme File Editor (`theme-editor.php`)
	 * reachable; everything in the block list, plus the Patterns list
	 * (`edit.php?post_type=wp_block`), redirects.
	 *
	 * @return void
	 */
	public static function block_direct_access(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		global $pagenow;
		$current = isset( $pagenow ) ? (string) $pagenow : '';

		$blocked = false;

		if ( in_array( $current, self::BLOCK_SCRIPTS, true ) ) {
			$blocked = true;
		}

		// Patterns: edit.php?post_type=wp_block.
		if ( 'edit.php' === $current ) {
			$pt = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route match.
			if ( 'wp_block' === $pt ) {
				$blocked = true;
			}
		}

		if ( $blocked ) {
			wp_safe_redirect( admin_url( 'themes.php' ) );
			exit;
		}
	}
}
