<?php
/**
 * S5 Framework plugin bootstrap.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the framework into WordPress.
 *
 * Step 0 scope: register the `wp s5` CLI namespace so the environment can be
 * proven end to end (a real WP-CLI command runs against real WordPress). No
 * storage, compiler, importer, or render behavior exists yet — those arrive in
 * later Build Order steps and hook in through this same class.
 */
final class Plugin {

	/**
	 * Whether boot() has already run in this request.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boot the framework. Idempotent.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		self::register_cli_commands();
	}

	/**
	 * Register WP-CLI commands under the `s5` namespace.
	 *
	 * Guarded by the WP_CLI constant so it is a no-op on web requests.
	 *
	 * @return void
	 */
	private static function register_cli_commands(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// `wp s5 hello` — Step 0 smoke command proving CLI wiring.
		\WP_CLI::add_command( 's5 hello', Cli\HelloCommand::class );
	}
}
