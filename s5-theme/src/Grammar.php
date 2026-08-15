<?php
/**
 * S5 Framework — Authoring Contract class-name grammar (Part A4/A3).
 *
 * @package S5\Plugin
 */

declare( strict_types=1 );

namespace S5;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies a class name into its Authoring Contract layer and reports whether
 * it conforms to the grammar (A4) and step ladder (A3).
 *
 * A4 layers (machine-parseable from the pattern):
 *   - utility  : `u-` prefix                      (.u-text-center)
 *   - element  : exactly one single underscore    (.pricing-card_title)
 *   - modifier : contains `--`                     (.btn--secondary)
 *   - scale    : `{category}-{step}`               (.text-xl, .space-top-lg)
 *   - block    : dashes / single word              (.pricing-card, .hero)
 *
 * A3 ladder (the ONLY permitted scale steps): xxs xs sm md lg xl xxl xxxl.
 * Numeric steps (text-2, space-4) are grammar-nonconforming (WARNING per the
 * Step 4 severity model), never ERROR — the class still has to exist in CSS.
 */
final class Grammar {

	public const LAYER_UTILITY  = 'utility';
	public const LAYER_ELEMENT  = 'element';
	public const LAYER_MODIFIER = 'modifier';
	public const LAYER_SCALE    = 'scale';
	public const LAYER_BLOCK    = 'block';
	public const LAYER_UNKNOWN  = 'unknown';

	/**
	 * The A3 step ladder. Slots are theme law; nobody adds slots.
	 *
	 * @var array<int, string>
	 */
	public const LADDER = array( 'xxs', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl', 'xxxl' );

	/**
	 * Scale categories that take a ladder step (A5: the generated scale classes).
	 *
	 * MUST stay ordered longest-prefix-first: classify_scale() below does a simple
	 * strpos() prefix match and returns on the first hit, so a shorter category
	 * that is itself a prefix of a longer one (space vs space-top/space-bottom)
	 * would otherwise shadow it and misparse the step (e.g. "space-top-sm" would
	 * match "space-" first, leaving step="top-sm" -> wrongly rejected as
	 * off-ladder). Fixed a matching bug (fresh re-audit): 'text' was listed
	 * instead of the actually-shipped 'text-scale' prefix (utilities.css ships
	 * .text-scale-xxs..xxxl, never bare .text-xxs), which meant every real
	 * text-scale-* class a user applied via the Style tab was rejected on save
	 * as "off-ladder scale step" even though it's a valid, shipped utility.
	 *
	 * @var array<int, string>
	 */
	// 'text' stays a DISTINCT category from 'text-scale': the Step 4 acceptance
	// suite's own fixture (tests/acceptance-step4.sh, "4/6 Numeric scale class")
	// deliberately defines a legacy/alias class like .text-2 (bare `text-` prefix,
	// no `-scale-`) to prove the numeric-scale-alias WARNING path works — that is a
	// real, intentionally-supported pattern, separate from the real text-scale-*
	// ladder utilities.css ships (.text-scale-xxs..xxxl). Both must be recognized.
	public const SCALE_CATEGORIES = array( 'text-scale', 'text', 'space-top', 'space-bottom', 'space', 'radius' );

	/**
	 * Classify a class name.
	 *
	 * @param string $class Class name (no leading dot).
	 * @return array{layer: string, conforms: bool, reason: string}
	 *         `layer` is one of the LAYER_* constants; `conforms` is whether it
	 *         matches the grammar cleanly; `reason` explains a nonconformance
	 *         (empty when it conforms).
	 */
	public static function classify( string $class ): array {
		// Utility layer: `u-` prefix.
		if ( 0 === strpos( $class, 'u-' ) ) {
			return self::result( self::LAYER_UTILITY, true, '' );
		}

		// Modifier: contains a double dash.
		if ( false !== strpos( $class, '--' ) ) {
			// Must be block--modifier: non-empty base and modifier around `--`.
			if ( preg_match( '/^[A-Za-z][A-Za-z0-9-]*--[A-Za-z0-9-]+$/', $class ) ) {
				return self::result( self::LAYER_MODIFIER, true, '' );
			}
			return self::result( self::LAYER_MODIFIER, false, 'malformed modifier: expected block--modifier' );
		}

		// Element: exactly one single underscore separating block_element.
		if ( false !== strpos( $class, '_' ) ) {
			if ( preg_match( '/^[A-Za-z][A-Za-z0-9-]*_[A-Za-z0-9-]+$/', $class ) ) {
				return self::result( self::LAYER_ELEMENT, true, '' );
			}
			return self::result( self::LAYER_ELEMENT, false, 'malformed element: expected block_element with a single underscore' );
		}

		// Scale: `{category}-{step}` where category is a scale category.
		$scale = self::classify_scale( $class );
		if ( null !== $scale ) {
			return $scale;
		}

		// Block: a plain dash-cased identifier (or single word).
		if ( preg_match( '/^[A-Za-z][A-Za-z0-9]*(-[A-Za-z0-9]+)*$/', $class ) ) {
			return self::result( self::LAYER_BLOCK, true, '' );
		}

		return self::result( self::LAYER_UNKNOWN, false, 'matches no Authoring Contract layer grammar' );
	}

	/**
	 * Try to classify $class as a scale class `{category}-{step}`.
	 *
	 * @param string $class Class name.
	 * @return array{layer: string, conforms: bool, reason: string}|null
	 *         A scale result, or null if this is not a scale class at all.
	 */
	private static function classify_scale( string $class ): ?array {
		foreach ( self::SCALE_CATEGORIES as $cat ) {
			$prefix = $cat . '-';
			if ( 0 !== strpos( $class, $prefix ) ) {
				continue;
			}
			$step = substr( $class, strlen( $prefix ) );
			if ( '' === $step ) {
				continue;
			}
			if ( in_array( $step, self::LADDER, true ) ) {
				return self::result( self::LAYER_SCALE, true, '' );
			}
			// A numeric step is the canonical nonconforming scale alias.
			if ( preg_match( '/^[0-9]/', $step ) ) {
				return self::result(
					self::LAYER_SCALE,
					false,
					sprintf( 'numeric scale step "%s"; the ladder is %s (never numeric)', $step, implode( '/', self::LADDER ) )
				);
			}
			// Non-numeric, non-ladder step under a scale category is still off-ladder.
			return self::result(
				self::LAYER_SCALE,
				false,
				sprintf( 'off-ladder scale step "%s"; permitted steps: %s', $step, implode( '/', self::LADDER ) )
			);
		}

		return null;
	}

	/**
	 * Build a classify() result tuple.
	 *
	 * @param string $layer    Layer constant.
	 * @param bool   $conforms Whether it conforms.
	 * @param string $reason   Nonconformance reason (empty if conforms).
	 * @return array{layer: string, conforms: bool, reason: string}
	 */
	private static function result( string $layer, bool $conforms, string $reason ): array {
		return array(
			'layer'    => $layer,
			'conforms' => $conforms,
			'reason'   => $reason,
		);
	}

	/**
	 * A one-line human description of the permitted grammar, for diagnostics.
	 *
	 * @return string
	 */
	public static function permitted_summary(): string {
		return 'block (dash-case: hero, pricing-card) · element (block_element) · '
			. 'modifier (block--modifier) · utility (u-*) · scale ({text-scale|space|space-top|space-bottom|radius}-{'
			. implode( '|', self::LADDER ) . '})';
	}
}
