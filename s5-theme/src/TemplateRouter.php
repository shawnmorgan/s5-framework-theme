<?php
/**
 * S5 Framework — template hierarchy resolver (S5-TEMPLATES-SPEC v0.1, PRD §12.6).
 *
 * "A theme that cannot render a post is not a theme." This resolves the WordPress
 * template hierarchy (single / index / archive / search / 404) to S5 canonical-HTML
 * templates under site/templates/, composed of registered <s5-part> regions + a
 * content region bound by S5 Dynamics v0 (<s5-field> / <s5-loop>). It matches the
 * Twenty-Twenty-Five native baseline with no plugin.
 *
 * Boundaries:
 *   - Singular FRAMEWORK PAGES stay owned by Render (canonical-HTML pages); this
 *     router explicitly ignores them (is_framework_page → return).
 *   - Regular single POSTS, the blog index, archives, search, and 404 are owned here.
 *   - `page` requests that are NOT framework pages (e.g. a classic WP page) fall back
 *     to WordPress's own template (we don't hijack non-framework content we can't bind).
 *
 * Render path: load template file → expand <s5-part> → run S5 Dynamics (fields/loops
 * against the main query) → resolve context tags (comments / pagination / archive
 * heading / search query) → emit through the template canvas wrapper.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve WP contexts to S5 canonical-HTML templates.
 */
final class TemplateRouter {

	/**
	 * The template canvas wrapper (doctype/head/body around the rendered fragment).
	 *
	 * @var string
	 */
	private const TEMPLATE_CANVAS = 'templates/template-canvas.php';

	/**
	 * Register on template_include, just ahead of Render (99) so framework-page
	 * routing still wins where it applies (we defer to it explicitly anyway).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'template_include', array( self::class, 'route' ), 95 );
	}

	/**
	 * Absolute path to an S5 template file by key, or '' if it does not exist.
	 *
	 * @param string $key Template key (single/index/archive/search/404).
	 * @return string
	 */
	public static function template_path( string $key ): string {
		if ( ! preg_match( '/^[a-z0-9-]+$/', $key ) ) {
			return '';
		}
		$dir  = trailingslashit( get_stylesheet_directory() ) . 'site/templates/';
		$path = $dir . $key . '.html';
		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Decide the S5 template key for the current WordPress query, or '' to defer to
	 * WordPress's own template. Mirrors the WP hierarchy, scoped to the v0 baseline.
	 *
	 * @return string
	 */
	public static function resolve_key(): string {
		if ( is_404() ) {
			return '404';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_singular( 'post' ) ) {
			return 'single';
		}
		// Category / tag / date / author / any main-query archive → one loop template.
		if ( is_archive() ) {
			return 'archive';
		}
		// Blog home (posts index) and the catch-all fallback.
		if ( is_home() ) {
			return 'index';
		}
		return '';
	}

	/**
	 * template_include filter: swap in the S5 template canvas when an S5 template owns
	 * the current context; otherwise return the template WordPress resolved.
	 *
	 * @param string $template The template WordPress resolved.
	 * @return string
	 */
	public static function route( string $template ): string {
		// Framework PAGES are Render's; never hijack them here.
		if ( is_singular() ) {
			$qid = get_queried_object_id();
			if ( $qid && Storage::is_framework_page( (int) $qid ) ) {
				return $template;
			}
		}

		$key = self::resolve_key();
		if ( '' === $key || '' === self::template_path( $key ) ) {
			return $template; // no S5 template for this context → WordPress default.
		}

		$canvas = S5_PLUGIN_DIR . self::TEMPLATE_CANVAS;
		return is_readable( $canvas ) ? $canvas : $template;
	}

	/**
	 * Build the fully-rendered fragment for the current context: load the S5 template,
	 * expand parts, run Dynamics, resolve context tags. Called by the template canvas.
	 *
	 * @return string Rendered HTML fragment (parts + bound content).
	 */
	public static function render_current(): string {
		$key = self::resolve_key();
		if ( '' === $key ) {
			return '';
		}
		$path = self::template_path( $key );
		if ( '' === $path ) {
			return '';
		}
		$html = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme template.

		// 1. Expand <s5-part> regions (header/footer/nav). post_id 0 = no page context.
		$map  = array();
		$html = PartExpander::expand( $html, 0, $map );

		// 2. Resolve context tags that need the query (heading / search / comments /
		//    pagination) BEFORE Dynamics, since none contains s5-field/s5-loop.
		$html = self::resolve_context_tags( $html );

		// 3. Run S5 Dynamics: fields bind to the queried post (single); loops walk the
		//    main query (index/archive/search).
		$html = Dynamics::render( $html );

		// 4. Shortcode pass (forms / plugin compatibility), consistent with canvas.php.
		$html = do_shortcode( $html );

		return $html;
	}

	/**
	 * Resolve the non-field context tags used by the baseline templates:
	 *   <s5-archive-label>  → "Category" / "Tag" / "Author" / "Archives"
	 *   <s5-archive-title>  → the term/author/date title
	 *   <s5-search-query>   → the submitted search query (escaped)
	 *   <s5-pagination>     → the main-query pager
	 *   <s5-comments>       → the WordPress comment list + form (single only)
	 *
	 * @param string $html Fragment HTML.
	 * @return string
	 */
	private static function resolve_context_tags( string $html ): string {
		$replace = array(
			'#<s5-archive-label\b[^>]*>(?:</s5-archive-label>)?#i' => esc_html( self::archive_label() ),
			'#<s5-archive-title\b[^>]*>(?:</s5-archive-title>)?#i' => wp_kses_post( self::archive_title() ),
			'#<s5-search-query\b[^>]*>(?:</s5-search-query>)?#i'   => esc_html( (string) get_search_query() ),
		);
		foreach ( $replace as $pattern => $value ) {
			$html = (string) preg_replace( $pattern, $value, $html );
		}

		// Pagination — the standard main-query pager markup.
		$html = (string) preg_replace_callback(
			'#<s5-pagination\b[^>]*>(?:</s5-pagination>)?#i',
			static function (): string {
				$links = paginate_links( array( 'type' => 'list' ) );
				return is_string( $links ) ? '<nav class="s5-pagination" aria-label="Posts">' . $links . '</nav>' : '';
			},
			$html
		);

		// Comments — WordPress comment list + form, buffered from the core template.
		$html = (string) preg_replace_callback(
			'#<s5-comments\b[^>]*>(?:</s5-comments>)?#i',
			static function (): string {
				if ( ! ( is_singular() && ( comments_open() || get_comments_number() ) ) ) {
					return '';
				}
				ob_start();
				comments_template();
				return '<div class="s5-comments">' . (string) ob_get_clean() . '</div>';
			},
			$html
		);

		return $html;
	}

	/**
	 * The archive-kind label for the current archive context.
	 *
	 * @return string
	 */
	private static function archive_label(): string {
		if ( is_category() ) {
			return __( 'Category', 's5' );
		}
		if ( is_tag() ) {
			return __( 'Tag', 's5' );
		}
		if ( is_author() ) {
			return __( 'Author', 's5' );
		}
		if ( is_date() ) {
			return __( 'Archive', 's5' );
		}
		return __( 'Archive', 's5' );
	}

	/**
	 * The archive title (term name / author / date), via WordPress's own resolver but
	 * stripped of its "Category:" prefix (our label tag supplies the kind separately).
	 *
	 * @return string
	 */
	private static function archive_title(): string {
		$title = get_the_archive_title();
		// get_the_archive_title() prefixes with "Category:" etc.; drop the prefix since
		// <s5-archive-label> already renders the kind.
		return (string) preg_replace( '/^[^:]+:\s*/', '', (string) $title );
	}
}
