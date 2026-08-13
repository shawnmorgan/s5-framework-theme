<?php
/**
 * S5 Framework — compiled CSS bundle (Item 2, D17).
 *
 * Concatenates the site design system + every registered component's scoped CSS
 * into ONE built stylesheet at `site/dist/s5.css`, so a single cacheable `<link>`
 * carries all of it to served framework pages. This is the "scoped CSS concatenates
 * at compile" seam: the bundle is (re)built at import and recompile, never at
 * request time, and never edited by hand.
 *
 * Order is deterministic: design tokens → scales → base vocabulary (components,
 * utilities) → each component's style.css (alphabetical by component name). Tokens
 * first so component CSS can consume the custom properties.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

use S5\Cli\ValidateCommand;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and locates the compiled CSS bundle.
 */
final class StyleBundle {

	/**
	 * Bundle path relative to the site root.
	 *
	 * @var string
	 */
	public const REL_PATH = 'dist/s5.css';

	/**
	 * Build the bundle from the current site styles + registered components.
	 *
	 * @return string Absolute path to the written bundle.
	 *
	 * @throws \RuntimeException If the site root or a declared style file is unreadable.
	 */
	public static function build(): string {
		$root   = ValidateCommand::repo_root();
		$config = json_decode( self::read( $root . '/s5.config.json' ), true );
		if ( ! is_array( $config ) ) {
			throw new \RuntimeException( 's5.config.json is not valid JSON' );
		}

		$parts = array();

		// 1. Design system, in cascade order: tokens/scales first, then vocabulary.
		$ordered = array_merge(
			isset( $config['styles']['designTokens'] ) && is_array( $config['styles']['designTokens'] ) ? $config['styles']['designTokens'] : array(),
			isset( $config['styles']['vocabulary'] ) && is_array( $config['styles']['vocabulary'] ) ? $config['styles']['vocabulary'] : array()
		);
		foreach ( $ordered as $rel ) {
			$path = $root . '/' . ltrim( (string) $rel, '/' );
			if ( is_readable( $path ) ) {
				$parts[] = "/* --- {$rel} --- */";
				$parts[] = rtrim( self::read( $path ), "\n" );
			}
		}

		// 2. Each registered component's scoped style.css (alphabetical by name).
		try {
			foreach ( ComponentRegistry::css_paths() as $name => $css_path ) {
				$parts[] = "/* --- component: {$name} --- */";
				$parts[] = rtrim( self::read( $css_path ), "\n" );
			}
		} catch ( \RuntimeException $e ) {
			unset( $e ); // No components dir yet — bundle is just the design system.
		}

		$css     = "/* Compiled by S5 (StyleBundle). Do not edit — regenerated on recompile. */\n"
			. implode( "\n", $parts ) . "\n";
		$dist    = $root . '/dist';
		$outfile = $root . '/' . self::REL_PATH;

		if ( ! is_dir( $dist ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- local build dir.
			if ( ! mkdir( $dist, 0755, true ) && ! is_dir( $dist ) ) {
				throw new \RuntimeException( esc_html( 'could not create dist dir at ' . $dist ) );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local build artifact.
		if ( false === file_put_contents( $outfile, $css ) ) {
			throw new \RuntimeException( esc_html( 'could not write CSS bundle at ' . $outfile ) );
		}

		return $outfile;
	}

	/**
	 * Absolute path where the bundle lives in the active theme's site/ subtree.
	 *
	 * @return string
	 */
	public static function path(): string {
		return rtrim( (string) get_stylesheet_directory(), '/' ) . '/site/' . self::REL_PATH;
	}

	/**
	 * Whether the compiled bundle exists on disk.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		return is_file( self::path() );
	}

	/**
	 * Ensure the compiled bundle exists, building it on demand if it is missing.
	 *
	 * A theme installed from a zip may ship without the built bundle (or a product
	 * update may leave it stale). Rather than serve unstyled pages, self-heal: build
	 * the bundle from the shipped site/ subtree the first time it is needed. Building
	 * is cheap (string concatenation of local CSS) and idempotent.
	 *
	 * Never throws: a build failure returns false so the caller can surface a notice
	 * instead of white-screening the front end.
	 *
	 * @return bool True if the bundle exists (already, or after a successful build).
	 */
	public static function ensure(): bool {
		if ( self::exists() ) {
			return true;
		}
		// Cannot build without the shipped design system. Let the caller notice.
		$site = rtrim( (string) get_stylesheet_directory(), '/' ) . '/site';
		if ( ! is_readable( $site . '/s5.config.json' ) ) {
			return false;
		}
		try {
			self::build();
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}
		return self::exists();
	}

	/**
	 * Public URL of the compiled bundle for enqueueing, or '' if not built yet.
	 *
	 * Resolves against the active theme's site/ subtree (D14). Returns '' when the
	 * bundle file is absent so the enqueue seam degrades quietly.
	 *
	 * @return string
	 */
	public static function url(): string {
		if ( ! self::exists() ) {
			return '';
		}
		return rtrim( (string) get_stylesheet_directory_uri(), '/' ) . '/site/' . self::REL_PATH;
	}

	/**
	 * Filesystem mtime of the bundle for cache-busting (0 if absent).
	 *
	 * @return int
	 */
	public static function version(): int {
		$abs = rtrim( (string) get_stylesheet_directory(), '/' ) . '/site/' . self::REL_PATH;
		return is_file( $abs ) ? (int) filemtime( $abs ) : 0;
	}

	/**
	 * Read a local file or throw.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 *
	 * @throws \RuntimeException If unreadable.
	 */
	private static function read( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local site file.
		$raw = is_file( $path ) ? file_get_contents( $path ) : false;
		if ( false === $raw ) {
			throw new \RuntimeException( esc_html( 'could not read ' . $path ) );
		}
		return (string) $raw;
	}
}
