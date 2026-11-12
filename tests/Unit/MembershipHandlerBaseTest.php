<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Membership_Handler_Base;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub for testing the abstract Membership_Handler_Base.
 */
class ConcreteMembershipHandler extends Membership_Handler_Base {

	/**
	 * Expose the protected logger for assertions.
	 *
	 * @return Logger
	 */
	public function get_logger(): Logger {
		return $this->logger;
	}
}

/**
 * Unit tests for Membership_Handler_Base.
 *
 * Verifies that the abstract base class correctly stores and
 * exposes the Logger dependency to subclasses.
 */
#[CoversClass(\WP4Odoo\Modules\Membership_Handler_Base::class)]
class MembershipHandlerBaseTest extends TestCase {

	private ConcreteMembershipHandler $handler;
	private Logger $logger;

	protected function setUp(): void {
		$this->logger  = new Logger( 'test' );
		$this->handler = new ConcreteMembershipHandler( $this->logger );
	}

	// ─── Constructor ──────────────────────────────────────────

	public function test_constructor_injects_logger(): void {
		$this->assertSame( $this->logger, $this->handler->get_logger() );
	}

	public function test_logger_is_accessible_by_subclass(): void {
		$this->assertInstanceOf( Logger::class, $this->handler->get_logger() );
	}
}
