<?php
/**
 * S5 Framework — request-time render path.
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the stored canonical HTML fragment for framework pages.
 *
 * Step 1 scope: on a singular framework page, take over template selection and
 * emit the stored fragment through a plugin-owned canvas template, bypassing the
 * theme and Gutenberg entirely. The fragment is written byte-for-byte — it is
 * never passed through `the_content` (so wpautop, wptexturize, shortcode/block
 * expansion, and oEmbed never touch it). The only WordPress output around it is
 * the wrapper's `wp_head()` / `wp_footer()`, which stay OUTSIDE the fragment so
 * the authored bytes stay pristine.
 *
 * Deliberately NOT in Step 1: media rewriting, head parsing, L&L, components,
 * canonical/render split. See the Build Order.
 */
final class Render {

	/**
	 * Absolute path to the canvas template.
	 *
	 * @var string
	 */
	private const CANVAS_TEMPLATE = 'templates/canvas.php';

	/**
	 * Register render hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'template_include', array( self::class, 'route_framework_page' ), 99 );
		// Framework pages are authored as HTML, not blocks — keep the block
		// editor off so no block markup or editor assumptions attach to them.
		add_filter( 'use_block_editor_for_post', array( self::class, 'disable_block_editor' ), 10, 2 );

		// Emit the stored head artifacts on the canvas's wp_head seam, and wire
		// the document <title> through the core title filter so there is exactly
		// one title tag (wp_head prints it; we do not echo a second one).
		add_action( 'wp_head', array( self::class, 'output_head' ), 1 );
		add_filter( 'pre_get_document_title', array( self::class, 'filter_document_title' ) );

		// Bypassing the theme means no theme declares title-tag support, so
		// core wp_head() would not print a <title>. Declare it here so core
		// prints exactly one title tag, populated by filter_document_title().
		add_action( 'after_setup_theme', array( self::class, 'enable_title_tag' ) );
	}

	/**
	 * Ensure core prints a single <title> in wp_head() for framework pages.
	 *
	 * @return void
	 */
	public static function enable_title_tag(): void {
		add_theme_support( 'title-tag' );
	}

	/**
	 * Route singular framework pages to the canvas template.
	 *
	 * @param string $template The template WordPress resolved.
	 * @return string The canvas template for framework pages, else $template.
	 */
	public static function route_framework_page( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id || ! Storage::is_framework_page( (int) $post_id ) ) {
			return $template;
		}

		$canvas = S5_PLUGIN_DIR . self::CANVAS_TEMPLATE;

		return is_readable( $canvas ) ? $canvas : $template;
	}

	/**
	 * Turn off the block editor for framework pages.
	 *
	 * @param bool     $use_block_editor Whether to use the block editor.
	 * @param \WP_Post $post             The post being edited.
	 * @return bool
	 */
	public static function disable_block_editor( $use_block_editor, $post ) {
		if ( $post instanceof \WP_Post && Storage::is_framework_page( (int) $post->ID ) ) {
			return false;
		}

		return $use_block_editor;
	}

	/**
	 * Emit the stored head artifacts (description, canonical, OG/meta tags,
	 * JSON-LD) for the current framework page. The <title> is handled by the
	 * core title filter, not echoed here, to avoid a duplicate title tag.
	 *
	 * @return void
	 */
	public static function output_head(): void {
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! Storage::is_framework_page( (int) $post_id ) ) {
			return;
		}

		$head = Storage::get_head( (int) $post_id );

		$lines = array();

		if ( ! empty( $head['tags'] ) && is_array( $head['tags'] ) ) {
			foreach ( $head['tags'] as $tag ) {
				$lines[] = (string) $tag;
			}
		}
		if ( ! empty( $head['jsonld'] ) && is_array( $head['jsonld'] ) ) {
			foreach ( $head['jsonld'] as $block ) {
				$lines[] = (string) $block;
			}
		}

		if ( empty( $lines ) ) {
			return;
		}

		// Stored verbatim from source; emitted as-is by design (Build Order Step 2).
		echo "\n" . implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- canonical head artifacts, served verbatim by design.
	}

	/**
	 * Provide the document title for a framework page from its stored head blob.
	 *
	 * @param string $title The default title.
	 * @return string
	 */
	public static function filter_document_title( $title ) {
		$post_id = get_queried_object_id();
		if ( ! $post_id || ! Storage::is_framework_page( (int) $post_id ) ) {
			return $title;
		}

		$head = Storage::get_head( (int) $post_id );

		return ( isset( $head['title'] ) && '' !== $head['title'] ) ? (string) $head['title'] : $title;
	}
}
