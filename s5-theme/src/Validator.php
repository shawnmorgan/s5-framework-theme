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
	public const RULE_SRCDOC        = 's5.attr.srcdoc';

	/**
	 * Attributes whose value a browser resolves as a URL, and which therefore can
	 * carry an executable scheme. Checked bare, so namespaced forms (`xlink:href`)
	 * match too. Audit C2: the rule used to cover only href/src, leaving
	 * `formaction` and `xlink:href` as proven-executable holes.
	 *
	 * @var array<int, string>
	 */
	private const URL_ATTRIBUTES = array(
		'href',
		'src',
		'formaction',
		'action',
		'poster',
		'data',
		'background',
		'cite',
		'longdesc',
		'usemap',
		'profile',
		'manifest',
	);

	/**
	 * Schemes that execute script when a browser follows the URL.
	 *
	 * `data:` is deliberately NOT a blanket entry: `data:image/png;base64,...` and
	 * `data:image/svg+xml,...` are legitimate inline-asset idioms, and rejecting
	 * them wholesale would be exactly the false-positive friction this validator
	 * has been bitten by before. Only the renderable-document media types are
	 * dangerous (they navigate into an attacker-authored page), so those are
	 * matched separately by DANGEROUS_DATA_TYPES below.
	 *
	 * @var array<int, string>
	 */
	private const DANGEROUS_SCHEMES = array( 'javascript', 'vbscript' );

	/**
	 * `data:` media types that a browser will parse as a scripting document.
	 * `data:image/svg+xml` is included: SVG is a document format and can carry
	 * <script>, so it executes when navigated to (it does NOT execute inside a
	 * plain <img>, but this rule cannot assume the element).
	 *
	 * @var array<int, string>
	 */
	private const DANGEROUS_DATA_TYPES = array( 'text/html', 'application/xhtml', 'image/svg' );

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
	 * @param bool                $dev   Developer authoring (code lens). When true, an
	 *                                   UNKNOWN CLASS is a WARNING, not an ERROR: the
	 *                                   Developer defines the vocabulary, so a class the
	 *                                   stylesheet does not know yet is a nudge ("not in
	 *                                   the stylesheet yet"), not a wall. SECURITY rules
	 *                                   (inline style, on* handlers, javascript: URLs,
	 *                                   <script>) stay ERROR for EVERYONE — those are XSS,
	 *                                   not vocabulary, and a Developer is not exempt.
	 *                                   Defaults false so importer/marketer/client keep
	 *                                   the hard closed-vocabulary gate.
	 * @return array<int, array<string, mixed>> Findings; empty means clean.
	 */
	public static function validate( string $html, array $vocab, string $file = '', bool $dev = false ): array {
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
				$finding = self::check_class( $class, $vocab, $html, $file, $dev );
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

		// --- script-bearing URLs in ANY url-valued attribute (script injection via a
		// URL, not an attribute name) -- same security gap class as the on* check
		// above: a closed vocabulary means nothing if a URL can still execute script.
		//
		// AUDIT C2: this rule previously scanned ONLY href/src, and only with
		// `stripos( ltrim( $val ), 'javascript:' )`. Other url-valued attributes were
		// unguarded and PROVEN to execute in a real browser -- `formaction` on a
		// <button> and `xlink:href` on an SVG <a>. And because `ltrim()` strips only
		// LEADING whitespace, a control character EMBEDDED in the scheme
		// ("java\tscript:", "java\nscript:") slipped through, while browsers strip
		// those bytes and execute the URL anyway.
		//
		// Both halves are closed here: the attribute list is the full url-valued set,
		// and the value is normalized (entities decoded, all C0/space bytes removed)
		// before the scheme comparison -- so the check sees what the BROWSER will see
		// rather than what the author typed.
		foreach ( $xpath->query( '//*' ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			foreach ( $node->attributes as $attr ) {
				$attr_name = strtolower( (string) $attr->nodeName );
				// Match the bare name and any namespaced form (xlink:href, xml:href).
				$bare = $attr_name;
				if ( false !== strpos( $bare, ':' ) ) {
					$bare = substr( $bare, strrpos( $bare, ':' ) + 1 );
				}
				if ( ! in_array( $bare, self::URL_ATTRIBUTES, true ) ) {
					continue;
				}
				$scheme = self::dangerous_scheme( (string) $attr->nodeValue );
				if ( '' === $scheme ) {
					continue;
				}
				$findings[] = self::finding(
					self::SEVERITY_ERROR,
					self::RULE_JS_URL,
					$file,
					self::line_of( $html, $attr_name . '=' ),
					$attr_name . '="' . (string) $attr->nodeValue . '"',
					$scheme . ': URL in ' . $attr_name . '= is prohibited on <' . strtolower( $node->nodeName ) . '>',
					'no script-bearing URLs — links/sources are real paths or URLs only',
					'replace with a real URL; wire interactivity through a registered component or island instead',
					''
				);
			}
		}

		// --- srcdoc= -- a full HTML document smuggled inside an attribute. Its
		// content is parsed as markup by the browser but is opaque to every rule
		// above (the <script> sweep below sees an attribute VALUE, not a script
		// element), so an entire document -- scripts included -- rode in unchecked.
		// PROVEN to execute. There is no sanctioned authoring use for it here.
		foreach ( $xpath->query( '//*[@srcdoc]' ) as $node ) {
			if ( ! ( $node instanceof \DOMElement ) ) {
				continue;
			}
			$findings[] = self::finding(
				self::SEVERITY_ERROR,
				self::RULE_SRCDOC,
				$file,
				self::line_of( $html, 'srcdoc=' ),
				'srcdoc="…"',
				'srcdoc= is prohibited on <' . strtolower( $node->nodeName ) . '>',
				'no inline iframe documents — embed a real URL, or use a registered component',
				'remove srcdoc and point src= at a real URL instead',
				''
			);
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
	 * @param bool                $dev   Developer authoring — unknown class is a WARNING.
	 * @return array<string, mixed>|null Finding, or null if the class is clean.
	 */
	private static function check_class( string $class, array $vocab, string $html, string $file, bool $dev = false ): ?array {
		$in_vocab = isset( $vocab[ $class ] );

		if ( ! $in_vocab ) {
			// Not in the closed vocabulary. For a MARKETER/CLIENT (and the importer) this
			// is an ERROR — they compose from the developer-curated vocabulary and may not
			// invent classes. For a DEVELOPER authoring in the code lens it is a WARNING:
			// the developer DEFINES the vocabulary, so a not-yet-declared class is a nudge
			// to add it to the stylesheet, never a wall. Message adapts to the audience.
			$suggestion = self::nearest( $class, array_keys( $vocab ) );
			if ( $dev ) {
				return self::finding(
					self::SEVERITY_WARNING,
					self::RULE_UNKNOWN_CLASS,
					$file,
					self::line_of( $html, $class ),
					$class,
					sprintf( 'class "%s" is not in the theme stylesheet yet', $class ),
					'define .' . $class . ' in the theme stylesheet (CSS tab) so pages that use it validate for every role',
					sprintf( 'add .%s to the theme stylesheet to make it part of the design system', $class ),
					$suggestion,
					''
				);
			}
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
	/**
	 * Name the dangerous URL scheme a value resolves to, or '' when it is safe.
	 *
	 * Normalizes the way a BROWSER does before comparing, which is the whole point:
	 * an author-typed value and the value the browser acts on are not the same
	 * string. Two normalizations, both of which were live bypasses (audit C2):
	 *
	 *   1. HTML entities are decoded — `java&#115;cript:` is `javascript:`.
	 *   2. ALL C0 control bytes and spaces are stripped THROUGHOUT the value, not
	 *      just trimmed from the front — browsers ignore TAB/LF/CR anywhere inside
	 *      the scheme, so `java\tscript:` navigates exactly like `javascript:`.
	 *      The previous `ltrim()` only handled leading whitespace.
	 *
	 * The comparison is then a case-insensitive prefix test against the scheme
	 * allowlist's inverse: a bare `javascript` with no colon is inert, so the colon
	 * is required.
	 *
	 * @param string $value Raw attribute value.
	 * @return string Scheme name ('javascript', 'vbscript', 'data'), or '' if safe.
	 */
	private static function dangerous_scheme( string $value ): string {
		// Decode entities first: the DOM already decodes most, but a double-encoded
		// value (`&amp;#115;`) survives one pass, so decode until it stabilizes.
		$decoded = $value;
		for ( $i = 0; $i < 3; $i++ ) {
			$next = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $next === $decoded ) {
				break;
			}
			$decoded = $next;
		}
		// Strip every C0 control byte and space, anywhere in the value.
		$normalized = preg_replace( '/[\x00-\x20]+/', '', $decoded );
		if ( null === $normalized || '' === $normalized ) {
			return '';
		}
		foreach ( self::DANGEROUS_SCHEMES as $scheme ) {
			if ( 0 === stripos( $normalized, $scheme . ':' ) ) {
				return $scheme;
			}
		}
		// data: only when the media type is a renderable document (see the constant).
		if ( 0 === stripos( $normalized, 'data:' ) ) {
			$type = substr( $normalized, 5 );
			foreach ( self::DANGEROUS_DATA_TYPES as $bad ) {
				if ( 0 === stripos( $type, $bad ) ) {
					return 'data';
				}
			}
		}
		return '';
	}

	private static function nearest( string $class, array $vocab ): string {
		$best      = '';
		$best_dist = PHP_INT_MAX;
		foreach ( $vocab as $candidate ) {
			// Audit L2: the documented contract is a MAP (class => true), so callers
			// pass `array_keys()`. A list-shaped vocab would yield int candidates and
			// fatal in levenshtein(); skip defensively rather than crash the save.
			if ( ! is_string( $candidate ) ) {
				continue;
			}
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
