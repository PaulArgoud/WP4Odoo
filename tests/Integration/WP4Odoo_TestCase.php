<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

/**
 * Base test case for WP4Odoo integration tests.
 *
 * Overrides expectDeprecated() and expectedDeprecated() to work around
 * WordPress test framework calling PHPUnit\Util\Test::parseTestMethodAnnotations(),
 * which was removed in PHPUnit 10. WordPress core has not yet merged the fix
 * (Trac #62004). This shim disables annotation-based deprecation tracking
 * but preserves all other WP_UnitTestCase functionality.
 *
 * @package WP4Odoo\Tests\Integration
 */
abstract class WP4Odoo_TestCase extends \WP_UnitTestCase {

	/**
	 * Disable annotation-based expected-deprecation setup.
	 *
	 * The parent implementation calls parseTestMethodAnnotations()
	 * which does not exist in PHPUnit 10+.
	 */
	public function expectDeprecated(): void {
		// No-op: incompatible with PHPUnit 10+.
	}

	/**
	 * Disable annotation-based expected-deprecation assertions.
	 *
	 * Companion to expectDeprecated() — the parent implementation
	 * also relies on parseTestMethodAnnotations().
	 */
	public function expectedDeprecated(): void {
		// No-op: incompatible with PHPUnit 10+.
	}
}
