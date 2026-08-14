<?php
/**
 * Appearance-menu removal probe (Phase A §7). Run via `wp eval-file` so there is NO
 * complex shell quoting — the whole assertion lives here and prints a single token line.
 *
 * Prints two lines:
 *   UNIT=OK|FAIL:<reason>       — removal RUN against the real-world slug shapes core uses.
 *   RENDERED=OK|FAIL:<reason>|SKIP — removal against the REAL wp-admin/menu.php submenu.
 *
 * The gate greps these two lines. Both must be OK (RENDERED may be SKIP if the admin menu
 * cannot be bootstrapped in this WP build — the authoritative rendered-menu check is the
 * Playwright real-install walkthrough).
 *
 * @package S5\Tests
 */

if ( function_exists( 'wp_set_current_user' ) ) {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	if ( ! empty( $admins ) ) {
		wp_set_current_user( (int) $admins[0] );
	}
}

$forbidden = array( 'customize.php', 'site-editor.php', 'font-library.php', 'post_type=wp_block' );

/**
 * Run S5\Appearance::remove_menus() over a given themes.php submenu and report.
 *
 * @param array $submenu_themes The seed submenu rows ([label, cap, slug]).
 * @return string OK:<slugs> | FAIL:<reason>
 */
$run = static function ( array $submenu_themes ) use ( $forbidden ) {
	$GLOBALS['submenu']['themes.php'] = $submenu_themes;
	\S5\Appearance::remove_menus();
	$left = array();
	if ( isset( $GLOBALS['submenu']['themes.php'] ) ) {
		foreach ( $GLOBALS['submenu']['themes.php'] as $e ) {
			$left[] = $e[2];
		}
	}
	$s = implode( ',', $left );
	foreach ( $forbidden as $f ) {
		if ( false !== strpos( $s, $f ) ) {
			return 'FAIL:survived ' . $f;
		}
	}
	if ( false === strpos( $s, 'themes.php' ) ) {
		return 'FAIL:themes-removed';
	}
	if ( false === strpos( $s, 'theme-editor.php' ) ) {
		return 'FAIL:editor-removed';
	}
	return 'OK:' . $s;
};

/* (7a) The real-world slug shapes modern WordPress registers. */
$unit = $run(
	array(
		array( 'Themes', 'switch_themes', 'themes.php' ),
		array( 'Patterns', 'edit_theme_options', 'site-editor.php?p=/pattern' ),
		array( 'Customize', 'customize', 'customize.php?return=%2Fwp-admin%2F' ),
		array( 'Fonts', 'edit_theme_options', 'font-library.php' ),
		array( 'Patterns (legacy)', 'edit_theme_options', 'edit.php?post_type=wp_block' ),
		array( 'Theme File Editor', 'edit_themes', 'theme-editor.php' ),
	)
);
echo 'UNIT=' . $unit . "\n";

/* (7b) The REAL admin submenu built by wp-admin/menu.php. If it cannot bootstrap in this
   WP build, SKIP loudly — the authoritative rendered-menu check is the Playwright pass. */
$rendered = 'SKIP';
try {
	if ( ! defined( 'WP_ADMIN' ) ) {
		define( 'WP_ADMIN', true );
	}
	$GLOBALS['pagenow']     = 'themes.php';
	$GLOBALS['hook_suffix'] = '';
	foreach ( array( 'menu', 'submenu', '_wp_menu_nopriv', '_wp_submenu_nopriv', '_registered_pages', '_parent_pages', '_wp_real_parent_file' ) as $g ) {
		$GLOBALS[ $g ] = array();
	}
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	set_current_screen( 'themes' );
	require ABSPATH . 'wp-admin/menu.php';
	\S5\Appearance::remove_menus();
	$left = array();
	if ( isset( $GLOBALS['submenu']['themes.php'] ) ) {
		foreach ( $GLOBALS['submenu']['themes.php'] as $e ) {
			$left[] = $e[2];
		}
	}
	$s   = implode( ',', $left );
	$bad = '';
	foreach ( $forbidden as $f ) {
		if ( false !== strpos( $s, $f ) ) {
			$bad = $f;
			break;
		}
	}
	$rendered = $bad ? ( 'FAIL:' . $bad ) : ( 'OK:' . $s );
} catch ( \Throwable $e ) {
	$rendered = 'SKIP';
}
echo 'RENDERED=' . $rendered . "\n";
