<?php
/**
 * S5 Framework — full HTML document parser (head/body split).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splits a full HTML document into the two sanctioned import artifacts:
 *
 * 1. The <body> inner HTML — captured VERBATIM by byte boundaries (never
 *    re-serialized), so it round-trips exactly into `_s5_canonical_html`.
 * 2. A structured head blob — title, meta description, canonical, OG/meta tags,
 *    and JSON-LD, extracted from <head> and preserved as-is.
 *
 * These are the only two transforms Step 2 performs. It does not rewrite media,
 * expand components, or validate beyond parseability (Steps 4/6). A document
 * that has no parseable <body> is a parse failure.
 */
final class HtmlDocument {

	/**
	 * Verbatim inner HTML of <body> (the canonical fragment).
	 *
	 * @var string
	 */
	public string $body_html = '';

	/**
	 * Extracted head blob. See Storage::get_head() for the shape.
	 *
	 * @var array<string, mixed>
	 */
	public array $head = array();

	/**
	 * Head elements present in the source but NOT captured by the extractor,
	 * as human-readable descriptions. Reported (warned) by the importer so
	 * nothing is silently dropped.
	 *
	 * @var array<int, string>
	 */
	public array $unrecognized = array();

	/**
	 * Parse a full HTML document string.
	 *
	 * @param string $html Full HTML document (doctype/head/body).
	 * @return self
	 *
	 * @throws \RuntimeException If the document has no parseable <body>.
	 */
	public static function parse( string $html ): self {
		$doc = new self();

		$doc->body_html = self::extract_body_verbatim( $html );

		$dom = self::load_dom( $html );
		$doc->extract_head( $dom );

		return $doc;
	}

	/**
	 * Capture the inner HTML of <body> verbatim by byte boundaries.
	 *
	 * We deliberately do NOT use DOM serialization here: the body must be
	 * byte-identical to source. We locate the first `<body...>` open tag and the
	 * last `</body>` close tag and take everything between them.
	 *
	 * @param string $html Full document.
	 * @return string Verbatim body inner HTML.
	 *
	 * @throws \RuntimeException If no <body> can be located.
	 */
	private static function extract_body_verbatim( string $html ): string {
		if ( ! preg_match( '/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			throw new \RuntimeException( 'no <body> element found' );
		}
		$open_end = $m[0][1] + strlen( $m[0][0] );

		$close_pos = strripos( $html, '</body>' );
		if ( false === $close_pos || $close_pos < $open_end ) {
			throw new \RuntimeException( 'no closing </body> found' );
		}

		return substr( $html, $open_end, $close_pos - $open_end );
	}

	/**
	 * Load the document into DOM for head extraction only.
	 *
	 * Libxml errors are captured (not emitted) so a messy head does not fatal;
	 * the body is already captured verbatim above, independent of DOM.
	 *
	 * @param string $html Full document.
	 * @return \DOMDocument
	 */
	private static function load_dom( string $html ): \DOMDocument {
		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		// Force UTF-8 handling; do not let DOMDocument guess and mangle entities.
		$dom->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $dom;
	}

	/**
	 * Extract head artifacts from the DOM (Build Order Step 7 head ruling).
	 *
	 * The head is handled as: STRUCTURED EXTRACTION of a few load-bearing values
	 * (title, meta description, canonical) for the title filter and the round-trip
	 * head contract, plus ORDERED VERBATIM PASSTHROUGH of every OTHER head node —
	 * OG/Twitter meta, JSON-LD, favicon links, analytics tags, HTML comments, and
	 * anything else — preserved byte-for-byte and in source order into
	 * `head['passthrough']`. The framework omits nothing from the head.
	 *
	 * Two element kinds are captured structurally and NOT placed in passthrough,
	 * because WordPress/the theme emits them and a passthrough copy would DUPLICATE
	 * them:
	 *   - `<title>`  — core prints it via pre_get_document_title (one title tag).
	 *   - charset + viewport `<meta>` — the theme/canvas owns emitting these once;
	 *     we record their source values so the importer can WARN on drift, but we
	 *     never re-emit them.
	 *
	 * @param \DOMDocument $dom Parsed document.
	 * @return void
	 */
	private function extract_head( \DOMDocument $dom ): void {
		$heads = $dom->getElementsByTagName( 'head' );
		if ( 0 === $heads->length ) {
			return;
		}
		$head = $heads->item( 0 );

		$passthrough = array();

		foreach ( $head->childNodes as $node ) {
			// Comments (e.g. a TODO-GA4 analytics placeholder) are passed through
			// verbatim too — the head omits nothing.
			if ( $node instanceof \DOMComment ) {
				$passthrough[] = '<!--' . $node->textContent . '-->';
				continue;
			}
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue; // Whitespace/text between elements is not significant.
			}

			$tag = strtolower( $node->nodeName );

			// <title>: structured only; core emits the single title tag.
			if ( 'title' === $tag ) {
				$this->head['title'] = $node->textContent;
				continue;
			}

			// charset / viewport meta: theme owns emission — record for drift, do
			// NOT pass through (avoids a duplicate charset/viewport in the head).
			if ( 'meta' === $tag ) {
				if ( $node->hasAttribute( 'charset' ) ) {
					$this->head['charset'] = (string) $node->getAttribute( 'charset' );
					continue;
				}
				if ( 'viewport' === strtolower( (string) $node->getAttribute( 'name' ) ) ) {
					$this->head['viewport'] = (string) $node->getAttribute( 'content' );
					continue;
				}
				// Pull description out structurally for the head contract; it is
				// ALSO passed through verbatim so the served head matches source.
				if ( 'description' === strtolower( (string) $node->getAttribute( 'name' ) ) ) {
					$this->head['description'] = (string) $node->getAttribute( 'content' );
				}
			}

			// canonical link: structured value for the head contract; also passed
			// through verbatim.
			if ( 'link' === $tag && 'canonical' === strtolower( (string) $node->getAttribute( 'rel' ) ) ) {
				$this->head['canonical'] = (string) $node->getAttribute( 'href' );
			}

			// Everything else in the head is preserved verbatim, in order.
			$passthrough[] = self::serialize_element( $node );
		}

		if ( ! empty( $passthrough ) ) {
			$this->head['passthrough'] = $passthrough;
		}
	}

	/**
	 * Serialize a single DOM element back to an HTML string.
	 *
	 * @param \DOMElement $el Element.
	 * @return string
	 */
	private static function serialize_element( \DOMElement $el ): string {
		$out = $el->ownerDocument->saveHTML( $el );

		return is_string( $out ) ? trim( $out ) : '';
	}
}
