<?php
/**
 * S5 Framework — frontend interactive islands (Build Order Step 10).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The interaction-island convention: a page opts a component into client-side
 * behavior with `<div data-s5-island="name">…</div>`. Each island name maps to a
 * vanilla ES module at `s5-theme/islands/<name>.js`.
 *
 * SIDECAR DISCOVERY: the compiler records which islands a page's render contains
 * (`_s5_islands` meta); at request time only THOSE modules are enqueued. A static
 * page (no islands) ships ZERO interaction JS. Modules load as native ES modules
 * (`type="module"`, deferred by spec), and the markup is meaningful without them
 * (progressive enhancement). The server stays authoritative for persisted data;
 * islands hold only ephemeral UI state seeded from server-rendered markup.
 *
 * Vanilla ES modules are used here for the smallest proof; the same discovery
 * seam can enqueue WordPress Interactivity API / Script Modules where richer.
 */
final class Islands {

	/**
	 * Meta key: the list of island names present in a page's compiled render.
	 *
	 * @var string
	 */
	public const META_KEY = '_s5_islands';

	/**
	 * Register the front-end enqueue hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_for_page' ) );
		// Native ES modules: mark our island scripts type="module".
		add_filter( 'script_loader_tag', array( self::class, 'as_module' ), 10, 3 );
	}

	/**
	 * Detect the island names referenced in a compiled render fragment.
	 *
	 * @param string $html Compiled render HTML.
	 * @return array<int, string> Unique island names, in first-seen order.
	 */
	public static function detect( string $html ): array {
		if ( false === stripos( $html, 'data-s5-island' ) ) {
			return array();
		}
		if ( ! preg_match_all( '/data-s5-island=("|\')([a-z0-9][a-z0-9_-]*)\1/i', $html, $m ) ) {
			return array();
		}
		$names = array();
		foreach ( $m[2] as $name ) {
			$name = strtolower( $name );
			if ( ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Store the detected island names for a page.
	 *
	 * @param int                $post_id Page ID.
	 * @param array<int, string> $names   Island names.
	 * @return void
	 */
	public static function set_for_page( int $post_id, array $names ): void {
		update_post_meta( $post_id, self::META_KEY, array_values( $names ) );
	}

	/**
	 * The island names stored for a page.
	 *
	 * @param int $post_id Page ID.
	 * @return array<int, string>
	 */
	public static function get_for_page( int $post_id ): array {
		$names = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $names ) ? $names : array();
	}

	/**
	 * Enqueue only the island modules a framework page actually contains.
	 *
	 * @return void
	 */
	public static function enqueue_for_page(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! Storage::is_framework_page( (int) $post_id ) ) {
			return;
		}

		$islands = self::get_for_page( (int) $post_id );
		if ( empty( $islands ) ) {
			return; // Static page: zero interaction JS.
		}

		$theme_dir = get_stylesheet_directory();
		$theme_uri = get_stylesheet_directory_uri();
		foreach ( $islands as $name ) {
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', (string) $name ) ) {
				continue;
			}
			$rel  = 'islands/' . $name . '.js';
			$path = $theme_dir . '/' . $rel;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			wp_enqueue_script(
				's5-island-' . $name,
				$theme_uri . '/' . $rel,
				array(),
				(string) filemtime( $path ),
				true
			);
		}
	}

	/**
	 * Emit our island scripts as native ES modules (`type="module"`).
	 *
	 * @param string $tag    The script tag.
	 * @param string $handle The script handle.
	 * @param string $src    The script src.
	 * @return string
	 */
	public static function as_module( $tag, $handle, $src ) {
		if ( is_string( $handle ) && 0 === strpos( $handle, 's5-island-' ) ) {
			// This is the `script_loader_tag` filter — the sanctioned WP seam for
			// rewriting an ALREADY-enqueued script's tag (the script is registered
			// via wp_enqueue_script in enqueue_for_page). We rewrite it to a native
			// ES module tag; this is not a fresh manual script injection.
			return '<script type="module" src="' . esc_url( $src ) . '" id="' . esc_attr( $handle ) . '-js"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- rewriting an enqueued script's tag to type="module" via the script_loader_tag filter.
		}
		return $tag;
	}
}
