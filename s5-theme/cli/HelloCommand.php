<?php
/**
 * `wp s5 hello` — Step 0 smoke command.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proves the `wp s5` command namespace is wired to real WP-CLI.
 *
 * This command does nothing else and must not grow. It exists only so Step 0's
 * acceptance test can assert that an S5 CLI command runs against real WordPress
 * and exits 0. Real commands (`wp s5 import`, ...) arrive in later steps as
 * their own classes.
 */
final class HelloCommand {

	/**
	 * Print a fixed readiness token and exit 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp s5 hello
	 *
	 * @param array<int, string>    $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Associative args (unused).
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		\WP_CLI::line( 's5 ok' );
	}
}
