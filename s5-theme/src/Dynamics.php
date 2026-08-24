<?php
/**
 * S5 Framework — S5 Dynamics v0 (native, owned; supersedes L&L).
 *
 * The minimum native dynamic binding required to be a theme at all (S5-TEMPLATES-SPEC
 * §3, PRD §12.6): the front edge of S5-DYNAMICS-SPEC-v1.5, scoped HARD to the
 * Twenty-Twenty-Five baseline — current-post CORE fields and the MAIN query only.
 *
 * Vocabulary (closed, enumerated — unknown = left inert, never guessed):
 *   <s5-field name="title|content|excerpt|featured-image|date|modified|author|permalink">
 *   <s5-loop> … body … <s5-empty> … no-results … </s5-empty> </s5-loop>
 *
 * Laws (S5-DYNAMICS-SPEC §0/§7): no expression language, no variables, no user tags,
 * no PHP-in-templates. Output is context-escaped and inert (HTML-text or attribute or
 * URL as the field dictates) — `content` is the one field WordPress itself sanitizes
 * via the_content, everything else is escaped here.
 *
 * NOT in v0 (deferred adapter tiers, S5-TEMPLATES §5): ACF/meta/Pods adapters, custom
 * WP_Query, taxonomy queries beyond the main-query archive, conditionals beyond loop/
 * empty. Those escalate to later Dynamics tiers, never to syntax improvisation.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request-time evaluator for the v0 S5 Dynamics vocabulary.
 */
final class Dynamics {

	/**
	 * The closed core-field registry — the current post's renderable pieces, bindable
	 * by `<s5-field name="…">`. THIS registration is the seed of the full Dynamics
	 * system: later tiers extend the same registry with ACF/meta/Woo adapters. v0
	 * registers only WordPress-core fields (exactly the TT25 surface, no plugin).
	 *
	 * Each entry: kind decides escaping/inertness (S5-DYNAMICS §7).
	 *   - 'text'  → escaped HTML text
	 *   - 'html'  → WordPress-sanitized rich HTML (content only)
	 *   - 'url'   → escaped URL
	 *   - 'media' → an <img> resolved from the attachment
	 *
	 * @return array<string, array{label:string, kind:string}>
	 */
	public static function field_registry(): array {
		return array(
			'title'          => array( 'label' => 'Title', 'kind' => 'text' ),
			'content'        => array( 'label' => 'Content', 'kind' => 'html' ),
			'excerpt'        => array( 'label' => 'Excerpt', 'kind' => 'text' ),
			'featured-image' => array( 'label' => 'Featured image', 'kind' => 'media' ),
			'date'           => array( 'label' => 'Published date', 'kind' => 'text' ),
			'modified'       => array( 'label' => 'Modified date', 'kind' => 'text' ),
			'author'         => array( 'label' => 'Author', 'kind' => 'text' ),
			'permalink'      => array( 'label' => 'Permalink', 'kind' => 'url' ),
		);
	}

	/**
	 * True when a fragment contains any v0 S5 Dynamics tag (so callers can flag a page
	 * dynamic / decide whether to run the pass at all). Static fragments match nothing.
	 *
	 * @param string $html Fragment HTML.
	 * @return bool
	 */
	public static function has_dynamics( string $html ): bool {
		return (bool) preg_match( '/<s5-(?:field|loop|if|else|empty)\b/i', $html );
	}

	/**
	 * Evaluate the v0 vocabulary in a fragment against the current WordPress context.
	 *
	 * Loops are resolved first (they establish the per-item context for the fields
	 * inside them); fields OUTSIDE any loop bind to the current queried post (a single
	 * template). Order: loops → remaining fields.
	 *
	 * @param string        $html  Fragment HTML.
	 * @param \WP_Post|null $post  Context post for bare fields (defaults to the queried post).
	 * @return string Rendered HTML with dynamics resolved and made inert.
	 */
	public static function render( string $html, ?\WP_Post $post = null ): string {
		if ( ! self::has_dynamics( $html ) ) {
			return $html; // byte-clean: nothing dynamic, nothing touched.
		}

		// 1. Loops first — each expands against the MAIN query, rendering its body per
		//    post with that post as the field context.
		$html = self::render_loops( $html );

		// 2. Bare fields (outside any loop) bind to the given/queried post.
		$context = $post instanceof \WP_Post ? $post : get_post();
		if ( $context instanceof \WP_Post ) {
			$html = self::render_fields( $html, $context );
		}

		return $html;
	}

	/**
	 * Expand every `<s5-loop>…</s5-loop>` against the MAIN query. Walks the posts
	 * WordPress already resolved for this request (index / archive / search — WP
	 * decides WHICH posts, the loop body is identical), rendering the body once per
	 * post with that post as the field context. `<s5-empty>…</s5-empty>` inside the
	 * loop renders instead when there are no posts.
	 *
	 * Non-recursive by design (v0 depth cap): a loop body is not itself scanned for
	 * nested loops. Innermost-first is unnecessary at v0 scope.
	 *
	 * @param string $html Fragment HTML.
	 * @return string
	 */
	private static function render_loops( string $html ): string {
		return (string) preg_replace_callback(
			'#<s5-loop\b[^>]*>(.*?)</s5-loop>#is',
			static function ( array $m ): string {
				$body = $m[1];

				// Split the loop body into the per-item template and the empty state.
				$empty = '';
				if ( preg_match( '#<s5-empty\b[^>]*>(.*?)</s5-empty>#is', $body, $em ) ) {
					$empty = $em[1];
					$body  = (string) preg_replace( '#<s5-empty\b[^>]*>.*?</s5-empty>#is', '', $body );
				}

				global $wp_query;
				if ( ! ( $wp_query instanceof \WP_Query ) || ! $wp_query->have_posts() ) {
					return $empty; // no results → the empty state (may be '').
				}

				$out = '';
				while ( $wp_query->have_posts() ) {
					$wp_query->the_post();
					$post = get_post();
					if ( $post instanceof \WP_Post ) {
						$out .= self::render_fields( $body, $post );
					}
				}
				wp_reset_postdata();
				return $out;
			},
			$html
		);
	}

	/**
	 * Resolve every `<s5-field name="…">` in a fragment against a specific post.
	 * Self-closing or paired form both accepted; unknown/unregistered names are left
	 * inert (rendered empty) — the closed vocabulary never guesses.
	 *
	 * @param string   $html Fragment HTML.
	 * @param \WP_Post $post Context post.
	 * @return string
	 */
	private static function render_fields( string $html, \WP_Post $post ): string {
		$registry = self::field_registry();

		// Match <s5-field name="x"></s5-field> and <s5-field name="x"/> and <s5-field name="x">.
		return (string) preg_replace_callback(
			'#<s5-field\b([^>]*)>(?:</s5-field>)?#i',
			static function ( array $m ) use ( $registry, $post ): string {
				$attrs = $m[1];
				if ( ! preg_match( '/\bname\s*=\s*(["\'])(.*?)\1/i', $attrs, $nm ) ) {
					return ''; // no name → inert.
				}
				$name = strtolower( trim( $nm[2] ) );
				if ( ! isset( $registry[ $name ] ) ) {
					return ''; // unknown field → inert (never guessed).
				}
				return self::field_value( $name, $registry[ $name ]['kind'], $post );
			},
			$html
		);
	}

	/**
	 * Produce one core field's inert, context-escaped value for a post.
	 *
	 * @param string   $name Field key (registry key).
	 * @param string   $kind Escaping kind (text|html|url|media).
	 * @param \WP_Post $post Context post.
	 * @return string
	 */
	private static function field_value( string $name, string $kind, \WP_Post $post ): string {
		switch ( $name ) {
			case 'title':
				return esc_html( get_the_title( $post ) );

			case 'content':
				// The one rich field: WordPress sanitizes via the_content filters
				// (wpautop, shortcodes, kses on save). Rendered as sanitized HTML.
				return apply_filters( 'the_content', $post->post_content );

			case 'excerpt':
				return esc_html( get_the_excerpt( $post ) );

			case 'featured-image':
				$id = get_post_thumbnail_id( $post );
				if ( ! $id ) {
					return '';
				}
				// Core markup: attachment-backed <img> with srcset + dimensions + lazy.
				return (string) wp_get_attachment_image( $id, 'large', false, array( 'loading' => 'lazy' ) );

			case 'date':
				return esc_html( get_the_date( '', $post ) );

			case 'modified':
				return esc_html( get_the_modified_date( '', $post ) );

			case 'author':
				return esc_html( get_the_author_meta( 'display_name', (int) $post->post_author ) );

			case 'permalink':
				return esc_url( (string) get_permalink( $post ) );
		}

		unset( $kind ); // kind is advisory; per-field escaping is explicit above.
		return '';
	}
}
