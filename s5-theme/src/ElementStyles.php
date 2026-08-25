<?php
/**
 * S5 Framework — element-style reader/writer for the Styles tab (Phase 3, v1.x).
 *
 * The third part of the three-part Styles surface (S5-ATOMIC-DESIGN-SPEC-v0.1 §4 +
 * PRD v1.6 §8.5): TOKENS → SCALES → ELEMENT STYLES. Where {@see Styles} reads the
 * raw token VALUES, this reads the ELEMENT RULES — the CSS declaration blocks for
 * the registered primitives (heading, body, eyebrow, button, label, link, card…)
 * that live in site/styles/components.css.
 *
 * This is the DEVELOPER control-plane surface, not the page canvas. Per the spec,
 * the "no free CSS / no arbitrary values" law is scoped to the page canvas and the
 * Content/Marketer level (PRD §9, §6.x): the marketer picks from the closed class
 * vocabulary; they never write CSS. HERE — in the Styles tab, Developer-tier — is
 * exactly where that vocabulary's actual declarations are AUTHORED. Editing an
 * element writes real CSS (any property, hover/focus states, token var(--…) values
 * available and encouraged), then recompiles the bundle through the one save door
 * ({@see StyleBundle::build}). Token var() references keep edits inside the design
 * system without forcing it: a rule may still declare whatever a real front-end
 * rule needs.
 *
 * Boundaries kept:
 *   - Writes are allow-listed to site/styles/components.css only (never arbitrary
 *     paths); this reuses {@see Settings::write_css_layer}'s target discipline.
 *   - A saved rule is validated to be a single well-formed declaration block for
 *     the SAME selector it claims to edit (balanced braces, no selector smuggling,
 *     no injection past the block), so an element edit can never rewrite a
 *     different rule or inject markup.
 *   - The class SELECTORS are unchanged by an edit — only the declarations inside a
 *     known rule change, so the closed vocabulary the validator enforces is intact.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the registered element style rules from components.css.
 */
final class ElementStyles {

	/**
	 * The CSS layer file that holds the element rules. Allow-listed key into
	 * {@see Settings::write_css_layer} (Settings::CSS_FILES['components']).
	 */
	private const LAYER_KEY = 'components';

	/**
	 * The registered element primitives, in display order. Each is the front-edge
	 * of the element-atom set (S5-ATOMIC-DESIGN-SPEC §6): a human label, the CSS
	 * SELECTOR whose rule it edits, and a FontAwesome glyph for the card. The
	 * selector must exist in components.css for the element to be listed (an entry
	 * with no rule is shown as "not yet defined" rather than invented).
	 *
	 * These are the primitives an agent handed the theme builds around, and the
	 * primitives a developer restyles here. v2 turns this static list into the
	 * editable element-atom REGISTRY (prop capabilities, agent contract); v1.x
	 * surfaces the real rules for the primitives the starter already ships.
	 *
	 * @var array<int, array{key:string, label:string, selector:string, icon:string, sample:string}>
	 */
	private const REGISTERED = array(
		array( 'key' => 'heading',   'label' => 'Heading',   'selector' => '.page-hero_title', 'icon' => 'fa-heading',        'sample' => 'The quick brown fox' ),
		array( 'key' => 'lede',      'label' => 'Lede / lead', 'selector' => '.lede',          'icon' => 'fa-align-left',     'sample' => 'A short introductory paragraph that leads the section.' ),
		array( 'key' => 'eyebrow',   'label' => 'Eyebrow',    'selector' => '.eyebrow',        'icon' => 'fa-tag',            'sample' => 'SECTION LABEL' ),
		array( 'key' => 'button',    'label' => 'Button',     'selector' => '.btn',            'icon' => 'fa-hand-pointer',   'sample' => 'Button' ),
		array( 'key' => 'button-primary', 'label' => 'Button · primary', 'selector' => '.btn--primary', 'icon' => 'fa-square', 'sample' => 'Primary' ),
		array( 'key' => 'button-ghost',   'label' => 'Button · ghost',   'selector' => '.btn--ghost',   'icon' => 'fa-square-full', 'sample' => 'Ghost' ),
		array( 'key' => 'card',      'label' => 'Card',       'selector' => '.card',           'icon' => 'fa-square',         'sample' => 'Card body' ),
		array( 'key' => 'price',     'label' => 'Price',      'selector' => '.price',          'icon' => 'fa-dollar-sign',    'sample' => '$49' ),
	);

	/**
	 * The registered elements, each with its CURRENT rule text read from the file.
	 *
	 * Every REGISTERED entry is returned; entries whose selector has no rule in the
	 * file carry `rule => ''` and `defined => false` so the UI can show them as
	 * "not yet defined" (a real, discoverable state — the developer can author it)
	 * rather than dropping them silently.
	 *
	 * @return array<int, array{key:string,label:string,selector:string,icon:string,sample:string,rule:string,defined:bool}>
	 */
	public static function list_elements(): array {
		$css = self::read_layer_css();
		$out = array();
		foreach ( self::REGISTERED as $el ) {
			$rule          = self::extract_rule( $css, $el['selector'] );
			$el['rule']    = $rule;
			$el['defined'] = ( '' !== $rule );
			$out[]         = $el;
		}
		return $out;
	}

	/**
	 * Look up ONE registered element by key (or null if the key is unknown).
	 *
	 * @param string $key Registered element key.
	 * @return array{key:string,label:string,selector:string,icon:string,sample:string,rule:string,defined:bool}|null
	 */
	public static function get_element( string $key ): ?array {
		foreach ( self::list_elements() as $el ) {
			if ( $el['key'] === $key ) {
				return $el;
			}
		}
		return null;
	}

	/**
	 * Persist an edited element rule into components.css and leave the bundle for the
	 * caller to recompile (same contract as {@see Styles::update_token}).
	 *
	 * Accepts the raw DECLARATIONS the user typed for the element (the body between
	 * the braces — the UI edits declarations, the selector is fixed and shown as a
	 * read-only heading). The declarations are validated (balanced, no brace/selector
	 * smuggling), then the existing rule for that selector is rewritten in place
	 * (or appended if the element was "not yet defined"). Only the named rule's body
	 * changes; every other byte of the file is preserved.
	 *
	 * @param string $key          Registered element key.
	 * @param string $declarations The CSS declarations (no braces, no selector).
	 * @return true|\WP_Error
	 */
	public static function update_element( string $key, string $declarations ) {
		$el = null;
		foreach ( self::REGISTERED as $candidate ) {
			if ( $candidate['key'] === $key ) {
				$el = $candidate;
				break;
			}
		}
		if ( null === $el ) {
			return new \WP_Error( 's5_el_unknown', __( 'Unknown element — only registered elements can be edited here.', 's5' ), array( 'status' => 404 ) );
		}

		$declarations = trim( str_replace( "\r\n", "\n", $declarations ) );

		// The body is DECLARATIONS ONLY: no braces (which would smuggle a second rule
		// or close this one early) and no '<' (markup). An empty body is refused — an
		// element with no declarations is a mistake, not a valid save (delete is a
		// different, deliberate operation not offered here).
		if ( '' === $declarations ) {
			return new \WP_Error( 's5_el_empty', __( 'The element has no CSS. Add at least one declaration.', 's5' ), array( 'status' => 400 ) );
		}
		if ( false !== strpos( $declarations, '{' ) || false !== strpos( $declarations, '}' ) ) {
			return new \WP_Error( 's5_el_braces', __( 'Remove the braces — edit only the declarations inside the rule.', 's5' ), array( 'status' => 400 ) );
		}
		if ( false !== strpos( $declarations, '<' ) ) {
			return new \WP_Error( 's5_el_markup', __( 'That is not valid CSS.', 's5' ), array( 'status' => 400 ) );
		}
		// Each non-empty line-or-segment must look like `prop: value` — a light
		// well-formedness check so a typo does not silently produce a broken bundle.
		if ( ! self::declarations_well_formed( $declarations ) ) {
			return new \WP_Error( 's5_el_malformed', __( 'Each line must be a `property: value;` declaration.', 's5' ), array( 'status' => 400 ) );
		}

		$css     = self::read_layer_css();
		$rebuilt = self::replace_rule( $css, $el['selector'], $declarations );
		if ( is_wp_error( $rebuilt ) ) {
			return $rebuilt;
		}

		// Write through the one allow-listed CSS door (components layer). The caller
		// recompiles the bundle afterwards — identical discipline to token edits.
		return Settings::write_css_layer( self::LAYER_KEY, $rebuilt );
	}

	/**
	 * Read the components layer CSS text ('' if unreadable).
	 *
	 * @return string
	 */
	private static function read_layer_css(): string {
		$path = trailingslashit( get_stylesheet_directory() ) . 'site/styles/components.css';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local declared stylesheet.
	}

	/**
	 * Extract the DECLARATIONS (between braces, trimmed) for a top-level selector's
	 * rule, or '' if the selector has no rule in the CSS.
	 *
	 * Matches a rule whose selector list is EXACTLY the target selector (so `.btn`
	 * does not match `.btn--primary`). Only the first, top-level occurrence is used;
	 * nested at-rule copies (e.g. inside @media) are intentionally not the editable
	 * base rule and are left alone.
	 *
	 * @param string $css      Stylesheet text.
	 * @param string $selector Selector to find (e.g. '.btn').
	 * @return string Declarations only (no braces), or ''.
	 */
	private static function extract_rule( string $css, string $selector ): string {
		$span = self::rule_body_span( $css, $selector );
		if ( null === $span ) {
			return '';
		}
		return trim( substr( $css, $span['start'], $span['end'] - $span['start'] ) );
	}

	/**
	 * Locate the body span (start/end byte offsets, exclusive of braces) of the rule
	 * whose selector list is EXACTLY $selector at the top level, or null.
	 *
	 * @param string $css      Stylesheet text.
	 * @param string $selector Selector to find.
	 * @return array{sel_start:int,start:int,end:int,brace_end:int}|null
	 */
	private static function rule_body_span( string $css, string $selector ): ?array {
		// A top-level rule: `^\s*<selector>\s*{ … }`. Match the selector token bounded
		// so it is the WHOLE selector list (allowing surrounding whitespace), not a
		// substring of a compound/child selector. (?m) so ^ anchors each line.
		$quoted = preg_quote( $selector, '/' );
		if ( ! preg_match( '/(?m)^[ \t]*' . $quoted . '[ \t]*\{/', $css, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$sel_start  = (int) $m[0][1];
		$brace_open = strpos( $css, '{', $sel_start );
		if ( false === $brace_open ) {
			return null;
		}
		// Find the matching close brace (rules here contain no nested braces, but scan
		// defensively so a future nested construct cannot mis-slice).
		$depth = 0;
		$len   = strlen( $css );
		for ( $i = $brace_open; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					return array(
						'sel_start' => $sel_start,
						'start'     => $brace_open + 1,
						'end'       => $i,
						'brace_end' => $i,
					);
				}
			}
		}
		return null; // unbalanced — treat as not found.
	}

	/**
	 * Return the CSS with the given selector's rule body replaced by $declarations
	 * (formatted, one declaration per line). If the selector has no rule yet, a new
	 * rule is APPENDED at end of file.
	 *
	 * @param string $css          Stylesheet text.
	 * @param string $selector     Target selector.
	 * @param string $declarations New declarations (validated, no braces).
	 * @return string|\WP_Error
	 */
	private static function replace_rule( string $css, string $selector, string $declarations ) {
		$body = self::format_declarations( $declarations );

		$span = self::rule_body_span( $css, $selector );
		if ( null === $span ) {
			// Not yet defined → append a fresh rule at end of file.
			$block = "\n" . $selector . " {\n" . $body . "\n}\n";
			return rtrim( $css, "\n" ) . "\n" . $block;
		}

		// Replace only the bytes between the braces (exclusive), preserving everything
		// before the '{' and after the '}'.
		$before = substr( $css, 0, $span['start'] );
		$after  = substr( $css, $span['end'] );
		return $before . "\n" . $body . "\n" . $after;
	}

	/**
	 * Normalize declarations: trim each line, ensure a trailing semicolon, indent one
	 * tab. Accepts either newline-separated or semicolon-run input.
	 *
	 * @param string $declarations Raw declarations (validated).
	 * @return string
	 */
	private static function format_declarations( string $declarations ): string {
		// Split on newlines first; if the user wrote it all on one line, split on ';'.
		$raw = ( false !== strpos( $declarations, "\n" ) )
			? explode( "\n", $declarations )
			: array_map(
				static function ( $s ) {
					return $s . ';';
				},
				array_filter( array_map( 'trim', explode( ';', $declarations ) ), 'strlen' )
			);

		$lines = array();
		foreach ( $raw as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( ';' !== substr( $line, -1 ) ) {
				$line .= ';';
			}
			$lines[] = "\t" . $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Light well-formedness check: every non-empty declaration segment looks like
	 * `property: value`. Catches a typo before it produces a broken bundle without
	 * pretending to be a full CSS parser (the recompile is the real gate).
	 *
	 * @param string $declarations Raw declarations.
	 * @return bool
	 */
	private static function declarations_well_formed( string $declarations ): bool {
		$segments = preg_split( '/[;\n]+/', $declarations );
		if ( ! is_array( $segments ) ) {
			return false;
		}
		$seen = false;
		foreach ( $segments as $seg ) {
			$seg = trim( $seg );
			if ( '' === $seg ) {
				continue;
			}
			$seen = true;
			// property: value — property is an identifier, value is non-empty.
			if ( ! preg_match( '/^[a-z-]+\s*:\s*\S.*$/i', $seg ) ) {
				return false;
			}
		}
		return $seen;
	}
}
