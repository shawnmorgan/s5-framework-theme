<?php
/**
 * S5 Framework — compiler: canonical source -> derived render + mirror (Step 5).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns canonical source HTML into the two derived artifacts:
 *
 *   1. `_s5_render_html` — the bytes the canvas serves.
 *   2. the `post_content` semantic mirror — a visible-HTML mirror for WordPress
 *      search and SEO-plugin introspection; NEVER served.
 *
 * Canonical source is portable truth; both derived artifacts are disposable
 * environment state, regenerable from canonical at any time. `persist_page()`
 * writes canonical + render + mirror ATOMICALLY (all or nothing).
 */
final class Compiler {

	/**
	 * The compile pipeline: an ORDERED, auditable list of transforms applied to
	 * canonical source to produce render HTML. It is enumerated in code (same
	 * pattern as the round-trip harness's sanctioned-transforms list) so it stays
	 * auditable as it grows. TODAY every active transform is identity — render is
	 * byte-identical to canonical — but the list, ordering, and seams exist now.
	 *
	 * Each entry: [id, description, callable]. Adding a real transform is one new
	 * entry, not a rewrite.
	 *
	 *   1. identity            canonical bytes pass through unchanged (today's whole pipeline).
	 *   --- seats reserved (NOT active yet; listed so the ordering is visible) ---
	 *   2. hidden-node removal (Step 5 do-not-build: no data-hidden exists yet).
	 *   3. media resolution    (Step 6): logical asset refs -> env media markup.
	 *   4. component expansion  (later): deterministic write-time expansions.
	 *
	 * @return array<int, array{id: string, desc: string, fn: callable}>
	 */
	private static function pipeline(): array {
		return array(
			array(
				'id'   => 'identity',
				'desc' => 'canonical bytes pass through unchanged',
				'fn'   => static function ( string $html ): string {
					return $html;
				},
			),
			// 2. hidden-node removal — seat reserved (no data-hidden until later).
			// 3. media resolution — joins in Step 6.
			// 4. component expansion — joins later.
		);
	}

	/**
	 * Compile canonical source HTML into derived render HTML.
	 *
	 * Deterministic: the same canonical input always yields byte-identical output.
	 *
	 * @param string               $canonical Canonical body HTML.
	 * @param array<string, mixed> $context   Compile context (post id, etc.); unused today.
	 * @return string Derived render HTML.
	 */
	public static function compile( string $canonical, array $context = array() ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $context is the compile context; real transforms consume it from Step 6.
		$html = $canonical;
		foreach ( self::pipeline() as $transform ) {
			$html = (string) call_user_func( $transform['fn'], $html );
		}
		return $html;
	}

	/**
	 * Derive the visible semantic `post_content` mirror from canonical body HTML.
	 *
	 * Removes: <script> subtrees, <style> subtrees, inline <svg> subtrees, and
	 * ALL data-* attributes. Preserves visible structure (headings, paragraphs,
	 * lists, links, images). This mirror feeds WordPress search + SEO plugins and
	 * is NEVER served as the page.
	 *
	 * @param string $canonical Canonical body HTML.
	 * @return string Semantic mirror HTML.
	 */
	public static function derive_mirror( string $canonical ): string {
		if ( '' === trim( $canonical ) ) {
			return '';
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		// Wrap so we can extract just the body children back out afterwards.
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><html><body>' . $canonical . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( null === $body ) {
			return '';
		}

		// 1. Remove <script>, <style>, and <svg> subtrees entirely.
		foreach ( array( 'script', 'style', 'svg' ) as $tag ) {
			$nodes = array();
			foreach ( $body->getElementsByTagName( $tag ) as $n ) {
				$nodes[] = $n; // Collect first; a live NodeList mutates during removal.
			}
			foreach ( $nodes as $n ) {
				if ( $n->parentNode ) {
					$n->parentNode->removeChild( $n );
				}
			}
		}

		// 2. Strip every data-* attribute from every remaining element.
		$xpath = new \DOMXPath( $dom );
		foreach ( $xpath->query( '//*[@*]' ) as $el ) {
			if ( ! ( $el instanceof \DOMElement ) ) {
				continue;
			}
			$to_remove = array();
			foreach ( $el->attributes as $attr ) {
				if ( 0 === stripos( $attr->nodeName, 'data-' ) ) {
					$to_remove[] = $attr->nodeName;
				}
			}
			foreach ( $to_remove as $name ) {
				$el->removeAttribute( $name );
			}
		}

		// 3. Serialize the body's children back to an HTML string.
		$out = '';
		foreach ( $body->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return trim( $out );
	}

	/**
	 * Compile and persist a page's derived artifacts ATOMICALLY.
	 *
	 * Writes, together: canonical meta, derived render meta, and the post_content
	 * mirror. If the mirror cannot be stored faithfully (a content filter mutated
	 * it on save), the whole operation is rolled back so no state exists where the
	 * mirror/render reflect a different canonical than the one stored.
	 *
	 * @param int    $post_id   Page ID.
	 * @param string $canonical Canonical body HTML to store + compile from.
	 * @return array{render_changed: bool, mirror: string}
	 *
	 * @throws \RuntimeException If the post_content mirror cannot be stored verbatim.
	 */
	public static function persist_page( int $post_id, string $canonical ): array {
		$render = self::compile( $canonical, array( 'post_id' => $post_id ) );
		$mirror = self::derive_mirror( $canonical );

		// Snapshot for rollback.
		$prev_canonical = Storage::get_canonical_html( $post_id );
		$prev_render    = Storage::get_render_html( $post_id );
		$prev_post      = get_post( $post_id );
		$prev_content   = ( $prev_post instanceof \WP_Post ) ? (string) $prev_post->post_content : '';

		// 1. Canonical (portable truth) + 2. derived render (served artifact).
		update_post_meta( $post_id, Storage::META_KEY, $canonical );
		Storage::set_render_html( $post_id, $render );

		// 3. Semantic mirror -> post_content, WITHOUT content filters mangling it.
		$stored = self::write_post_content_raw( $post_id, $mirror );

		if ( $stored !== $mirror ) {
			// Roll back everything so canonical/render/mirror never disagree.
			update_post_meta( $post_id, Storage::META_KEY, $prev_canonical );
			Storage::set_render_html( $post_id, $prev_render );
			self::write_post_content_raw( $post_id, $prev_content );

			throw new \RuntimeException(
				esc_html(
					sprintf(
						'post_content mirror for page #%d was altered on save (got %d bytes, expected %d); rolled back',
						$post_id,
						strlen( $stored ),
						strlen( $mirror )
					)
				)
			);
		}

		return array(
			'render_changed' => ( $prev_render !== $render ),
			'mirror'         => $mirror,
		);
	}

	/**
	 * Write raw HTML to post_content without kses / content-filter mutation.
	 *
	 * We update the column via wp_update_post with kses suspended, then re-read
	 * the stored value so the caller can assert fidelity.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Content to store verbatim.
	 * @return string The content as actually stored.
	 */
	private static function write_post_content_raw( int $post_id, string $content ): string {
		// kses runs on save for users without unfiltered_html (the CLI context);
		// suspend it so the derived mirror is stored exactly as the compiler emitted.
		$had_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $had_kses ) {
			kses_remove_filters();
		}

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( $had_kses ) {
			kses_init_filters();
		}

		clean_post_cache( $post_id );
		$post = get_post( $post_id );

		return ( $post instanceof \WP_Post ) ? (string) $post->post_content : '';
	}
}
