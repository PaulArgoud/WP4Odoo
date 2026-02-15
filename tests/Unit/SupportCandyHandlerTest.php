<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\SupportCandy_Handler;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for SupportCandy_Handler.
 *
 * Tests ticket loading from custom tables, status saving,
 * Odoo data parsing, and priority mapping.
 */
class SupportCandyHandlerTest extends Module_Test_Case {

	private SupportCandy_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb->prefix = 'wp_';
		$this->handler      = new SupportCandy_Handler( new Logger( 'test' ) );
	}

	// ─── load_ticket ───────────────────────────────────

	public function test_load_ticket_returns_ticket_data(): void {
		$this->wpdb->get_row_return = [
			'id'          => 42,
			'subject'     => 'Login Issue',
			'description' => 'Cannot log in.',
			'customer'    => 5,
			'status'      => 'open',
		];

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( 'Login Issue', $data['name'] );
		$this->assertSame( 'Cannot log in.', $data['description'] );
		$this->assertSame( 5, $data['_user_id'] );
		$this->assertSame( 'open', $data['_wp_status'] );
	}

	public function test_load_ticket_empty_for_zero_id(): void {
		$data = $this->handler->load_ticket( 0 );
		$this->assertEmpty( $data );
	}

	public function test_load_ticket_empty_when_not_found(): void {
		// Default: $wpdb->get_row_return is null.
		$data = $this->handler->load_ticket( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_ticket_maps_priority_from_meta(): void {
		$this->wpdb->get_row_return = [
			'id'          => 42,
			'subject'     => 'Test',
			'description' => '',
			'customer'    => 1,
			'status'      => 'open',
		];
		// get_var returns the priority meta value.
		$this->wpdb->get_var_return = 'high';

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( '2', $data['priority'] );
	}

	public function test_load_ticket_defaults_priority_to_zero(): void {
		$this->wpdb->get_row_return = [
			'id'          => 42,
			'subject'     => 'Test',
			'description' => '',
			'customer'    => 1,
			'status'      => 'open',
		];
		// get_var returns null (no meta) — defaults to empty string → '0'.
		$this->wpdb->get_var_return = null;

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( '0', $data['priority'] );
	}

	// ─── save_ticket_status ─────────────────────────────

	public function test_save_ticket_status_calls_wpdb_update(): void {
		$result = $this->handler->save_ticket_status( 42, 'closed' );

		$this->assertTrue( $result );
		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_save_ticket_status_returns_false_for_zero_id(): void {
		$result = $this->handler->save_ticket_status( 0, 'closed' );

		$this->assertFalse( $result );
	}

	// ─── parse_ticket_from_odoo ─────────────────────────

	public function test_parse_ticket_from_odoo_extracts_stage_name(): void {
		$odoo_data = [
			'id'       => 55,
			'stage_id' => [ 3, 'In Progress' ],
			'name'     => 'Ticket #55',
		];

		$result = $this->handler->parse_ticket_from_odoo( $odoo_data, true );

		$this->assertSame( 'In Progress', $result['_stage_name'] );
	}

	public function test_parse_ticket_from_odoo_handles_missing_stage(): void {
		$result = $this->handler->parse_ticket_from_odoo( [], false );

		$this->assertSame( '', $result['_stage_name'] );
	}

	public function test_parse_ticket_from_odoo_handles_false_stage(): void {
		$odoo_data = [ 'stage_id' => false ];

		$result = $this->handler->parse_ticket_from_odoo( $odoo_data, true );

		$this->assertSame( '', $result['_stage_name'] );
	}

	public function test_parse_ticket_from_odoo_works_for_project_task(): void {
		$odoo_data = [
			'id'       => 10,
			'stage_id' => [ 7, 'Done' ],
		];

		$result = $this->handler->parse_ticket_from_odoo( $odoo_data, false );

		$this->assertSame( 'Done', $result['_stage_name'] );
	}
}
