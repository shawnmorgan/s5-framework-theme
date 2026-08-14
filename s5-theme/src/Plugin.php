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
 * proven end to end (a real WP-CLI command runs against real WordPress).
 *
 * Step 1 scope: register canonical-HTML storage (a page post-meta key) and the
 * render path that serves that stored fragment through a plugin-owned canvas
 * template, bypassing the theme and Gutenberg. Importer, compiler, media, and
 * the canonical/render split arrive in later Build Order steps and hook in
 * through this same class.
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

		Storage::register();
		Render::register();
		Revisions::register();
		Islands::register();
		Admin::register();
		Settings::register();
		Editor::register();
		PageAdmin::register();
		Appearance::register();
		Integrations::register();
		Redirects::register();
		Rest::register();
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

		// `wp s5 init` — scaffold the theme's site/ subtree (single-theme, D14/D15).
		\WP_CLI::add_command( 's5 init', Cli\InitCommand::class );

		// `wp s5 import` — Step 2: import a directory of HTML docs into pages.
		\WP_CLI::add_command( 's5 import', Cli\ImportCommand::class );

		// `wp s5 validate` — Step 4: Authoring Contract validation.
		\WP_CLI::add_command( 's5 validate', Cli\ValidateCommand::class );

		// `wp s5 recompile` — Step 5: regenerate derived render + mirror.
		\WP_CLI::add_command( 's5 recompile', Cli\RecompileCommand::class );

		// `wp s5 component` — Step 11: register/list/insert registered components.
		\WP_CLI::add_command( 's5 component', Cli\ComponentCommand::class );
	}
}
