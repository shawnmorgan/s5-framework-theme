<?php
/**
 * S5 Framework — Style controls manifest (R3).
 *
 * The single source of truth for the editor's Style-tab controls. Each control is an
 * AXIS: a named group with a fixed, mutually-exclusive set of sanctioned classes (a
 * "class ladder"). The editor may only ever SWAP one class for another WITHIN an axis
 * — never write a free-form class. The server's `mode:class` save enforces exactly
 * that: it removes any class belonging to the chosen axis's set and adds the one the
 * control picked. Anything outside a sanctioned swap is rejected with diagnostics
 * (standing loud-validation model), and there is NO free-form class path here at any
 * capability level (free class editing stays the Design+/code path, not this endpoint).
 *
 * Axes are derived from the theme's REAL closed vocabulary (utilities.css /
 * components.css) so a control can never offer a class the validator would reject.
 * Today the theme's utility layer sanctions a text-alignment axis (u-text-*); a size
 * or spacing ladder appears here automatically once the design system defines one
 * (e.g. text-scale-* / space-top-*). Until then those axes are absent by construction —
 * the UI shows them disabled with a "no scale defined yet" note rather than inventing
 * classes.
 *
 * @package S5\Theme
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the axis manifest that both the editor UI and the class-swap save read.
 */
final class ControlsManifest {

	/**
	 * Candidate axes, each with a matcher over the vocabulary and human labels. An axis
	 * only appears in the manifest if the vocabulary actually contains ≥2 of its classes
	 * (a one-option axis is not a swap). Add a new axis here; it lights up when the
	 * design system defines its classes.
	 *
	 * Each entry:
	 *   key     — stable axis id (the `mode:class` param).
	 *   label   — UI label.
	 *   control — 'segmented' | 'slider'.
	 *   match   — regex the class names in this axis match.
	 *   order   — optional ordering of the matched classes (by suffix); else sorted.
	 *
	 * @return array<int, array{key:string,label:string,control:string,match:string,order:array<int,string>}>
	 */
	private static function candidates(): array {
		return array(
			array(
				'key'     => 'text-align',
				'label'   => 'Text alignment',
				'control' => 'segmented',
				'match'   => '/^u-text-(left|center|right)$/',
				'order'   => array( 'u-text-left', 'u-text-center', 'u-text-right' ),
			),
			array(
				'key'     => 'text-scale',
				'label'   => 'Text size',
				'control' => 'slider',
				'match'   => '/^text-scale-(xxs|xs|sm|md|lg|xl|xxl|xxxl)$/',
				'order'   => array(),
			),
			array(
				'key'     => 'space-top',
				'label'   => 'Space top',
				'control' => 'slider',
				'match'   => '/^space-top-(xxs|xs|sm|md|lg|xl|xxl|xxxl)$/',
				'order'   => array(),
			),
			array(
				'key'     => 'space-bottom',
				'label'   => 'Space bottom',
				'control' => 'slider',
				'match'   => '/^space-bottom-(xxs|xs|sm|md|lg|xl|xxl|xxxl)$/',
				'order'   => array(),
			),
		);
	}

	/**
	 * The manifest of AVAILABLE axes for the current vocabulary: only axes with ≥2
	 * sanctioned classes. Each axis carries its ordered class set.
	 *
	 * @return array<int, array{key:string,label:string,control:string,classes:array<int,string>}>
	 */
	public static function axes(): array {
		$vocab = self::vocabulary_classes();
		$out   = array();

		foreach ( self::candidates() as $axis ) {
			$matched = array_values(
				array_filter(
					$vocab,
					static function ( string $c ) use ( $axis ): bool {
						return (bool) preg_match( $axis['match'], $c );
					}
				)
			);
			if ( count( $matched ) < 2 ) {
				continue; // Not a real swap in this vocabulary.
			}
			$matched = self::order_classes( $matched, $axis['order'] );
			$out[]   = array(
				'key'     => $axis['key'],
				'label'   => $axis['label'],
				'control' => $axis['control'],
				'classes' => $matched,
			);
		}
		return $out;
	}

	/**
	 * The full sanctioned class set for a single axis, or empty if the axis is not
	 * available. Used by the class-swap save to know which classes to strip before
	 * adding the chosen one.
	 *
	 * @param string $axis_key Axis id.
	 * @return array<int, string>
	 */
	public static function classes_for_axis( string $axis_key ): array {
		foreach ( self::axes() as $axis ) {
			if ( $axis['key'] === $axis_key ) {
				return $axis['classes'];
			}
		}
		return array();
	}

	/**
	 * The list of candidate axes (available or not) with an `available` flag — so the
	 * UI can render a disabled control + "no scale defined yet" note for axes the
	 * vocabulary does not yet back.
	 *
	 * @return array<int, array{key:string,label:string,control:string,available:bool,classes:array<int,string>}>
	 */
	public static function ui_axes(): array {
		$available = array();
		foreach ( self::axes() as $a ) {
			$available[ $a['key'] ] = $a['classes'];
		}
		$out = array();
		foreach ( self::candidates() as $axis ) {
			$has   = isset( $available[ $axis['key'] ] );
			$out[] = array(
				'key'       => $axis['key'],
				'label'     => $axis['label'],
				'control'   => $axis['control'],
				'available' => $has,
				'classes'   => $has ? $available[ $axis['key'] ] : array(),
			);
		}
		return $out;
	}

	/**
	 * Order a matched class set by an explicit order list, then append any leftovers
	 * sorted. Keeps sliders/segments in a sensible ladder order.
	 *
	 * @param array<int,string> $classes Matched classes.
	 * @param array<int,string> $order   Preferred order.
	 * @return array<int,string>
	 */
	private static function order_classes( array $classes, array $order ): array {
		if ( empty( $order ) ) {
			// Ladder suffix order (xxs..xxxl) if present, else alphabetical.
			$ladder = array( 'xxs', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl', 'xxxl' );
			usort(
				$classes,
				static function ( string $a, string $b ) use ( $ladder ): int {
					$sa = preg_replace( '/^.*-/', '', $a );
					$sb = preg_replace( '/^.*-/', '', $b );
					$ia = array_search( $sa, $ladder, true );
					$ib = array_search( $sb, $ladder, true );
					if ( false !== $ia && false !== $ib ) {
						return $ia <=> $ib;
					}
					return strcmp( $a, $b );
				}
			);
			return $classes;
		}
		$ranked = array();
		foreach ( $order as $c ) {
			if ( in_array( $c, $classes, true ) ) {
				$ranked[] = $c;
			}
		}
		foreach ( $classes as $c ) {
			if ( ! in_array( $c, $ranked, true ) ) {
				$ranked[] = $c;
			}
		}
		return $ranked;
	}

	/**
	 * The theme's closed vocabulary class names (utilities + components), or empty.
	 *
	 * @return array<int, string>
	 */
	private static function vocabulary_classes(): array {
		try {
			$repo  = Cli\ValidateCommand::repo_root();
			$vocab = Stylesheet::load_vocabulary( $repo );
		} catch ( \RuntimeException $e ) {
			return array();
		}
		$classes = isset( $vocab['classes'] ) && is_array( $vocab['classes'] ) ? array_keys( $vocab['classes'] ) : array();
		return array_values( array_unique( array_map( 'strval', $classes ) ) );
	}
}
