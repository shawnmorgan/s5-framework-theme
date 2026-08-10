<?php
/**
 * S5 Framework — canonical HTML storage.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Framework page storage.
 *
 * Step 1 scope: a single registered post-meta key holds the authored canonical
 * body-fragment HTML for a page. A page "is a framework page" when that meta is
 * non-empty. Pages keep normal WordPress identity (permalink, title, status,
 * permissions). No importer/editor exists yet — the fragment is placed directly
 * (e.g. via WP-CLI in the acceptance test); those authoring doors arrive in
 * Steps 2/4.
 */
final class Storage {

	/**
	 * Meta key holding the canonical body-fragment HTML.
	 *
	 * Underscore-prefixed so it is hidden from the default custom-fields UI.
	 *
	 * @var string
	 */
	public const META_KEY = '_s5_canonical_html';

	/**
	 * Meta key holding the extracted head blob (title, description, canonical,
	 * OG/meta tags, and JSON-LD), stored as a single structured array.
	 *
	 * @var string
	 */
	public const HEAD_KEY = '_s5_head';

	/**
	 * Meta key holding the DERIVED render HTML (Build Order Step 5).
	 *
	 * This is the artifact the canvas serves. It is compiled from the canonical
	 * source by the transform pipeline and is deliberately disposable: never
	 * revisioned, never exported, regenerable at any time via `wp s5 recompile`.
	 * Canonical (`META_KEY`) stays the portable truth; this is environment state.
	 *
	 * @var string
	 */
	public const RENDER_KEY = '_s5_render_html';

	/**
	 * Meta key holding the media-resolution REVERSAL MAP (Build Order Step 6).
	 *
	 * An ordered list of `[resolved_bytes, original_bytes]` pairs recorded when
	 * the compiler resolved logical media refs into environment attachment markup.
	 * This is OUT-OF-BAND bookkeeping — it is NEVER emitted into the served render
	 * (the served `<img>`/`<source>` carry only runtime attributes) — so tooling
	 * (the round-trip harness) can map resolved markup back to its logical source
	 * without any framework-internal attribute shipping in the HTML. Disposable and
	 * regenerable, exactly like the render it accompanies; not revisioned.
	 *
	 * @var string
	 */
	public const MEDIA_MAP_KEY = '_s5_media_map';

	/**
	 * Post type framework pages live on in Step 1.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'page';

	/**
	 * Register the canonical-HTML meta on the page post type.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_meta' ) );
	}

	/**
	 * Register the post meta.
	 *
	 * `show_in_rest` is false in Step 1 (no editor/REST authoring yet). The
	 * auth callback gates writes behind the normal page edit capability so the
	 * page's permissions stay intact.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => null, // Canonical HTML is stored verbatim; no importer/sanitizer in Step 1.
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::HEAD_KEY,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => null, // Head artifacts are stored as extracted; no rewriting in Step 2.
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::RENDER_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => null, // Derived render is written verbatim by the compiler.
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::MEDIA_MAP_KEY,
			array(
				'type'              => 'array',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => null, // Compiler-authored bookkeeping; stored as-is.
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * The canonical HTML fragment for a post, or empty string if none.
	 *
	 * @param int $post_id Post ID.
	 * @return string Raw stored fragment (may be empty).
	 */
	public static function get_canonical_html( int $post_id ): string {
		$html = get_post_meta( $post_id, self::META_KEY, true );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Whether a post is a framework page (has a non-empty canonical fragment).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_framework_page( int $post_id ): bool {
		return '' !== self::get_canonical_html( $post_id );
	}

	/**
	 * The extracted head blob for a post.
	 *
	 * Shape (all keys optional):
	 * - `title`       string  Document title text (core emits the title tag).
	 * - `description` string  Meta description content (structured; for the head contract).
	 * - `canonical`   string  Canonical URL (structured; for the head contract).
	 * - `charset`     string  Source charset (theme emits it; recorded for drift only).
	 * - `viewport`    string  Source viewport content (theme emits it; drift only).
	 * - `passthrough` array   Ordered list of every OTHER head node's verbatim source
	 *                         (OG/Twitter meta, JSON-LD, favicon links, analytics tags,
	 *                         HTML comments, …) — emitted as-is, in source order.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Head blob (empty array if none).
	 */
	public static function get_head( int $post_id ): array {
		$head = get_post_meta( $post_id, self::HEAD_KEY, true );

		return is_array( $head ) ? $head : array();
	}

	/**
	 * The DERIVED render HTML for a post, or empty string if none.
	 *
	 * This is what the canvas serves. Empty means "not compiled yet" — callers
	 * fall back to canonical so a page authored before Step 5 still serves.
	 *
	 * @param int $post_id Post ID.
	 * @return string Raw stored render HTML (may be empty).
	 */
	public static function get_render_html( int $post_id ): string {
		$html = get_post_meta( $post_id, self::RENDER_KEY, true );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Store the derived render HTML for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $html    Compiled render HTML.
	 * @return void
	 */
	public static function set_render_html( int $post_id, string $html ): void {
		update_post_meta( $post_id, self::RENDER_KEY, $html );
	}

	/**
	 * The media-resolution reversal map for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{0:string,1:string}> Ordered [resolved, original] pairs (empty if none).
	 */
	public static function get_media_map( int $post_id ): array {
		$map = get_post_meta( $post_id, self::MEDIA_MAP_KEY, true );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Store the media-resolution reversal map for a post.
	 *
	 * @param int                                  $post_id Post ID.
	 * @param array<int, array{0:string,1:string}> $map     Ordered [resolved, original] pairs.
	 * @return void
	 */
	public static function set_media_map( int $post_id, array $map ): void {
		update_post_meta( $post_id, self::MEDIA_MAP_KEY, $map );
	}
}
