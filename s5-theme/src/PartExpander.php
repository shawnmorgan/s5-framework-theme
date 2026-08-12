<?php
/**
 * S5 Framework — reusable-part expansion (Build Order Step 8).
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
 * Expands reusable-part references into their markup — the Step 8 compile-time
 * transform for templates/parts.
 *
 * A page authors a shared fragment ONCE as a part (`parts/site-header.html`) and
 * references it in canonical source with a `<s5-part name="site-header"></s5-part>`
 * element. Canonical stays DRY and portable (the reference, not the duplicated
 * markup); the compiler expands each reference into the part's verbatim bytes in
 * the DERIVED render. Editing the part and recompiling updates every page that
 * references it — the header/footer are maintained in one place.
 *
 * BYTE-FIDELITY, like media resolution: DOM is used only to LOCATE each
 * `<s5-part>` element; the rewrite is a targeted byte-level string replacement.
 * Each expansion records a `[expanded_bytes, reference_bytes]` pair in the shared
 * render-reversal map (out of band, page meta) so the round-trip harness maps the
 * expanded markup back to its reference before diffing — nothing framework-only
 * ships in the served HTML beyond the part's own authored markup.
 *
 * An unknown part name is a loud compile ERROR naming the page + part.
 */
final class PartExpander {

	/**
	 * Expand every `<s5-part>` reference in a render fragment.
	 *
	 * @param string                               $html    Working render HTML.
	 * @param int                                  $post_id Owning page id (diagnostics).
	 * @param array<int, array{0:string,1:string}> $map     Filled by ref with
	 *                                                       [expanded, reference] pairs.
	 * @return string HTML with part references expanded.
	 *
	 * @throws \RuntimeException If a referenced part does not exist.
	 */
	public static function expand( string $html, int $post_id, array &$map = array() ): string {
		if ( false === stripos( $html, '<s5-part' ) ) {
			return $html; // Nothing to expand — bytes untouched.
		}

		$parts_dir = self::parts_dir();

		$dom = self::load_dom( $html );
		if ( null === $dom ) {
			return $html;
		}
		$xpath = new \DOMXPath( $dom );

		$edits = array();
		foreach ( $xpath->query( '//*[local-name()="s5-part"]' ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			$name = (string) $node->getAttribute( 'name' );
			if ( '' === $name || ! self::is_safe_name( $name ) ) {
				throw new \RuntimeException(
					esc_html( sprintf( 'part expansion failed for page #%d: invalid or missing part name "%s"', $post_id, $name ) )
				);
			}

			$markup   = self::load_part( $parts_dir, $name, $post_id );
			$original = self::element_source( $node, $html );
			if ( '' === $original ) {
				continue;
			}
			$edits[] = array( $original, $markup );
		}

		foreach ( $edits as $edit ) {
			list( $reference, $expanded ) = $edit;

			$pos = strpos( $html, $reference );
			if ( false === $pos ) {
				continue;
			}

			$html  = substr( $html, 0, $pos ) . $expanded . substr( $html, $pos + strlen( $reference ) );
			$map[] = array( $expanded, $reference );
		}

		return $html;
	}

	/**
	 * Load a part's verbatim markup from the parts directory.
	 *
	 * @param string $parts_dir Absolute parts dir.
	 * @param string $name      Part name (no extension).
	 * @param int    $post_id   Owning page id (diagnostics).
	 * @return string Part markup (trailing newline trimmed).
	 *
	 * @throws \RuntimeException If the part file is missing.
	 */
	private static function load_part( string $parts_dir, string $name, int $post_id ): string {
		$path = $parts_dir . '/' . $name . '.html';
		$real = realpath( $path );
		$root = realpath( $parts_dir );

		if ( false === $real || ! is_file( $real ) || false === $root || 0 !== strpos( $real, $root . '/' ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf( 'part expansion failed for page #%d: part "%s" not found (looked in %s)', $post_id, $name, $path )
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local part file; wp_remote_get is for URLs.
		$markup = file_get_contents( $real );
		if ( false === $markup ) {
			throw new \RuntimeException(
				esc_html( sprintf( 'part expansion failed for page #%d: could not read part "%s"', $post_id, $name ) )
			);
		}

		return rtrim( (string) $markup, "\r\n" );
	}

	/**
	 * The exact source substring for a `<s5-part>` element, located in $html.
	 *
	 * @param \DOMElement $el   Element.
	 * @param string      $html Original HTML.
	 * @return string Exact source substring, or '' if not locatable.
	 */
	private static function element_source( \DOMElement $el, string $html ): string {
		$name   = (string) $el->getAttribute( 'name' );
		$needle = 'name="' . $name . '"';
		$anchor = strpos( $html, $needle );
		if ( false === $anchor ) {
			return '';
		}
		$open = strrpos( substr( $html, 0, $anchor ), '<s5-part' );
		if ( false === $open ) {
			return '';
		}
		$gt = strpos( $html, '>', $anchor );
		if ( false === $gt ) {
			return '';
		}
		// Self-closing form: `<s5-part ... />`.
		if ( '/' === substr( $html, $gt - 1, 1 ) ) {
			return substr( $html, $open, $gt - $open + 1 );
		}
		// Paired form: `<s5-part ...>...</s5-part>`.
		$close = stripos( $html, '</s5-part>', $gt );
		if ( false === $close ) {
			return substr( $html, $open, $gt - $open + 1 );
		}
		return substr( $html, $open, $close - $open + strlen( '</s5-part>' ) );
	}

	/**
	 * Whether a part name is a safe slug (no path traversal).
	 *
	 * @param string $name Part name.
	 * @return bool
	 */
	private static function is_safe_name( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $name );
	}

	/**
	 * Load an HTML fragment into DOM for LOCATING part elements only.
	 *
	 * @param string $html Fragment.
	 * @return \DOMDocument|null
	 */
	private static function load_dom( string $html ): ?\DOMDocument {
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$ok   = $dom->loadHTML(
			'<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $ok ? $dom : null;
	}

	/**
	 * Absolute parts directory (`paths.parts` in s5.config.json), container-aware.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException If the parts dir is not declared or missing.
	 */
	private static function parts_dir(): string {
		$path = ValidateCommand::repo_root() . '/s5.config.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local config; wp_remote_get is for URLs.
		$raw    = file_get_contents( $path );
		$config = ( false !== $raw ) ? json_decode( (string) $raw, true ) : null;
		$rel    = ( is_array( $config ) && isset( $config['paths']['parts'] ) ) ? (string) $config['paths']['parts'] : 'parts';

		$abs = ValidateCommand::repo_root() . '/' . ltrim( $rel, '/' );
		if ( ! is_dir( $abs ) ) {
			throw new \RuntimeException(
				esc_html( sprintf( 's5.config.json: parts dir "%s" not found at %s', $rel, $abs ) )
			);
		}
		return rtrim( $abs, '/' );
	}
}
