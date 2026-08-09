<?php
/**
 * `wp s5 validate` — Authoring Contract validation (Build Order Step 4).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5\Cli;

use S5\Stylesheet;
use S5\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates authored HTML pages against the Authoring Contract and reports
 * agent-readable diagnostics. Standalone (`wp s5 validate`) and reused by the
 * import gate.
 *
 * Strictness is the point: unknown classes and inline styles are ERRORS that
 * fail loudly; grammar-nonconforming (but defined) classes and numeric scale
 * steps are WARNINGS that print and proceed. There is no --force flag.
 */
final class ValidateCommand {

	/**
	 * Validate a directory of HTML documents.
	 *
	 * ## OPTIONS
	 *
	 * [<dir>]
	 * : Directory of HTML documents. Defaults to the `pages/` source dir.
	 *
	 * [--format=<format>]
	 * : Output format. `table` (default, human) or `json` (agent consumption).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp s5 validate
	 *     wp s5 validate wp-content/s5-pages --format=json
	 *
	 * @param array<int, string>    $args       Positional args.
	 * @param array<string, string> $assoc_args Associative args.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$dir    = isset( $args[0] ) ? rtrim( (string) $args[0], '/\\' ) : self::default_dir();

		if ( ! is_dir( $dir ) ) {
			\WP_CLI::error( sprintf( 'Directory not found: %s', $dir ) );
		}

		$vocab = self::load_vocabulary_or_die();

		$files = glob( $dir . '/*.html' );
		if ( empty( $files ) ) {
			\WP_CLI::warning( sprintf( 'No .html files found in %s', $dir ) );
			return;
		}
		sort( $files );

		$all_findings = array();
		$error_count  = 0;
		$warn_count   = 0;

		foreach ( $files as $file ) {
			$html     = (string) file_get_contents( $file );
			$findings = Validator::validate( $html, $vocab, basename( $file ) );
			foreach ( $findings as $f ) {
				$all_findings[] = $f;
				if ( Validator::SEVERITY_ERROR === $f['severity'] ) {
					++$error_count;
				} else {
					++$warn_count;
				}
			}
		}

		if ( 'json' === $format ) {
			self::print_json( $all_findings, count( $files ), $error_count, $warn_count );
		} else {
			self::print_table( $all_findings );
		}

		if ( $error_count > 0 ) {
			\WP_CLI::error(
				sprintf( '%d error(s), %d warning(s) across %d file(s).', $error_count, $warn_count, count( $files ) )
			);
		}

		\WP_CLI::success(
			sprintf( 'Authoring Contract: %d file(s) valid, %d warning(s).', count( $files ), $warn_count )
		);
	}

	/**
	 * Print findings as a human-readable report.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return void
	 */
	public static function print_table( array $findings ): void {
		if ( empty( $findings ) ) {
			\WP_CLI::log( 'No findings.' );
			return;
		}
		foreach ( $findings as $f ) {
			$label = ( Validator::SEVERITY_ERROR === $f['severity'] )
				? \WP_CLI::colorize( '%rERROR%n' )
				: \WP_CLI::colorize( '%yWARN %n' );

			\WP_CLI::log( sprintf( '%s %s:%d  [%s]', $label, $f['file'], $f['line'], $f['rule'] ) );
			if ( '' !== (string) $f['token'] ) {
				\WP_CLI::log( sprintf( '      token:     %s', $f['token'] ) );
			}
			\WP_CLI::log( sprintf( '      problem:   %s', $f['message'] ) );
			\WP_CLI::log( sprintf( '      permitted: %s', $f['permitted'] ) );
			if ( '' !== (string) $f['did_you_mean'] ) {
				\WP_CLI::log( sprintf( '      did you mean: %s', $f['did_you_mean'] ) );
			}
			\WP_CLI::log( sprintf( '      repair:    %s', $f['repair'] ) );
			\WP_CLI::log( '' );
		}
	}

	/**
	 * Print findings as JSON for agent consumption.
	 *
	 * @param array<int, array<string, mixed>> $findings    Findings.
	 * @param int                              $file_count  Files checked.
	 * @param int                              $error_count Errors.
	 * @param int                              $warn_count  Warnings.
	 * @return void
	 */
	private static function print_json( array $findings, int $file_count, int $error_count, int $warn_count ): void {
		$payload = array(
			'ok'       => ( 0 === $error_count ),
			'summary'  => array(
				'files'    => $file_count,
				'errors'   => $error_count,
				'warnings' => $warn_count,
			),
			'findings' => $findings,
		);
		\WP_CLI::line( (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Load the class vocabulary, or abort with a clear error.
	 *
	 * @return array<string, true>
	 */
	public static function load_vocabulary_or_die(): array {
		$v = array();
		try {
			$repo = self::repo_root();
			$v    = Stylesheet::load_vocabulary( $repo );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( 'Cannot load class vocabulary: ' . $e->getMessage() );
		}

		if ( ! empty( $v['missing'] ) ) {
			foreach ( $v['missing'] as $m ) {
				\WP_CLI::warning( sprintf( 'Declared stylesheet not found: %s', $m ) );
			}
		}
		if ( empty( $v['classes'] ) ) {
			\WP_CLI::error( 'Class vocabulary is empty — no class selectors found in the declared stylesheet(s).' );
		}

		return $v['classes'];
	}

	/**
	 * Resolve the repo root that contains s5.config.json + the theme styles.
	 *
	 * In dev the repo root is the plugin's parent. In the wp-env container the
	 * plugin and theme are NOT siblings, so the repo is mounted at
	 * wp-content/s5-repo (see .wp-env.json). Try both.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException If no candidate contains s5.config.json.
	 */
	public static function repo_root(): string {
		$candidates = array();
		if ( defined( 'S5_PLUGIN_DIR' ) ) {
			$candidates[] = rtrim( dirname( (string) S5_PLUGIN_DIR ), '/' );
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$candidates[] = rtrim( (string) WP_CONTENT_DIR, '/' ) . '/s5-repo';
		}

		foreach ( $candidates as $c ) {
			if ( is_readable( $c . '/s5.config.json' ) ) {
				return $c;
			}
		}

		throw new \RuntimeException(
			esc_html( 's5.config.json not found. Looked in: ' . implode( ', ', $candidates ) )
		);
	}

	/**
	 * Default source directory (the repo `pages/` dir), container-aware.
	 *
	 * @return string
	 */
	private static function default_dir(): string {
		try {
			return self::repo_root() . '/pages';
		} catch ( \RuntimeException $e ) {
			return defined( 'S5_PLUGIN_DIR' ) ? dirname( (string) S5_PLUGIN_DIR ) . '/pages' : 'pages';
		}
	}
}
