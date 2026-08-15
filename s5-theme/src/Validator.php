<?php
/**
 * S5 Framework — Authoring Contract validator (Build Order Step 4).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates a page's HTML against the Authoring Contract: every class must be in
 * the closed stylesheet vocabulary and conform to the layer grammar, and inline
 * `style=` is prohibited except inside inline <svg> subtrees.
 *
 * Severity model (decided — encoded exactly, Build Order Step 4):
 *   ERROR   (blocks import): class not in vocabulary · inline style= (outside
 *           the enumerated exceptions) · unparseable HTML.
 *   WARNING (prints, proceeds): class defined in CSS but grammar-nonconforming
 *           (e.g. legacy Foundation names) · numeric scale step aliases.
 *
 * Findings are agent-readable (PRD §4.2): file · line · rule id · offending
 * token · what the contract permits · likely repair · did-you-mean nearest
 * match. An agent must be able to fix the file from the finding alone.
 */
final class Validator {

	public const SEVERITY_ERROR   = 'error';
	public const SEVERITY_WARNING = 'warning';

	// Rule ids (stable, agent-facing).
	public const RULE_UNKNOWN_CLASS = 's5.class.unknown';
	public const RULE_GRAMMAR       = 's5.class.grammar';
	public const RULE_NUMERIC_SCALE = 's5.class.numeric-scale';
	public const RULE_INLINE_STYLE  = 's5.style.inline';
	public const RULE_UNPARSEABLE   = 's5.html.unparseable';
	public const RULE_EVENT_HANDLER = 's5.attr.event-handler';
	public const RULE_JS_URL        = 's5.attr.javascript-url';
	public const RULE_SCRIPT_TAG    = 's5.html.script-tag';

	/**
	 * ENUMERATED inline-style exceptions — the ONLY places a `style=` attribute
	 * is allowed. This list starts now, in code, commented and auditable (same
	 * pattern as the round-trip harness's sanctioned-transforms list). Adding an
	 * exception is one entry here, never a loosening of the rule.
	 *
	 *   #1  style= attributes within an inline <svg> subtree (SVG presentation
	 *       attributes and generated icon markup legitimately carry style).
	 *
	 * Nothing else.
	 *
	 * @var array<int, string>
	 */
	private const INLINE_STYLE_EXCEPTIONS = array(
		'inside inline <svg> subtree',
	);

	/**
	 * Validate a full HTML document (or fragment) string.
	 *
	 * @param string              $html  HTML source.
	 * @param array<string, true> $vocab Class vocabulary set (name => true).
	 * @param string              $file  Filename for diagnostics (display only).
	 * @return array<int, array<string, mixed>> Findings; empty means clean.
	 */
	public static function validate( string $html, array $vocab, string $file = '' ): array {
		$findings = array();

		$parse_error = null;
		$dom         = self::load_dom( $html, $parse_error );
		if ( null !== $parse_error ) {
			$findings[] = self::finding(
				self::SEVERITY_ERROR,
				self::RULE_UNPARSEABLE,
				$file,
				self::line_of( $html, '<' ),
				'',
				'the document could not be parsed as HTML: ' . $parse_error,
				'well-formed HTML (matched tags, quoted attributes)',
				'',
				''
			);
			return $findings;
		}

		$xpath = new \DOMXPath( $dom );

		// --- classes -------------------------------------------------------
		foreach ( $xpath->query( '//*[@class]' ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			// SVG-internal class names are the SVG author's business, not ours.
			if ( self::is_within_svg( $node ) ) {
				continue;
			}
			$classes = preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) );
			if ( ! is_array( $classes ) ) {
				$classes = array();
			}
			foreach ( $classes as $class ) {
				if ( '' === $class ) {
					continue;
				}
				$finding = self::check_class( $class, $vocab, $html, $file );
				if ( null !== $finding ) {
					$findings[] = $finding;
				}
			}
		}

		// --- inline styles -------------------------------------------------
		foreach ( $xpath->query( '//*[@style]' ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			// Enumerated exception #1: inside (or on) an inline <svg> subtree.
			if ( self::is_within_svg( $node ) || 'svg' === strtolower( $node->nodeName ) ) {
				continue;
			}
			$style      = (string) $node->getAttribute( 'style' );
			$findings[] = self::finding(
				self::SEVERITY_ERROR,
				self::RULE_INLINE_STYLE,
				$file,
				self::line_of( $html, 'style="' . $style ),
				'style="' . $style . '"',
				'inline style attributes are prohibited on <' . strtolower( $node->nodeName ) . '>',
				'no inline style= — the only allowed exception is: ' . implode( '; ', self::INLINE_STYLE_EXCEPTIONS ),
				'move the declaration into a component/utility class in the theme stylesheet',
				''
			);
		}

		// --- event-handler attributes (fresh re-audit: previously unchecked) ----
		// A bare on* attribute (onclick, onerror, onload, ...) planted via Code lens
		// or a malformed edit reached canonical + the served front-end with ZERO
		// diagnostics -- the closed-vocabulary contract covered classes and inline
		// style= but never attributes in general, so this was a real script-injection
		// gap for any role with Code lens access (Marketer, Developer).
		foreach ( $xpath->query( '//*' ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			foreach ( $node->attributes as $attr ) {
				$name = strtolower( (string) $attr->nodeName );
				if ( 0 !== strpos( $name, 'on' ) || strlen( $name ) < 3 ) {
					continue;
				}
				$findings[] = self::finding(
					self::SEVERITY_ERROR,
					self::RULE_EVENT_HANDLER,
					$file,
					self::line_of( $html, $name . '=' ),
					$name . '="' . (string) $attr->nodeValue . '"',
					'event-handler attribute "' . $name . '" is prohibited on <' . strtolower( $node->nodeName ) . '>',
					'no on* attributes — interactivity comes from registered components/islands, never inline script',
					'remove the attribute; wire the behavior through a registered component or island instead',
					''
				);
			}
		}

		// --- javascript: URLs in href/src (script injection via a URL, not an
		// attribute name) -- same security gap class as the on* check above: a
		// closed vocabulary means nothing if <a href="javascript:..."> or an
		// <img src="javascript:..."> can still execute script.
		foreach ( $xpath->query( '//*[@href or @src]' ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			foreach ( array( 'href', 'src' ) as $attr_name ) {
				if ( ! $node->hasAttribute( $attr_name ) ) {
					continue;
				}
				$val = (string) $node->getAttribute( $attr_name );
				if ( 0 !== stripos( ltrim( $val ), 'javascript:' ) ) {
					continue;
				}
				$findings[] = self::finding(
					self::SEVERITY_ERROR,
					self::RULE_JS_URL,
					$file,
					self::line_of( $html, $attr_name . '="' . $val ),
					$attr_name . '="' . $val . '"',
					'javascript: URL in ' . $attr_name . '= is prohibited on <' . strtolower( $node->nodeName ) . '>',
					'no javascript: URLs — links/sources are real paths or URLs only',
					'replace with a real URL; wire interactivity through a registered component or island instead',
					''
				);
			}
		}

		// --- <script> injected mid-body -- outside the framework's own asset
		// enqueue, a page author has no sanctioned path to ship a <script> tag
		// inside the body. Head-level structured data
		// (<script type="application/ld+json">, inert JSON, no execution) is a
		// legitimate, pre-existing corpus pattern (see pages/index.html) and is
		// intentionally exempted; every other <script> in <body> -- with or
		// without a type attribute -- is a script-injection vector.
		foreach ( $xpath->query( '//body//script' ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			$type = strtolower( trim( (string) $node->getAttribute( 'type' ) ) );
			if ( 'application/ld+json' === $type ) {
				continue;
			}
			$findings[] = self::finding(
				self::SEVERITY_ERROR,
				self::RULE_SCRIPT_TAG,
				$file,
				self::line_of( $html, '<script' ),
				'<script>',
				'a <script> element is prohibited mid-body in authored canonical HTML',
				'no <script> tags in the body — the framework owns all script enqueue (editor assets, islands); head-level application/ld+json structured data is exempt',
				'remove the tag; wire the behavior through a registered component or island instead',
				''
			);
		}

		return $findings;
	}

	/**
	 * Check a single class token against vocabulary + grammar.
	 *
	 * @param string              $class Class name.
	 * @param array<string, true> $vocab Vocabulary set.
	 * @param string              $html  Source (for line lookup).
	 * @param string              $file  Filename.
	 * @return array<string, mixed>|null Finding, or null if the class is clean.
	 */
	private static function check_class( string $class, array $vocab, string $html, string $file ): ?array {
		$in_vocab = isset( $vocab[ $class ] );

		if ( ! $in_vocab ) {
			// ERROR: not in the closed vocabulary. Offer a nearest match.
			$suggestion = self::nearest( $class, array_keys( $vocab ) );
			return self::finding(
				self::SEVERITY_ERROR,
				self::RULE_UNKNOWN_CLASS,
				$file,
				self::line_of( $html, $class ),
				$class,
				sprintf( 'class "%s" is not in the theme stylesheet vocabulary', $class ),
				'a class defined in the declared theme stylesheet(s) (' . count( $vocab ) . ' classes in vocabulary)',
				'' !== $suggestion
					? sprintf( 'use "%s", or add .%s to the theme stylesheet if this class is intended', $suggestion, $class )
					: sprintf( 'add .%s to the theme stylesheet, or use an existing class', $class ),
				$suggestion,
				''
			);
		}

		// In vocabulary — now check grammar conformance.
		$g = Grammar::classify( $class );
		if ( $g['conforms'] ) {
			return null; // Clean.
		}

		// Defined-but-nonconforming. Numeric scale steps get their own rule id.
		$is_numeric_scale = ( Grammar::LAYER_SCALE === $g['layer'] && false !== strpos( $g['reason'], 'numeric' ) );

		return self::finding(
			self::SEVERITY_WARNING,
			$is_numeric_scale ? self::RULE_NUMERIC_SCALE : self::RULE_GRAMMAR,
			$file,
			self::line_of( $html, $class ),
			$class,
			sprintf( 'class "%s" is defined in CSS but does not conform to the grammar: %s', $class, $g['reason'] ),
			Grammar::permitted_summary(),
			$is_numeric_scale
				? 'replace the numeric step with a ladder step (xxs/xs/sm/md/lg/xl/xxl/xxxl)'
				: 'rename to fit a layer pattern, or record for later promotion (legacy classes are tolerated for the S5T launch)',
			'',
			$g['reason']
		);
	}

	/**
	 * Load HTML into DOM, detecting a fatal parse failure.
	 *
	 * Libxml is lenient, so "unparseable" here means the loader returned false or
	 * produced fatal-level errors. Non-fatal warnings (HTML5 elements, etc.) are
	 * ignored — the body is authored HTML, not XML.
	 *
	 * @param string      $html        HTML source.
	 * @param string|null $parse_error Set to an error string on failure, else null.
	 * @return \DOMDocument
	 */
	private static function load_dom( string $html, ?string &$parse_error ): \DOMDocument {
		$parse_error = null;
		$prev        = libxml_use_internal_errors( true );
		$dom         = new \DOMDocument();
		$ok          = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING );
		$errors      = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( false === $ok ) {
			$parse_error = 'loadHTML failed';
			return $dom;
		}
		foreach ( $errors as $e ) {
			if ( LIBXML_ERR_FATAL === $e->level ) {
				$parse_error = trim( $e->message ) . ' (line ' . $e->line . ')';
				break;
			}
		}

		return $dom;
	}

	/**
	 * Whether a node has an <svg> ancestor (enumerated inline-style exception #1,
	 * and the reason SVG-internal classes are skipped).
	 *
	 * @param \DOMElement $node Node.
	 * @return bool
	 */
	private static function is_within_svg( \DOMElement $node ): bool {
		$p = $node->parentNode;
		while ( $p instanceof \DOMElement ) {
			if ( 'svg' === strtolower( $p->nodeName ) ) {
				return true;
			}
			$p = $p->parentNode;
		}
		return false;
	}

	/**
	 * 1-based line number of the first occurrence of $needle in $html, or 0.
	 *
	 * DOMDocument does not expose reliable source offsets for HTML, so we locate
	 * the offending token in the raw source. This is honest (it points at real
	 * bytes) and enough for an agent to find the line.
	 *
	 * @param string $html   Source.
	 * @param string $needle Text to locate.
	 * @return int
	 */
	private static function line_of( string $html, string $needle ): int {
		if ( '' === $needle ) {
			return 0;
		}
		$pos = strpos( $html, $needle );
		if ( false === $pos ) {
			return 0;
		}
		return substr_count( $html, "\n", 0, $pos ) + 1;
	}

	/**
	 * Nearest vocabulary class to $class by edit distance, for did-you-mean.
	 * Returns '' when nothing is close enough.
	 *
	 * @param string             $class Unknown class.
	 * @param array<int, string> $vocab Vocabulary class names.
	 * @return string Suggestion, or '' if none is close.
	 */
	private static function nearest( string $class, array $vocab ): string {
		$best      = '';
		$best_dist = PHP_INT_MAX;
		foreach ( $vocab as $candidate ) {
			$d = levenshtein( $class, $candidate );
			if ( $d < $best_dist ) {
				$best_dist = $d;
				$best      = $candidate;
			}
		}
		$threshold = (int) max( 2, floor( strlen( $class ) / 2 ) );
		return ( $best_dist <= $threshold ) ? $best : '';
	}

	/**
	 * Assemble a finding record.
	 *
	 * @param string $severity     error|warning.
	 * @param string $rule         Rule id.
	 * @param string $file         Filename.
	 * @param int    $line         1-based line.
	 * @param string $token        Offending token.
	 * @param string $message      What is wrong.
	 * @param string $permitted    What the contract permits.
	 * @param string $repair       Likely repair.
	 * @param string $did_you_mean Nearest-match suggestion (may be empty).
	 * @param string $detail       Extra machine detail (grammar reason, may be empty).
	 * @return array<string, mixed>
	 */
	private static function finding( string $severity, string $rule, string $file, int $line, string $token, string $message, string $permitted, string $repair, string $did_you_mean, string $detail = '' ): array {
		return array(
			'severity'     => $severity,
			'rule'         => $rule,
			'file'         => $file,
			'line'         => $line,
			'token'        => $token,
			'message'      => $message,
			'permitted'    => $permitted,
			'repair'       => $repair,
			'did_you_mean' => $did_you_mean,
			'detail'       => $detail,
		);
	}

	/**
	 * Whether a findings list contains any ERROR.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return bool
	 */
	public static function has_errors( array $findings ): bool {
		foreach ( $findings as $f ) {
			if ( self::SEVERITY_ERROR === $f['severity'] ) {
				return true;
			}
		}
		return false;
	}
}
