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
	 * Custom-element registry file, relative to the SITE root (site/). Holds the
	 * elements a Developer adds through the UI ("New element"), as a JSON array of
	 * {key,label,selector,icon,sample}. This is the seed of the v2 element-atom
	 * registry (ATOMIC-SPEC §5) — v1.x stores identity + selector; prop capabilities
	 * arrive in v2. The CSS itself lives in components.css like the built-ins, so a
	 * custom element is real closed-vocabulary the validator sees.
	 */
	private const REGISTRY_REL = 'atoms/elements.json';

	/**
	 * The full registry: the built-in primitives plus any Developer-added custom
	 * elements (custom entries carry `custom => true`). Deduped by key — a custom
	 * entry can never shadow a built-in key.
	 *
	 * @return array<int, array{key:string,label:string,selector:string,icon:string,sample:string,custom:bool}>
	 */
	public static function registry(): array {
		$out  = array();
		$keys = array();
		foreach ( self::REGISTERED as $el ) {
			$el['custom'] = false;
			$out[]        = $el;
			$keys[]       = $el['key'];
		}
		foreach ( self::read_custom_registry() as $el ) {
			if ( in_array( $el['key'], $keys, true ) ) {
				continue; // never shadow a built-in.
			}
			$el['custom'] = true;
			$out[]        = $el;
			$keys[]       = $el['key'];
		}
		return $out;
	}

	/**
	 * The registered elements, each with its CURRENT rule text read from the file.
	 *
	 * Every registry entry (built-in + custom) is returned; entries whose selector
	 * has no rule in the file carry `rule => ''` and `defined => false` so the UI can
	 * show them as "not yet defined" rather than dropping them silently.
	 *
	 * @return array<int, array{key:string,label:string,selector:string,icon:string,sample:string,custom:bool,rule:string,defined:bool}>
	 */
	public static function list_elements(): array {
		$css = self::read_layer_css();
		$out = array();
		foreach ( self::registry() as $el ) {
			$rule          = self::extract_rule( $css, $el['selector'] );
			$el['rule']    = $rule;
			$el['defined'] = ( '' !== $rule );
			$out[]         = $el;
		}
		return $out;
	}

	/**
	 * Register a NEW custom element from the UI: validate identity, append it to the
	 * registry file, and seed its CSS rule in components.css. The caller recompiles
	 * the bundle. Idempotency + collision are enforced (a key/selector already in the
	 * built-in set or the custom registry is refused).
	 *
	 * @param string $label     Human label (e.g. "Badge").
	 * @param string $selector  CSS class selector (e.g. ".badge") — a single class.
	 * @param string $css       Starting declarations (no braces); may be '' to seed a stub.
	 * @param string $icon      Optional FontAwesome glyph (e.g. "fa-tag"); defaulted if empty.
	 * @return array{key:string}|\WP_Error The new element key, or a WP_Error.
	 */
	public static function create_element( string $label, string $selector, string $css = '', string $icon = '' ) {
		$label    = trim( wp_strip_all_tags( $label ) );
		$selector = trim( $selector );
		$icon     = trim( $icon );

		if ( '' === $label ) {
			return new \WP_Error( 's5_el_label', __( 'Give the element a name.', 's5' ), array( 'status' => 400 ) );
		}
		// Selector must be a SINGLE class selector (.foo or .foo-bar or .block__el /
		// .block--mod) — no descendant/compound/tag selectors, so it stays a clean
		// vocabulary entry the validator can enforce.
		if ( ! preg_match( '/^\.[a-z][a-z0-9]*(?:[-_]{1,2}[a-z0-9]+)*$/i', $selector ) ) {
			return new \WP_Error( 's5_el_selector', __( 'Selector must be a single class, e.g. .badge or .price-tag.', 's5' ), array( 'status' => 400 ) );
		}

		$key = self::selector_to_key( $selector );

		// Collision: never duplicate a built-in or an existing custom key/selector.
		foreach ( self::registry() as $existing ) {
			if ( $existing['key'] === $key || strcasecmp( $existing['selector'], $selector ) === 0 ) {
				return new \WP_Error( 's5_el_exists', __( 'An element with that name or selector already exists.', 's5' ), array( 'status' => 409 ) );
			}
		}

		// Seed CSS: a stub if none given, so the element is immediately "defined" and
		// visibly stylable. Validated by the SAME rules as an edit (no braces/markup,
		// no dangerous values, well-formed) so the create path is not a weaker door.
		$decls = trim( str_replace( "\r\n", "\n", $css ) );
		if ( '' === $decls ) {
			$decls = 'display: inline-block;';
		}
		$invalid = self::validate_declarations( $decls );
		if ( null !== $invalid ) {
			return $invalid;
		}

		// Two writes must both land or neither: the CSS rule and the registry entry.
		// Do the CSS write FIRST (the failure-prone one — the file may be read-only),
		// and only record the registry entry once the CSS is safely on disk. If the CSS
		// write fails, nothing was recorded, so the name stays free to retry (no
		// orphaned, un-recreatable half-element).
		$css_before = self::read_layer_css();
		$rebuilt    = self::replace_rule( $css_before, $selector, $decls );
		if ( is_wp_error( $rebuilt ) ) {
			return $rebuilt;
		}
		$written = Settings::write_css_layer( self::LAYER_KEY, $rebuilt );
		if ( is_wp_error( $written ) ) {
			return $written; // CSS not written → registry untouched → name recreatable.
		}

		$entry = array(
			'key'      => $key,
			'label'    => $label,
			'selector' => $selector,
			'icon'     => '' !== $icon ? $icon : 'fa-shapes',
			'sample'   => $label,
		);
		$saved = self::write_custom_registry( array_merge( self::read_custom_registry(), array( $entry ) ) );
		if ( is_wp_error( $saved ) ) {
			// Registry write failed AFTER the CSS landed — roll the CSS back so the two
			// stores stay consistent and the name is recreatable.
			Settings::write_css_layer( self::LAYER_KEY, $css_before );
			return $saved;
		}

		return array( 'key' => $key );
	}

	/**
	 * A stable registry key from a class selector ('.price-tag' → 'price-tag',
	 * '.btn--ghost' → 'btn-ghost').
	 *
	 * @param string $selector Class selector.
	 * @return string
	 */
	private static function selector_to_key( string $selector ): string {
		$k = strtolower( ltrim( $selector, '.' ) );
		$k = (string) preg_replace( '/[^a-z0-9]+/', '-', $k );
		return trim( $k, '-' );
	}

	/**
	 * Read the custom-element registry (validated, safe entries only). Returns [] if
	 * the file is absent or unreadable — custom elements are purely additive.
	 *
	 * @return array<int, array{key:string,label:string,selector:string,icon:string,sample:string}>
	 */
	private static function read_custom_registry(): array {
		$path = self::registry_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local registry file.
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) || empty( $row['key'] ) || empty( $row['selector'] ) ) {
				continue;
			}
			$out[] = array(
				'key'      => (string) $row['key'],
				'label'    => (string) ( $row['label'] ?? $row['key'] ),
				'selector' => (string) $row['selector'],
				'icon'     => (string) ( $row['icon'] ?? 'fa-shapes' ),
				'sample'   => (string) ( $row['sample'] ?? ( $row['label'] ?? $row['key'] ) ),
			);
		}
		return $out;
	}

	/**
	 * Write the custom-element registry (creates site/atoms/ if needed).
	 *
	 * @param array<int, array<string,string>> $entries Registry entries.
	 * @return true|\WP_Error
	 */
	private static function write_custom_registry( array $entries ) {
		$path = self::registry_path();
		if ( '' === $path ) {
			return new \WP_Error( 's5_el_registry', __( 'Could not resolve the element registry path.', 's5' ), array( 'status' => 500 ) );
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 's5_el_registry_dir', __( 'Could not create the element registry directory.', 's5' ), array( 'status' => 500 ) );
		}
		$json = wp_json_encode( array_values( $entries ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return new \WP_Error( 's5_el_registry_json', __( 'Could not encode the element registry.', 's5' ), array( 'status' => 500 ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local registry file, admin-gated.
		if ( false === file_put_contents( $path, $json . "\n" ) ) {
			return new \WP_Error( 's5_el_registry_write', __( 'Could not write the element registry.', 's5' ), array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Absolute path to the custom-element registry file (or '' if the site root can't
	 * be resolved).
	 *
	 * @return string
	 */
	private static function registry_path(): string {
		$base = trailingslashit( get_stylesheet_directory() ) . 'site/';
		return $base . self::REGISTRY_REL;
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
		foreach ( self::registry() as $candidate ) {
			if ( $candidate['key'] === $key ) {
				$el = $candidate;
				break;
			}
		}
		if ( null === $el ) {
			return new \WP_Error( 's5_el_unknown', __( 'Unknown element — only registered elements can be edited here.', 's5' ), array( 'status' => 404 ) );
		}

		$declarations = trim( str_replace( "\r\n", "\n", $declarations ) );
		$invalid      = self::validate_declarations( $declarations );
		if ( null !== $invalid ) {
			return $invalid;
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
	 * Validate a declaration body for BOTH create and edit (one door, one policy).
	 * Returns null when safe, or a WP_Error naming the first problem. This is the
	 * single place the element-CSS safety rules live so the create and edit paths can
	 * never diverge (the audit found create was the weaker door).
	 *
	 * Refuses, in order: empty; braces (rule smuggling / early close); markup ('<');
	 * CSS comments ('/*' or '*' + '/', which can hide a smuggled construct past the
	 * line-wise well-formedness check); dangerous URL schemes and expression() inside
	 * a value (javascript:/vbscript:/data: script vectors, IE expression()); then the
	 * line-wise `property: value` well-formedness check.
	 *
	 * @param string $declarations Trimmed declaration body (no braces expected).
	 * @return \WP_Error|null Null when safe.
	 */
	private static function validate_declarations( string $declarations ) {
		if ( '' === $declarations ) {
			return new \WP_Error( 's5_el_empty', __( 'The element has no CSS. Add at least one declaration.', 's5' ), array( 'status' => 400 ) );
		}
		if ( false !== strpos( $declarations, '{' ) || false !== strpos( $declarations, '}' ) ) {
			return new \WP_Error( 's5_el_braces', __( 'Remove the braces — edit only the declarations inside the rule.', 's5' ), array( 'status' => 400 ) );
		}
		if ( false !== strpos( $declarations, '<' ) ) {
			return new \WP_Error( 's5_el_markup', __( 'That is not valid CSS.', 's5' ), array( 'status' => 400 ) );
		}
		// CSS comments could hide a smuggled construct from the line-wise check below.
		if ( false !== strpos( $declarations, '/*' ) || false !== strpos( $declarations, '*/' ) ) {
			return new \WP_Error( 's5_el_comment', __( 'Remove CSS comments from element declarations.', 's5' ), array( 'status' => 400 ) );
		}
		// Dangerous value vectors: script-bearing URL schemes and IE expression().
		if ( preg_match( '/(?:javascript|vbscript|data)\s*:/i', $declarations ) || preg_match( '/expression\s*\(/i', $declarations ) ) {
			return new \WP_Error( 's5_el_danger', __( 'That value is not allowed (script/expression URLs are blocked).', 's5' ), array( 'status' => 400 ) );
		}
		if ( ! self::declarations_well_formed( $declarations ) ) {
			return new \WP_Error( 's5_el_malformed', __( 'Each line must be a `property: value;` declaration.', 's5' ), array( 'status' => 400 ) );
		}
		return null;
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
