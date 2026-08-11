<?php
/**
 * S5 Framework — compiler SEO/quality gates (Build Order Step 9).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document-quality gates the compiler checks at import time — distinct from the
 * Authoring Contract vocabulary Validator. These assert the SEO/accessibility
 * structure that is inherent to a good document (PRD §13.1 Layer A):
 *
 *   - exactly one `<h1>` (document outline);
 *   - every `<img>` has a non-empty `alt`;
 *   - the LCP image (first content `<img>`) is not lazy-loaded;
 *   - a title and meta description are present in the head.
 *
 * All findings are WARNINGS (severity model, Step 4): they print and the page
 * proceeds — good structure is enforced by visibility, not by blocking the
 * pipeline. Findings use the same agent-readable shape as the Validator.
 */
final class SeoGate {

	public const RULE_H1        = 's5.seo.h1';
	public const RULE_IMG_ALT   = 's5.seo.img-alt';
	public const RULE_LCP_LAZY  = 's5.seo.lcp-lazy';
	public const RULE_META_HEAD = 's5.seo.meta';

	/**
	 * Run the SEO/quality gates over an HTML document.
	 *
	 * @param string $html HTML source (full document or body fragment).
	 * @param string $file Filename for diagnostics (display only).
	 * @return array<int, array<string, mixed>> Findings (all WARNING); empty = clean.
	 */
	public static function check( string $html, string $file = '' ): array {
		$findings = array();

		$prev = libxml_use_internal_errors( true );
		$dom  = new \DOMDocument();
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new \DOMXPath( $dom );

		// --- exactly one <h1> --------------------------------------------------
		$h1s = $xpath->query( '//h1' );
		$n   = ( $h1s instanceof \DOMNodeList ) ? $h1s->length : 0;
		if ( 1 !== $n ) {
			$findings[] = self::warn(
				self::RULE_H1,
				$file,
				sprintf( '%d <h1> element(s)', $n ),
				'a page should have exactly one <h1> for a clear document outline',
				0 === $n ? 'add a single <h1> naming the page' : 'keep one <h1>; demote the others to <h2>/<h3>'
			);
		}

		// --- every <img> has a non-empty alt ----------------------------------
		foreach ( $xpath->query( '//img' ) as $img ) {
			if ( ! ( $img instanceof \DOMElement ) ) {
				continue;
			}
			$alt = $img->getAttribute( 'alt' );
			if ( '' === trim( (string) $alt ) && ! $img->hasAttribute( 'aria-hidden' ) ) {
				$src        = (string) $img->getAttribute( 'src' );
				$findings[] = self::warn(
					self::RULE_IMG_ALT,
					$file,
					'<img src="' . $src . '">',
					'every content image needs descriptive alt text (or aria-hidden if decorative)',
					'add alt="…" describing the image, or aria-hidden="true" if purely decorative'
				);
			}
		}

		// --- LCP image (first content <img>) is not lazy-loaded ---------------
		$first_img = $xpath->query( '//img' )->item( 0 );
		if ( $first_img instanceof \DOMElement ) {
			$loading = strtolower( (string) $first_img->getAttribute( 'loading' ) );
			if ( 'lazy' === $loading ) {
				$findings[] = self::warn(
					self::RULE_LCP_LAZY,
					$file,
					'<img src="' . $first_img->getAttribute( 'src' ) . '"> loading="lazy"',
					'the first content image is the likely LCP element and must not be lazy-loaded',
					'remove loading="lazy" (or set loading="eager"/fetchpriority="high") on the first image'
				);
			}
		}

		// --- head has a title + meta description ------------------------------
		if ( false !== stripos( $html, '<head' ) ) {
			if ( 0 === $xpath->query( '//head/title' )->length ) {
				$findings[] = self::warn(
					self::RULE_META_HEAD,
					$file,
					'<title> missing',
					'every page needs a <title>',
					'add a <title> to the document head'
				);
			}
			if ( 0 === $xpath->query( '//head/meta[@name="description"]' )->length ) {
				$findings[] = self::warn(
					self::RULE_META_HEAD,
					$file,
					'meta description missing',
					'every page needs a meta description',
					'add <meta name="description" content="…"> to the head'
				);
			}
		}

		return $findings;
	}

	/**
	 * Whether any finding is present (all are warnings; convenience for callers).
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return bool
	 */
	public static function has_findings( array $findings ): bool {
		return ! empty( $findings );
	}

	/**
	 * Build a WARNING finding in the shared agent-readable shape.
	 *
	 * @param string $rule    Rule id.
	 * @param string $file    File name.
	 * @param string $token   Offending token / summary.
	 * @param string $message What is wrong and why.
	 * @param string $repair  Likely repair.
	 * @return array<string, mixed>
	 */
	private static function warn( string $rule, string $file, string $token, string $message, string $repair ): array {
		return array(
			'severity'     => Validator::SEVERITY_WARNING,
			'rule'         => $rule,
			'file'         => $file,
			'line'         => 0,
			'token'        => $token,
			'message'      => $message,
			'permitted'    => '',
			'repair'       => $repair,
			'did_you_mean' => '',
			'detail'       => '',
		);
	}
}
