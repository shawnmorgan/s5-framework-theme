<?php
/**
 * Placeholder unit test.
 *
 * @package S5\Tests
 */

declare( strict_types=1 );

namespace S5\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Confirms the PHPUnit harness is wired.
 *
 * Real unit tests arrive in Step 4 (Authoring Contract validation): class
 * extraction, closed-vocabulary checks, and agent-friendly diagnostics, tested
 * as pure functions. Until then this asserts nothing about the framework.
 *
 * @coversNothing
 */
final class PlaceholderTest extends TestCase {

	/**
	 * The harness runs.
	 *
	 * @return void
	 */
	public function test_harness_is_wired(): void {
		$this->assertTrue( true, 'PHPUnit harness is wired; Step 4 adds real compiler unit tests.' );
	}
}
