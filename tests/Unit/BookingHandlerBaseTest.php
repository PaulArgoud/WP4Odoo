<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Booking_Handler_Base;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub for testing the abstract Booking_Handler_Base.
 */
class ConcreteBookingHandler extends Booking_Handler_Base {

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
 * Unit tests for Booking_Handler_Base.
 *
 * Verifies that the abstract base class correctly stores and
 * exposes the Logger dependency to subclasses.
 *
 * @covers \WP4Odoo\Modules\Booking_Handler_Base
 */
class BookingHandlerBaseTest extends TestCase {

	private ConcreteBookingHandler $handler;
	private Logger $logger;

	protected function setUp(): void {
		$this->logger  = new Logger( 'test' );
		$this->handler = new ConcreteBookingHandler( $this->logger );
	}

	// ─── Constructor ──────────────────────────────────────────

	public function test_constructor_injects_logger(): void {
		$this->assertSame( $this->logger, $this->handler->get_logger() );
	}

	public function test_logger_is_accessible_by_subclass(): void {
		$this->assertInstanceOf( Logger::class, $this->handler->get_logger() );
	}
}
