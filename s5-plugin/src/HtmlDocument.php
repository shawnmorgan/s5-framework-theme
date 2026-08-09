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
	 * Extract the sanctioned head artifacts from the DOM.
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

		$tags   = array();
		$jsonld = array();

		foreach ( $head->childNodes as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			$tag = strtolower( $node->nodeName );

			switch ( $tag ) {
				case 'title':
					$this->head['title'] = $node->textContent;
					break;

				case 'meta':
					$name     = strtolower( (string) $node->getAttribute( 'name' ) );
					$property = strtolower( (string) $node->getAttribute( 'property' ) );
					if ( 'description' === $name ) {
						$this->head['description'] = (string) $node->getAttribute( 'content' );
					}
					// Preserve OG/Twitter/other meta verbatim, plus the description
					// meta itself so the served head matches source exactly.
					if ( '' !== $name || '' !== $property || $node->hasAttribute( 'charset' ) || '' !== (string) $node->getAttribute( 'http-equiv' ) ) {
						$tags[] = self::serialize_element( $node );
					} else {
						$this->unrecognized[] = 'meta tag: ' . self::serialize_element( $node );
					}
					break;

				case 'link':
					$rel = strtolower( (string) $node->getAttribute( 'rel' ) );
					if ( 'canonical' === $rel ) {
						$this->head['canonical'] = (string) $node->getAttribute( 'href' );
					}
					// Keep SEO-relevant <link> rels; report others.
					if ( in_array( $rel, array( 'canonical', 'alternate', 'amphtml' ), true ) ) {
						$tags[] = self::serialize_element( $node );
					} else {
						$this->unrecognized[] = 'link[rel=' . ( '' !== $rel ? $rel : '(none)' ) . ']';
					}
					break;

				case 'script':
					$type = strtolower( (string) $node->getAttribute( 'type' ) );
					if ( 'application/ld+json' === $type ) {
						$jsonld[] = self::serialize_element( $node );
					} else {
						$this->unrecognized[] = 'script[type=' . ( '' !== $type ? $type : '(none)' ) . ']';
					}
					break;

				default:
					$this->unrecognized[] = 'head <' . $tag . '>';
					break;
			}
		}

		if ( ! empty( $tags ) ) {
			$this->head['tags'] = $tags;
		}
		if ( ! empty( $jsonld ) ) {
			$this->head['jsonld'] = $jsonld;
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
