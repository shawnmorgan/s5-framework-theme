<?php
/**
 * S5 test-only debug guard (mu-plugin, loaded via .wp-env.json mapping).
 *
 * Escalates PHP warnings/notices to entries the acceptance test can detect,
 * and records them to the debug log. This exists ONLY to make the Step 0
 * acceptance test's "no PHP errors on a front-page request" assertion reliable.
 * It is not part of the framework and is never shipped.
 *
 * @package S5\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Ensure notices/warnings are surfaced in this test environment.
error_reporting( E_ALL );
