<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\WPERP_CRM_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WPERP_CRM_Handler.
 *
 * Tests contact/activity loading from WP ERP CRM tables via $wpdb stubs.
 *
 */
#[CoversClass(\WP4Odoo\Modules\WPERP_CRM_Handler::class)]
class WPERPCRMHandlerTest extends TestCase {

	private WPERP_CRM_Handler $handler;

	/** @var \WP_DB_Stub */
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options'] = [];

		$this->handler = new WPERP_CRM_Handler( new Logger( 'test' ) );
	}

	// ─── load_lead ─────────────────────────────────────────

	public function test_load_lead_returns_data(): void {
		$this->wpdb->get_row_return = [
			'id'          => 1,
			'first_name'  => 'John',
			'last_name'   => 'Doe',
			'company'     => 'Acme Corp',
			'email'       => 'john@acme.com',
			'phone'       => '+1234567890',
			'mobile'      => '+0987654321',
			'website'     => 'https://acme.com',
			'street_1'    => '123 Main St',
			'street_2'    => 'Suite 100',
			'city'        => 'New York',
			'postal_code' => '10001',
			'notes'       => 'Important client',
			'life_stage'  => 'opportunity',
		];

		$data = $this->handler->load_lead( 1 );

		$this->assertSame( 'Acme Corp', $data['name'] );
		$this->assertSame( 'John Doe', $data['contact_name'] );
		$this->assertSame( 'john@acme.com', $data['email_from'] );
		$this->assertSame( '+1234567890', $data['phone'] );
		$this->assertSame( '+0987654321', $data['mobile'] );
		$this->assertSame( 'https://acme.com', $data['website'] );
		$this->assertSame( '123 Main St', $data['street'] );
		$this->assertSame( 'Suite 100', $data['street2'] );
		$this->assertSame( 'New York', $data['city'] );
		$this->assertSame( '10001', $data['zip'] );
		$this->assertSame( 'Important client', $data['description'] );
		$this->assertSame( 'opportunity', $data['type'] );
	}

	public function test_load_lead_uses_contact_name_as_fallback(): void {
		$this->wpdb->get_row_return = [
			'id'          => 2,
			'first_name'  => 'Jane',
			'last_name'   => 'Smith',
			'company'     => '',
			'email'       => 'jane@test.com',
			'phone'       => '',
			'mobile'      => '',
			'website'     => '',
			'street_1'    => '',
			'street_2'    => '',
			'city'        => '',
			'postal_code' => '',
			'notes'       => '',
			'life_stage'  => 'subscriber',
		];

		$data = $this->handler->load_lead( 2 );

		$this->assertStringContainsString( 'Jane Smith', $data['name'] );
		$this->assertSame( 'lead', $data['type'] );
	}

	public function test_load_lead_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_lead( 999 );
		$this->assertEmpty( $data );
	}

	// ─── map_life_stage_to_odoo ────────────────────────────

	public function test_map_subscriber_to_lead(): void {
		$this->assertSame( 'lead', $this->handler->map_life_stage_to_odoo( 'subscriber' ) );
	}

	public function test_map_lead_to_lead(): void {
		$this->assertSame( 'lead', $this->handler->map_life_stage_to_odoo( 'lead' ) );
	}

	public function test_map_opportunity_to_opportunity(): void {
		$this->assertSame( 'opportunity', $this->handler->map_life_stage_to_odoo( 'opportunity' ) );
	}

	public function test_map_customer_to_opportunity(): void {
		$this->assertSame( 'opportunity', $this->handler->map_life_stage_to_odoo( 'customer' ) );
	}

	public function test_map_unknown_stage_defaults_to_lead(): void {
		$this->assertSame( 'lead', $this->handler->map_life_stage_to_odoo( 'unknown' ) );
	}

	// ─── load_activity ─────────────────────────────────────

	public function test_load_activity_returns_data(): void {
		$this->wpdb->get_row_return = [
			'id'            => 10,
			'user_id'       => 1,
			'type'          => 'call',
			'email_subject' => 'Follow up',
			'message'       => 'Discuss pricing',
			'start_date'    => '2025-07-15 10:00:00',
		];

		$data = $this->handler->load_activity( 10 );

		$this->assertSame( 1, $data['contact_id'] );
		$this->assertSame( 'Phone Call', $data['activity_type_name'] );
		$this->assertSame( 'Follow up', $data['summary'] );
		$this->assertSame( 'Discuss pricing', $data['note'] );
		$this->assertSame( '2025-07-15 10:00:00', $data['date_deadline'] );
	}

	public function test_load_activity_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_activity( 999 );
		$this->assertEmpty( $data );
	}

	// ─── get_contact_id_for_activity ───────────────────────

	public function test_get_contact_id_for_activity(): void {
		$this->wpdb->get_var_return = '5';

		$this->assertSame( 5, $this->handler->get_contact_id_for_activity( 10 ) );
	}

	public function test_get_contact_id_returns_zero_when_not_found(): void {
		$this->wpdb->get_var_return = null;

		$this->assertSame( 0, $this->handler->get_contact_id_for_activity( 999 ) );
	}

	// ─── get_activity_type_label ───────────────────────────

	public function test_activity_type_email(): void {
		$this->assertSame( 'Email', $this->handler->get_activity_type_label( 'email' ) );
	}

	public function test_activity_type_call(): void {
		$this->assertSame( 'Phone Call', $this->handler->get_activity_type_label( 'call' ) );
	}

	public function test_activity_type_meeting(): void {
		$this->assertSame( 'Meeting', $this->handler->get_activity_type_label( 'meeting' ) );
	}

	public function test_activity_type_unknown_defaults_to_todo(): void {
		$this->assertSame( 'To-Do', $this->handler->get_activity_type_label( 'unknown' ) );
	}

	// ─── parse_lead_from_odoo ──────────────────────────────

	public function test_parse_lead_from_odoo_maps_fields(): void {
		$odoo_data = [
			'name'         => 'Acme Corp',
			'contact_name' => 'John Doe',
			'email_from'   => 'john@acme.com',
			'phone'        => '+1234567890',
			'mobile'       => '+0987654321',
			'website'      => 'https://acme.com',
			'street'       => '123 Main St',
			'street2'      => 'Suite 100',
			'city'         => 'New York',
			'zip'          => '10001',
			'description'  => 'Important client',
			'type'         => 'opportunity',
		];

		$data = $this->handler->parse_lead_from_odoo( $odoo_data );

		$this->assertSame( 'John', $data['first_name'] );
		$this->assertSame( 'Doe', $data['last_name'] );
		$this->assertSame( 'Acme Corp', $data['company'] );
		$this->assertSame( 'john@acme.com', $data['email'] );
		$this->assertSame( 'opportunity', $data['life_stage'] );
	}

	public function test_parse_lead_from_odoo_handles_missing_fields(): void {
		$data = $this->handler->parse_lead_from_odoo( [] );

		$this->assertSame( '', $data['first_name'] );
		$this->assertSame( '', $data['last_name'] );
		$this->assertSame( '', $data['email'] );
		$this->assertSame( 'lead', $data['life_stage'] );
	}

	public function test_parse_lead_from_odoo_splits_name(): void {
		$data = $this->handler->parse_lead_from_odoo( [
			'contact_name' => 'Marie Claire Dupont',
		] );

		$this->assertSame( 'Marie', $data['first_name'] );
		$this->assertSame( 'Claire Dupont', $data['last_name'] );
	}

	// ─── save_lead ─────────────────────────────────────────

	public function test_save_lead_creates_new_contact(): void {
		$id = $this->handler->save_lead( [
			'first_name' => 'John',
			'last_name'  => 'Doe',
			'email'      => 'john@test.com',
		], 0 );

		$this->assertSame( 0, $id ); // insert_id default is 0 in stub.
	}

	public function test_save_lead_updates_existing_contact(): void {
		$id = $this->handler->save_lead( [
			'first_name' => 'John',
			'last_name'  => 'Updated',
			'email'      => 'john@test.com',
		], 5 );

		$this->assertSame( 5, $id );
	}

	// ─── delete_lead ───────────────────────────────────────

	public function test_delete_lead_returns_true(): void {
		$result = $this->handler->delete_lead( 5 );
		$this->assertTrue( $result );
	}
}
