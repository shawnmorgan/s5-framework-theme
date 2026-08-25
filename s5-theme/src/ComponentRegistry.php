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
	 * Component stylesheet filename (optional), mission-canonical (Item 2).
	 *
	 * @var string
	 */
	public const CSS_FILE = 'style.css';

	/**
	 * Legacy component stylesheet filename, still supported as a fallback so
	 * pre-Item-2 components (the card) keep working without a rename churn.
	 *
	 * @var string
	 */
	public const CSS_FILE_LEGACY = 'component.css';

	/**
	 * Resolve a component's stylesheet path, preferring style.css, falling back to
	 * the legacy component.css. Returns '' when the component ships no CSS.
	 *
	 * @param string $base Absolute component directory.
	 * @return string Absolute CSS path, or '' if none present.
	 */
	public static function css_path( string $base ): string {
		if ( is_file( $base . '/' . self::CSS_FILE ) ) {
			return $base . '/' . self::CSS_FILE;
		}
		if ( is_file( $base . '/' . self::CSS_FILE_LEGACY ) ) {
			return $base . '/' . self::CSS_FILE_LEGACY;
		}
		return '';
	}

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
	 * Absolute stylesheet paths for every registered component that ships CSS,
	 * keyed by component name (sorted by name). Drives the compiled CSS bundle
	 * (D17) and the vocabulary extension (D18).
	 *
	 * @return array<string, string> name => absolute style.css path
	 */
	public static function css_paths(): array {
		$paths = array();
		foreach ( self::list() as $name ) {
			$css = self::css_path( self::components_dir() . '/' . $name );
			if ( '' !== $css ) {
				$paths[ $name ] = $css;
			}
		}
		return $paths;
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

		$css      = '';
		$css_path = self::css_path( $base );
		if ( '' !== $css_path ) {
			$css = self::read_file( $css_path );
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
	 * Save a component's source files (Item 6, code-first editor).
	 *
	 * Writes component.html, manifest.json, and style.css into the fenced
	 * site/components/<name>/ subtree. Validates BEFORE writing: the name must be
	 * path-safe, manifest.json must be valid JSON declaring a version, and the
	 * component dir must already exist (create is out of this cut). Existing
	 * instances already on pages are NOT changed — copy-on-insert independence
	 * (D22); the caller rebuilds the CSS bundle so style edits go live.
	 *
	 * @param string $name     Component name (must be registered/present).
	 * @param string $html     component.html content.
	 * @param string $manifest manifest.json content (raw JSON).
	 * @param string $css      style.css content.
	 * @return true
	 *
	 * @throws \RuntimeException On an invalid name, missing dir, bad manifest, or write failure.
	 */
	/**
	 * Create a NEW component from the UI: mint components/<name>/ with a valid starter
	 * component.html + manifest.json + style.css, so it is immediately registered
	 * (registration is by presence) and openable in the builder. Refuses an unsafe or
	 * already-existing name. The caller rebuilds the CSS bundle.
	 *
	 * The starter HTML is deliberately minimal and vocabulary-clean (a single card
	 * using registered classes) so the new component validates on its first save and
	 * gives the author a real, editable shape rather than a blank box.
	 *
	 * @param string $name        Component name (path-safe, not already registered).
	 * @param string $description Optional human description for the manifest.
	 * @return true
	 *
	 * @throws \RuntimeException On an invalid name, a name collision, or write failure.
	 */
	public static function create_component( string $name, string $description = '' ): bool {
		$name = trim( $name );
		if ( ! self::is_safe_name( $name ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'invalid component name "%s" — use letters, numbers, - or _', $name ) ) );
		}
		$base = self::components_dir() . '/' . $name;
		if ( is_dir( $base ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'component "%s" already exists', $name ) ) );
		}
		if ( ! wp_mkdir_p( $base ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'could not create component dir for "%s"', $name ) ) );
		}

		$desc = '' !== trim( $description ) ? trim( $description ) : sprintf( 'The %s component.', $name );

		// Starter HTML: a registered-vocabulary card with a {{ title }} prop, so the
		// new component is valid on first save and demonstrates the prop convention.
		$html = "<div class=\"card\">\n\t<h3 class=\"page-hero_title\">{{ title }}</h3>\n</div>\n";

		// Starter manifest: version + one text prop the HTML references.
		$manifest = wp_json_encode(
			array(
				'version'     => '0.1.0',
				'description' => $desc,
				'props'       => array(
					'title' => array(
						'label' => 'Title',
						'type'  => 'Text',
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		if ( false === $manifest ) {
			throw new \RuntimeException( 'could not encode the starter manifest' );
		}

		$css = sprintf( "/* %s component styles. Scoped design-system CSS lives in the CSS tab; this ships with the component. */\n", $name );

		self::write_file( $base . '/' . self::HTML_FILE, $html );
		self::write_file( $base . '/' . self::MANIFEST_FILE, rtrim( $manifest, "\n" ) . "\n" );
		self::write_file( $base . '/' . self::CSS_FILE, $css );

		return true;
	}

	public static function save_component( string $name, string $html, string $manifest, string $css ): bool {
		if ( ! self::is_safe_name( $name ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'invalid component name "%s"', $name ) ) );
		}
		$base = self::components_dir() . '/' . $name;
		if ( ! is_dir( $base ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'component "%s" does not exist', $name ) ) );
		}
		if ( '' === trim( $html ) ) {
			throw new \RuntimeException( 'component.html cannot be empty' );
		}
		$decoded = json_decode( $manifest, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'manifest.json is not valid JSON' );
		}
		if ( ! isset( $decoded['version'] ) || '' === (string) $decoded['version'] ) {
			throw new \RuntimeException( 'manifest.json must declare a non-empty "version"' );
		}

		self::write_file( $base . '/' . self::HTML_FILE, $html );
		self::write_file( $base . '/' . self::MANIFEST_FILE, rtrim( $manifest, "\n" ) . "\n" );
		// Preserve whichever css filename the component uses (style.css canonical).
		$css_path = self::css_path( $base );
		if ( '' === $css_path ) {
			$css_path = $base . '/' . self::CSS_FILE;
		}
		self::write_file( $css_path, $css );

		return true;
	}

	/**
	 * Write a local file or throw.
	 *
	 * @param string $path    Absolute path (inside the component dir).
	 * @param string $content Content to write.
	 * @return void
	 *
	 * @throws \RuntimeException On write failure.
	 */
	private static function write_file( string $path, string $content ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local component source file, admin-gated write.
		if ( false === file_put_contents( $path, $content ) ) {
			throw new \RuntimeException( esc_html( sprintf( 'could not write %s', $path ) ) );
		}
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
