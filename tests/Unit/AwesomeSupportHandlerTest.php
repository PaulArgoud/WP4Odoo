<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Awesome_Support_Handler;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for Awesome_Support_Handler.
 *
 * Tests ticket loading, status saving, Odoo data parsing,
 * and priority mapping.
 */
class AwesomeSupportHandlerTest extends Module_Test_Case {

	private Awesome_Support_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->handler = new Awesome_Support_Handler( new Logger( 'test' ) );
	}

	// ─── load_ticket ───────────────────────────────────

	/**
	 * @return object
	 */
	private function make_ticket( int $id, string $title, string $content, int $author = 1 ): object {
		return (object) [
			'ID'           => $id,
			'post_type'    => 'ticket',
			'post_title'   => $title,
			'post_content' => $content,
			'post_author'  => $author,
			'post_status'  => 'publish',
		];
	}

	public function test_load_ticket_returns_ticket_data(): void {
		$GLOBALS['_wp_posts'][42]     = $this->make_ticket( 42, 'Bug Report', 'Something is broken', 5 );
		$GLOBALS['_wp_post_meta'][42] = [
			'_wpas_status'   => 'open',
			'_wpas_priority' => 'high',
		];

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( 'Bug Report', $data['name'] );
		$this->assertSame( 'Something is broken', $data['description'] );
		$this->assertSame( 5, $data['_user_id'] );
		$this->assertSame( 'open', $data['_wp_status'] );
		$this->assertSame( '2', $data['priority'] );
	}

	public function test_load_ticket_empty_for_zero_id(): void {
		$data = $this->handler->load_ticket( 0 );
		$this->assertEmpty( $data );
	}

	public function test_load_ticket_empty_for_nonexistent_post(): void {
		$data = $this->handler->load_ticket( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_ticket_empty_for_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][42] = (object) [
			'ID'          => 42,
			'post_type'   => 'post',
			'post_title'  => 'Not a ticket',
			'post_author' => 1,
		];

		$data = $this->handler->load_ticket( 42 );
		$this->assertEmpty( $data );
	}

	public function test_load_ticket_defaults_status_to_open(): void {
		$GLOBALS['_wp_posts'][42] = $this->make_ticket( 42, 'Test', 'Content' );

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( 'open', $data['_wp_status'] );
	}

	public function test_load_ticket_maps_low_priority(): void {
		$GLOBALS['_wp_posts'][42]     = $this->make_ticket( 42, 'Test', 'Content' );
		$GLOBALS['_wp_post_meta'][42] = [ '_wpas_priority' => 'low' ];

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( '0', $data['priority'] );
	}

	public function test_load_ticket_maps_medium_priority(): void {
		$GLOBALS['_wp_posts'][42]     = $this->make_ticket( 42, 'Test', 'Content' );
		$GLOBALS['_wp_post_meta'][42] = [ '_wpas_priority' => 'medium' ];

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( '1', $data['priority'] );
	}

	public function test_load_ticket_maps_urgent_priority(): void {
		$GLOBALS['_wp_posts'][42]     = $this->make_ticket( 42, 'Test', 'Content' );
		$GLOBALS['_wp_post_meta'][42] = [ '_wpas_priority' => 'urgent' ];

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( '3', $data['priority'] );
	}

	public function test_load_ticket_defaults_unknown_priority_to_zero(): void {
		$GLOBALS['_wp_posts'][42]     = $this->make_ticket( 42, 'Test', 'Content' );
		$GLOBALS['_wp_post_meta'][42] = [ '_wpas_priority' => 'custom' ];

		$data = $this->handler->load_ticket( 42 );

		$this->assertSame( '0', $data['priority'] );
	}

	// ─── save_ticket_status ─────────────────────────────

	public function test_save_ticket_status_updates_status(): void {
		$result = $this->handler->save_ticket_status( 42, 'closed' );

		$this->assertTrue( $result );
		$this->assertSame( 'closed', $GLOBALS['_wp_post_meta'][42]['_wpas_status'] );
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
			'name'     => 'Bug #123',
		];

		$result = $this->handler->parse_ticket_from_odoo( $odoo_data, true );

		$this->assertSame( 'In Progress', $result['_stage_name'] );
	}

	public function test_parse_ticket_from_odoo_handles_missing_stage(): void {
		$result = $this->handler->parse_ticket_from_odoo( [], true );

		$this->assertSame( '', $result['_stage_name'] );
	}

	public function test_parse_ticket_from_odoo_handles_false_stage(): void {
		$odoo_data = [ 'stage_id' => false ];

		$result = $this->handler->parse_ticket_from_odoo( $odoo_data, false );

		$this->assertSame( '', $result['_stage_name'] );
	}
}
