<?php
/**
 * PHPUnit bootstrap scaffold for S5 unit tests.
 *
 * Step 0: bootstrap only. The Step 0 acceptance test is tests/acceptance.sh
 * (bash), NOT PHPUnit — do not wire acceptance here. This suite is reserved for
 * Step 4 compiler unit tests (pure functions: class extraction, vocabulary
 * validation, diagnostics) that run without a full WordPress bootstrap.
 *
 * @package S5\Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
