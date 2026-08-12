<?php
/**
 * S5 Framework — class-vocabulary source (Authoring Contract Part A).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses the CLOSED class vocabulary out of the theme stylesheet(s) declared in
 * s5.config.json (`styles.vocabulary`). The vocabulary is the UNION of class
 * selectors found across those files — there is no hardcoded class list. This is
 * the single source of truth the Authoring Contract validator checks pages
 * against (Build Order Step 4).
 */
final class Stylesheet {

	/**
	 * Load the class vocabulary declared in s5.config.json.
	 *
	 * @param string $repo_root Absolute path to the repo root (parent of the plugin).
	 * @return array{classes: array<string, true>, files: array<int, string>, missing: array<int, string>}
	 *         `classes` is a set (class name => true); `files` are the stylesheets
	 *         actually read; `missing` are declared paths that did not exist.
	 *
	 * @throws \RuntimeException If s5.config.json is absent or declares no styles.
	 */
	public static function load_vocabulary( string $repo_root ): array {
		$config_path = $repo_root . '/s5.config.json';
		if ( ! is_readable( $config_path ) ) {
			throw new \RuntimeException( 's5.config.json not found at ' . esc_html( $config_path ) );
		}

		$raw    = (string) file_get_contents( $config_path );
		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) {
			throw new \RuntimeException( 's5.config.json is not valid JSON' );
		}

		$declared = array();
		if ( isset( $config['styles']['vocabulary'] ) && is_array( $config['styles']['vocabulary'] ) ) {
			$declared = $config['styles']['vocabulary'];
		}
		if ( empty( $declared ) ) {
			throw new \RuntimeException( 's5.config.json declares no styles.vocabulary stylesheets' );
		}

		$classes = array();
		$files   = array();
		$missing = array();

		foreach ( $declared as $rel ) {
			$rel  = (string) $rel;
			$path = $repo_root . '/' . ltrim( $rel, '/' );
			if ( ! is_readable( $path ) ) {
				$missing[] = $rel;
				continue;
			}
			$files[] = $rel;
			foreach ( self::parse_classes( (string) file_get_contents( $path ) ) as $class ) {
				$classes[ $class ] = true;
			}
		}

		// D18: every registered component's style.css EXTENDS the closed vocabulary.
		// A self-contained component ships its markup AND its licensed class names, so
		// an inserted component using its own scoped classes validates. The closed
		// guarantee holds — the vocabulary is still a finite union (declared files +
		// registered components), nothing outside it passes.
		try {
			foreach ( ComponentRegistry::css_paths() as $name => $css_path ) {
				$files[] = 'components/' . $name . '/' . basename( $css_path );
				foreach ( self::parse_classes( (string) file_get_contents( $css_path ) ) as $class ) {
					$classes[ $class ] = true;
				}
			}
		} catch ( \RuntimeException $e ) {
			// No components dir yet (fresh site) — vocabulary is just the declared files.
			unset( $e );
		}

		return array(
			'classes' => $classes,
			'files'   => $files,
			'missing' => $missing,
		);
	}

	/**
	 * Extract every class selector token from a CSS string.
	 *
	 * Strategy: strip comments, then strip declaration blocks ({...}) so property
	 * values (which can contain `.5rem`, colors, etc.) never leak in, then match
	 * class selectors `.name` in what remains (the selector text). Class names
	 * follow the CSS ident grammar (letters, digits, `-`, `_`, escapes); we accept
	 * the practical subset `[A-Za-z_-][A-Za-z0-9_-]*`, which covers the Authoring
	 * Contract grammar (block/element/modifier/utility/scale).
	 *
	 * @param string $css CSS source.
	 * @return array<int, string> Distinct class names (without the leading dot).
	 */
	public static function parse_classes( string $css ): array {
		// 1. Remove /* ... */ comments.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		// 2. Remove declaration blocks so values can't be mistaken for selectors.
		$css = (string) preg_replace( '/\{[^{}]*\}/s', ' ', $css );
		// Run again to catch simple nesting (at-rule wrappers, etc.).
		$css = (string) preg_replace( '/\{[^{}]*\}/s', ' ', $css );

		// 3. What remains is selector text (plus any leftover at-rule prelude).
		$found = array();
		if ( preg_match_all( '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $css, $m ) ) {
			foreach ( $m[1] as $name ) {
				$found[ $name ] = true;
			}
		}

		return array_keys( $found );
	}
}
