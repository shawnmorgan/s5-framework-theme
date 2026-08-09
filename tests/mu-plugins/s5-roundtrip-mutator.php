<?php
/**
 * S5 test-only round-trip MUTATOR (mu-plugin, loaded via .wp-env.json mapping).
 *
 * This exists ONLY to prove the round-trip fidelity harness can FAIL. When the
 * WordPress option `s5_roundtrip_mutate` is truthy, it injects a deliberate
 * corruption into the render path: the stored canonical body fragment gets a
 * sentinel byte-sequence appended as it is read for output. The harness
 * (tests/acceptance-step3.sh) arms the option, requests a page, and asserts the
 * body diff goes RED with a delta report; then disarms it and asserts GREEN.
 * That makes the fidelity guarantee self-verifying.
 *
 * An OPTION (not an env var) is the arming switch on purpose: the page is served
 * by the wp-env WEB container while the harness runs `wp` in the CLI container.
 * They share one database, so `wp option update` from the CLI reliably arms the
 * mutation the web server sees. Env vars would not cross that container boundary.
 *
 * It is INERT by default (option unset/`0`) and is never shipped — it lives under
 * tests/ and is only mounted into the wp-env test container.
 *
 * The mutation is applied at the meta-read seam (`get_post_metadata` for
 * `_s5_canonical_html`) rather than by editing any source file, so the negative
 * test corrupts exactly the served bytes through the real render path without
 * touching the fixtures, the plugin, or the canonical source on disk.
 *
 * @package S5\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'S5_ROUNDTRIP_SENTINEL' ) ) {
	// The unmistakable delta the harness looks for when the mutation is armed.
	define( 'S5_ROUNDTRIP_SENTINEL', '<!-- s5-roundtrip-mutation -->' );
}

/**
 * Whether the mutation is armed (option set truthy).
 *
 * @return bool
 */
function s5_roundtrip_mutation_enabled(): bool {
	return (bool) get_option( 's5_roundtrip_mutate', false );
}

add_filter(
	'get_post_metadata',
	/**
	 * Append a sentinel to the canonical body fragment as it is read.
	 *
	 * Signature matches the core `get_{$meta_type}_metadata` short-circuit
	 * filter. Returning non-null short-circuits the normal meta lookup, so we
	 * fetch the real value first, then corrupt it. A static re-entrancy guard
	 * prevents infinite recursion when we call get_post_meta() ourselves.
	 *
	 * @param mixed  $value     The short-circuit value (null to use default).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key being requested.
	 * @param bool   $single    Whether a single value was requested.
	 * @return mixed
	 */
	static function ( $value, $object_id, $meta_key, $single ) {
		static $reentrant = false;

		if ( $reentrant || '_s5_canonical_html' !== $meta_key ) {
			return $value; // Not our key, or a re-entrant read — untouched.
		}
		if ( ! s5_roundtrip_mutation_enabled() ) {
			return $value; // Disarmed — behave as if this plugin is not present.
		}

		// Pull the real stored value without re-triggering this filter.
		$reentrant = true;
		$real      = get_post_meta( (int) $object_id, '_s5_canonical_html', $single );
		$reentrant = false;

		if ( is_string( $real ) && '' !== $real ) {
			$mutated = $real . S5_ROUNDTRIP_SENTINEL;

			// Core normalizes the filter return: single=true wants the raw
			// value, single=false wants an array of values.
			return $single ? $mutated : array( $mutated );
		}

		return $value;
	},
	10,
	4
);
