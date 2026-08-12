<?php
/**
 * S5 Framework — design-token reader for the editor Style tab (Phase B4).
 *
 * Parses the declared design-token stylesheets (s5.config.json ->
 * styles.designTokens) into structured custom-property groups so the editor's
 * Style tab renders live FROM THE REAL FILES — no hand-built HTML that can drift
 * (EDITOR-DESIGN.md §6, DECISIONS.md D5). Read-only: the Style tab never edits.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads design tokens from declared stylesheets.
 */
final class Styles {

	/**
	 * Read + group the design-token custom properties for the Style tab.
	 *
	 * Groups: color, text (type ramp), space, radius, font, leading, other.
	 * Each entry is [ 'name' => '--s5-…', 'value' => '…' ].
	 *
	 * @return array<string, array<int, array{name:string,value:string}>>
	 */
	public static function read_tokens(): array {
		$groups = array(
			'color'   => array(),
			'text'    => array(),
			'space'   => array(),
			'radius'  => array(),
			'font'    => array(),
			'leading' => array(),
			'other'   => array(),
		);

		foreach ( self::token_files() as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$css = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local declared stylesheet, not a remote resource.
			foreach ( self::parse_custom_props( $css ) as $prop ) {
				$groups[ self::group_for( $prop['name'] ) ][] = $prop;
			}
		}

		return $groups;
	}

	/**
	 * Resolve the absolute paths of the declared design-token stylesheets.
	 *
	 * @return array<int, string>
	 */
	private static function token_files(): array {
		$files = array();
		try {
			$repo = Cli\ValidateCommand::repo_root();
		} catch ( \RuntimeException $e ) {
			return $files;
		}

		$config_path = $repo . '/s5.config.json';
		if ( ! is_readable( $config_path ) ) {
			return $files;
		}
		$config = json_decode( (string) file_get_contents( $config_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local config file.
		if ( ! is_array( $config ) ) {
			return $files;
		}

		$declared = array();
		if ( isset( $config['styles']['designTokens'] ) && is_array( $config['styles']['designTokens'] ) ) {
			$declared = $config['styles']['designTokens'];
		}

		foreach ( $declared as $rel ) {
			$files[] = $repo . '/' . ltrim( (string) $rel, '/' );
		}
		return $files;
	}

	/**
	 * Extract `--name: value;` custom-property declarations from CSS text.
	 *
	 * @param string $css Stylesheet contents.
	 * @return array<int, array{name:string,value:string}>
	 */
	private static function parse_custom_props( string $css ): array {
		$out = array();
		if ( preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$out[] = array(
					'name'  => trim( $m[1] ),
					'value' => trim( $m[2] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Bucket a custom-property name into a Style-tab group.
	 *
	 * @param string $name Custom-property name (e.g. --s5-color-brand).
	 * @return string
	 */
	private static function group_for( string $name ): string {
		if ( false !== strpos( $name, 'color' ) ) {
			return 'color';
		}
		if ( false !== strpos( $name, 'text' ) ) {
			return 'text';
		}
		if ( false !== strpos( $name, 'space' ) ) {
			return 'space';
		}
		if ( false !== strpos( $name, 'radius' ) ) {
			return 'radius';
		}
		if ( false !== strpos( $name, 'font' ) ) {
			return 'font';
		}
		if ( false !== strpos( $name, 'leading' ) ) {
			return 'leading';
		}
		return 'other';
	}
}
