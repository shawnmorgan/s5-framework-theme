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
	 * Appearance submenu slug PREFIXES to remove (parent = themes.php).
	 *
	 * WordPress registers several of these with a query string that varies by
	 * request, so an exact-match remove_submenu_page() silently no-ops. We therefore
	 * match by PREFIX against the real registered slugs:
	 *
	 * - `customize.php`      — the Customizer; registered as `customize.php?return=…`.
	 * - `site-editor.php?p=/pattern` — Patterns (modern WP; NOT edit.php?post_type=wp_block).
	 * - `site-editor.php`    — the block site editor itself (defensive; also its other tabs).
	 * - `edit.php?post_type=wp_block` — legacy Patterns list (older WP).
	 * - `font-library.php`   — the Fonts library.
	 *
	 * `themes.php` (Themes) and `theme-editor.php` (Theme File Editor) are KEPT.
	 *
	 * @var array<int, string>
	 */
	private const REMOVE_SUBMENU_PREFIXES = array(
		'customize.php',
		'site-editor.php?p=/pattern',
		'site-editor.php',
		'edit.php?post_type=wp_block',
		'font-library.php',
	);

	/**
	 * Base admin scripts (basenames) whose direct access is redirected to Themes.
	 *
	 * The Fonts library, the block site editor, and the Customizer have their own
	 * top-level scripts; a bookmarked link to any must not open. Patterns live under
	 * site-editor.php (modern) or edit.php?post_type=wp_block (legacy), both handled in
	 * {@see self::block_direct_access}.
	 *
	 * @var array<int, string>
	 */
	private const BLOCK_SCRIPTS = array(
		'customize.php',
		'site-editor.php',
		'font-library.php',
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
		global $submenu;

		// Match by PREFIX against the REAL registered slugs. WordPress registers
		// Patterns/Customize/etc. with per-request query strings (e.g.
		// `customize.php?return=…`, `site-editor.php?p=/pattern`), so an exact-slug
		// remove_submenu_page() silently misses. We read what is actually registered
		// under themes.php and remove every entry whose slug starts with a target
		// prefix — so a changed query arg can never smuggle the item back.
		if ( isset( $submenu['themes.php'] ) && is_array( $submenu['themes.php'] ) ) {
			foreach ( $submenu['themes.php'] as $entry ) {
				$slug = isset( $entry[2] ) ? (string) $entry[2] : '';
				if ( '' === $slug || 'themes.php' === $slug || 'theme-editor.php' === $slug ) {
					continue; // Never remove Themes or the Theme File Editor.
				}
				foreach ( self::REMOVE_SUBMENU_PREFIXES as $prefix ) {
					if ( 0 === strpos( $slug, $prefix ) ) {
						remove_submenu_page( 'themes.php', $slug );
						break;
					}
				}
			}
		}

		// Belt-and-braces: also try the bare prefixes directly (covers a slug that is
		// exactly the prefix, e.g. a legacy `customize.php` with no query).
		foreach ( self::REMOVE_SUBMENU_PREFIXES as $prefix ) {
			remove_submenu_page( 'themes.php', $prefix );
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
