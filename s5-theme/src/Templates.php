<?php
/**
 * S5 Framework — template parts registry (Phase C §3.2/§3.3).
 *
 * The site's shared template PARTS are real HTML files under the fenced `site/parts`
 * subtree (site-header.html, site-footer.html, …). They are canonical HTML in exactly
 * the same sense as a page, so the canvas editor edits them in TEMPLATE mode — this
 * class is the shared source of truth for discovering, reading, and writing them, used
 * by both the Templates settings screen and the editor's template mode.
 *
 * Names are validated as safe part slugs and paths are confined under the parts dir
 * (no traversal), mirroring PartExpander's own safety.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discover + read/write template part files.
 */
final class Templates {

	/**
	 * Absolute parts directory, or '' if it cannot be resolved.
	 *
	 * @return string
	 */
	public static function parts_dir(): string {
		try {
			$repo = Cli\ValidateCommand::repo_root();
		} catch ( \RuntimeException $e ) {
			return '';
		}
		$rel         = 'parts';
		$config_path = $repo . '/s5.config.json';
		if ( is_readable( $config_path ) ) {
			$config = json_decode( (string) file_get_contents( $config_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local config.
			if ( is_array( $config ) && isset( $config['paths']['parts'] ) ) {
				$rel = (string) $config['paths']['parts'];
			}
		}
		$dir = $repo . '/' . ltrim( $rel, '/' );
		return is_dir( $dir ) ? rtrim( $dir, '/' ) : '';
	}

	/**
	 * The relative parts path (for display), e.g. 'parts' or 'site/parts'.
	 *
	 * @return string
	 */
	public static function parts_rel(): string {
		try {
			$repo = Cli\ValidateCommand::repo_root();
		} catch ( \RuntimeException $e ) {
			return 'parts';
		}
		$rel         = 'parts';
		$config_path = $repo . '/s5.config.json';
		if ( is_readable( $config_path ) ) {
			$config = json_decode( (string) file_get_contents( $config_path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local config.
			if ( is_array( $config ) && isset( $config['paths']['parts'] ) ) {
				$rel = (string) $config['paths']['parts'];
			}
		}
		return ltrim( $rel, '/' );
	}

	/**
	 * Whether a given name is a safe part slug (letters, digits, dash/underscore).
	 *
	 * @param string $name Candidate name.
	 * @return bool
	 */
	public static function is_safe_name( string $name ): bool {
		return (bool) preg_match( '/^[a-z0-9][a-z0-9_-]*$/i', $name );
	}

	/**
	 * The absolute file path for a part name, confined under the parts dir, or ''.
	 *
	 * @param string $name Part name (no extension).
	 * @return string
	 */
	public static function part_path( string $name ): string {
		if ( ! self::is_safe_name( $name ) ) {
			return '';
		}
		$dir = self::parts_dir();
		if ( '' === $dir ) {
			return '';
		}
		$path = $dir . '/' . $name . '.html';
		$real = realpath( $path );
		$root = realpath( $dir );
		if ( false === $real || false === $root || 0 !== strpos( $real, $root ) ) {
			return '';
		}
		return $real;
	}

	/**
	 * Whether a part file exists for the name.
	 *
	 * @param string $name Part name.
	 * @return bool
	 */
	public static function part_exists( string $name ): bool {
		$path = self::part_path( $name );
		return '' !== $path && is_file( $path );
	}

	/**
	 * The canonical HTML of a part file, or ''.
	 *
	 * @param string $name Part name.
	 * @return string
	 */
	public static function read_part( string $name ): string {
		$path = self::part_path( $name );
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local part file.
	}

	/**
	 * Write a part file's canonical HTML. Path-confined to the parts dir (no traversal);
	 * the caller is responsible for validation + any recompile.
	 *
	 * @param string $name Part name.
	 * @param string $html Canonical HTML to write.
	 * @return true|\WP_Error
	 */
	public static function write_part( string $name, string $html ) {
		$path = self::part_path( $name );
		if ( '' === $path ) {
			return new \WP_Error( 's5_part_path', __( 'Invalid template part.', 's5' ), array( 'status' => 400 ) );
		}
		if ( ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- theme-local part file.
			return new \WP_Error( 's5_part_write', __( 'Template part file is not writable.', 's5' ), array( 'status' => 500 ) );
		}
		$ok = file_put_contents( $path, $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- theme-local part file.
		if ( false === $ok ) {
			return new \WP_Error( 's5_part_write', __( 'Could not write the template part.', 's5' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * The list of parts as [name, label, file].
	 *
	 * @return array<int, array{name:string,label:string,file:string}>
	 */
	public static function list_parts(): array {
		$out = array();
		$dir = self::parts_dir();
		if ( '' === $dir ) {
			return $out;
		}
		$rel   = self::parts_rel();
		$files = glob( $dir . '/*.html' );
		if ( ! is_array( $files ) ) {
			return $out;
		}
		sort( $files );
		foreach ( $files as $file ) {
			$name  = (string) preg_replace( '/\.html$/', '', basename( (string) $file ) );
			$out[] = array(
				'name'  => $name,
				'label' => ucwords( str_replace( array( '-', '_' ), ' ', $name ) ),
				'file'  => $rel . '/' . basename( (string) $file ),
			);
		}
		return $out;
	}
}
