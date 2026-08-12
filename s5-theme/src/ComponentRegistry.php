<?php
/**
 * S5 Framework — component registry (Build Order Step 11).
 *
 * A component is a registered HUMAN CAPABILITY: an HTML partial + optional CSS +
 * a manifest, living in `components/<name>/`. Registration is by presence — the
 * filesystem IS the registry (declared via `s5.config.json` → paths.components).
 *
 * Components are COPY-ON-INSERT: `wp s5 component insert` expands a component's
 * HTML into a page's canonical source ONCE, at insert time, and the component
 * stops existing as a separate abstraction — the page is then just canonical
 * HTML (Step 11 exit condition: "registered components define what humans may
 * compose WITHOUT becoming the page representation"). There is no component JSON
 * as page state, and nothing component-only ships in served output.
 *
 * This class is discovery + validation + lookup only. Insertion lives in the CLI
 * command, and the insert goes through the ONE persist door (Compiler::persist_page).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

use S5\Cli\ValidateCommand;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers and validates registered components.
 */
final class ComponentRegistry {

	/**
	 * Component HTML partial filename (required).
	 *
	 * @var string
	 */
	public const HTML_FILE = 'component.html';

	/**
	 * Component manifest filename (required).
	 *
	 * @var string
	 */
	public const MANIFEST_FILE = 'manifest.json';

	/**
	 * Component stylesheet filename (optional).
	 *
	 * @var string
	 */
	public const CSS_FILE = 'component.css';

	/**
	 * List every registered component name (sorted).
	 *
	 * A directory under components/ is a registered component iff it contains BOTH
	 * component.html and manifest.json. Anything else is ignored (not half-listed).
	 *
	 * @return array<int, string>
	 */
	public static function list(): array {
		$dir   = self::components_dir();
		$names = array();

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return $names;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( ! is_dir( $path ) || ! self::is_safe_name( $entry ) ) {
				continue;
			}
			if ( is_file( $path . '/' . self::HTML_FILE ) && is_file( $path . '/' . self::MANIFEST_FILE ) ) {
				$names[] = $entry;
			}
		}

		sort( $names );
		return $names;
	}

	/**
	 * Whether a component is registered (present + complete).
	 *
	 * @param string $name Component name.
	 * @return bool
	 */
	public static function is_registered( string $name ): bool {
		if ( ! self::is_safe_name( $name ) ) {
			return false;
		}
		$base = self::components_dir() . '/' . $name;
		return is_file( $base . '/' . self::HTML_FILE ) && is_file( $base . '/' . self::MANIFEST_FILE );
	}

	/**
	 * Load a registered component's definition.
	 *
	 * @param string $name Component name.
	 * @return array{name:string,version:string,description:string,html:string,css:string,manifest:array<string,mixed>}
	 *
	 * @throws \RuntimeException If the component is not registered or is malformed.
	 */
	public static function load( string $name ): array {
		if ( ! self::is_safe_name( $name ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'invalid component name "%s"', $name ) ) );
		}
		$base = self::components_dir() . '/' . $name;

		if ( ! self::is_registered( $name ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'component "%s" is not registered (need %s + %s in components/%s/)', $name, self::HTML_FILE, self::MANIFEST_FILE, $name ) )
			);
		}

		$html = self::read_file( $base . '/' . self::HTML_FILE );
		if ( '' === trim( $html ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'component "%s": %s is empty', $name, self::HTML_FILE ) ) );
		}

		$manifest_raw = self::read_file( $base . '/' . self::MANIFEST_FILE );
		$manifest     = json_decode( $manifest_raw, true );
		if ( ! is_array( $manifest ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'component "%s": %s is not valid JSON', $name, self::MANIFEST_FILE ) ) );
		}

		$version = isset( $manifest['version'] ) ? (string) $manifest['version'] : '';
		if ( '' === $version ) {
			throw new \RuntimeException( esc_html( sprintf( 'component "%s": manifest.json must declare a "version"', $name ) ) );
		}

		$css = '';
		if ( is_file( $base . '/' . self::CSS_FILE ) ) {
			$css = self::read_file( $base . '/' . self::CSS_FILE );
		}

		return array(
			'name'        => $name,
			'version'     => $version,
			'description' => isset( $manifest['description'] ) ? (string) $manifest['description'] : '',
			'html'        => rtrim( $html, "\r\n" ),
			'css'         => $css,
			'manifest'    => $manifest,
		);
	}

	/**
	 * Absolute path to the components directory (from paths.components, default
	 * "components").
	 *
	 * @return string
	 *
	 * @throws \RuntimeException If the directory does not exist.
	 */
	public static function components_dir(): string {
		$root   = ValidateCommand::repo_root();
		$config = json_decode( (string) self::read_file( $root . '/s5.config.json' ), true );
		$rel    = ( is_array( $config ) && isset( $config['paths']['components'] ) ) ? (string) $config['paths']['components'] : 'components';

		$abs = $root . '/' . ltrim( $rel, '/' );
		if ( ! is_dir( $abs ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 's5.config.json: components dir "%s" not found at %s', $rel, $abs ) )
			);
		}
		return rtrim( $abs, '/' );
	}

	/**
	 * A safe, path-traversal-proof component name.
	 *
	 * @param string $name Candidate name.
	 * @return bool
	 */
	public static function is_safe_name( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name );
	}

	/**
	 * Read a local file or throw.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 *
	 * @throws \RuntimeException If the file cannot be read.
	 */
	private static function read_file( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local component file; wp_remote_get is for URLs.
		$raw = is_file( $path ) ? file_get_contents( $path ) : false;
		if ( false === $raw ) {
			throw new \RuntimeException( esc_html( sprintf( 'could not read %s', $path ) ) );
		}
		return (string) $raw;
	}
}
