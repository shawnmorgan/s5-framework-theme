<?php
/**
 * Plugin Name:       S5 Framework
 * Plugin URI:        https://series5technology.com
 * Description:        Companion plugin for the Agent-Native WordPress Framework ("S5"): canonical storage, compiler/importer, and the `wp s5` CLI. Step 0 ships only the bootstrap and a `wp s5 hello` smoke command.
 * Version:           0.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shawn Morgan
 * Author URI:        https://series5technology.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       s5
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Absolute path to this plugin's directory, trailing-slashed.
 *
 * @var string
 */
const S5_PLUGIN_DIR = __DIR__ . '/';

/**
 * Public URL to this plugin's directory, trailing-slashed. Used to enqueue the
 * editor's admin-only assets (Build Order Step 12 — Editor MVP).
 *
 * @var string
 */
define( 'S5\\S5_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Locate the Composer autoloader. It maps the `S5\` namespace to `src/`
 * (see composer.json). If missing, `composer install` was not run; fail loudly
 * in WP-CLI and admin rather than white-screening.
 */
$s5_autoload = S5_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! is_readable( $s5_autoload ) ) {
	$s5_message = 'S5 Framework: Composer autoloader not found. Run `composer install` in the plugin directory.';

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::error( $s5_message );
	}

	add_action(
		'admin_notices',
		static function () use ( $s5_message ): void {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $s5_message ) );
		}
	);

	return;
}

require $s5_autoload;

// Boot the framework. Plugin::boot() is idempotent and safe to call once here.
Plugin::boot();
