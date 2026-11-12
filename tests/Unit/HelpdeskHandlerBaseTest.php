<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Helpdesk_Handler_Base;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub for testing the abstract Helpdesk_Handler_Base.
 */
class ConcreteHelpdeskHandler extends Helpdesk_Handler_Base {

	/**
	 * Expose the protected logger for assertions.
	 *
	 * @return Logger
	 */
	public function get_logger(): Logger {
		return $this->logger;
	}

	/**
	 * Expose map_priority() for testing.
	 *
	 * @param string $wp_priority Priority string.
	 * @return string Odoo priority.
	 */
	public function test_map_priority( string $wp_priority ): string {
		return $this->map_priority( $wp_priority );
	}
}

/**
 * Unit tests for Helpdesk_Handler_Base.
 *
 * Verifies constructor injection, priority mapping, Odoo ticket
 * parsing, and the PRIORITY_MAP constant.
 */
#[CoversClass(\WP4Odoo\Modules\Helpdesk_Handler_Base::class)]
class HelpdeskHandlerBaseTest extends TestCase {

	private ConcreteHelpdeskHandler $handler;
	private Logger $logger;

	protected function setUp(): void {
		$this->logger  = new Logger( 'test' );
		$this->handler = new ConcreteHelpdeskHandler( $this->logger );
	}

	// ─── Constructor ──────────────────────────────────────────

	public function test_constructor_injects_logger(): void {
		$this->assertSame( $this->logger, $this->handler->get_logger() );
	}

	public function test_logger_is_accessible_by_subclass(): void {
		$this->assertInstanceOf( Logger::class, $this->handler->get_logger() );
	}

	// ─── map_priority ─────────────────────────────────────────

	public function test_map_priority_low(): void {
		$this->assertSame( '0', $this->handler->test_map_priority( 'low' ) );
	}

	public function test_map_priority_medium(): void {
		$this->assertSame( '1', $this->handler->test_map_priority( 'medium' ) );
	}

	public function test_map_priority_high(): void {
		$this->assertSame( '2', $this->handler->test_map_priority( 'high' ) );
	}

	public function test_map_priority_urgent(): void {
		$this->assertSame( '3', $this->handler->test_map_priority( 'urgent' ) );
	}

	public function test_map_priority_case_insensitive(): void {
		$this->assertSame( '2', $this->handler->test_map_priority( 'HIGH' ) );
	}

	public function test_map_priority_unknown_defaults_to_low(): void {
		$this->assertSame( '0', $this->handler->test_map_priority( 'critical' ) );
	}

	// ─── parse_ticket_from_odoo ───────────────────────────────

	public function test_parse_ticket_extracts_stage_name_from_many2one(): void {
		$odoo_data = [
			'stage_id' => [ 5, 'In Progress' ],
			'name'     => 'Test Ticket',
		];

		$result = $this->handler->parse_ticket_from_odoo( $odoo_data, true );

		$this->assertSame( 'In Progress', $result['_stage_name'] );
	}

	public function test_parse_ticket_returns_empty_stage_when_no_stage_id(): void {
		$odoo_data = [
			'name' => 'No Stage Ticket',
		];

		$result = $this->handler->parse_ticket_from_odoo( $odoo_data, true );

		$this->assertSame( '', $result['_stage_name'] );
	}

	public function test_parse_ticket_returns_empty_stage_when_stage_is_false(): void {
		$odoo_data = [
			'stage_id' => false,
			'name'     => 'False Stage Ticket',
		];

		$result = $this->handler->parse_ticket_from_odoo( $odoo_data, false );

		$this->assertSame( '', $result['_stage_name'] );
	}

	// ─── PRIORITY_MAP constant ────────────────────────────────

	public function test_priority_map_has_four_entries(): void {
		$ref = new \ReflectionClassConstant( Helpdesk_Handler_Base::class, 'PRIORITY_MAP' );
		$map = $ref->getValue();

		$this->assertCount( 4, $map );
	}

	public function test_priority_map_keys(): void {
		$ref  = new \ReflectionClassConstant( Helpdesk_Handler_Base::class, 'PRIORITY_MAP' );
		$map  = $ref->getValue();
		$keys = array_keys( $map );

		$this->assertSame( [ 'low', 'medium', 'high', 'urgent' ], $keys );
	}
}
