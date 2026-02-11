<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Events_Calendar_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Events_Calendar_Module.
 *
 * Tests module identity, Odoo models, settings, field mappings,
 * dependency status, and boot guard.
 */
class EventsCalendarModuleTest extends TestCase {

	private Events_Calendar_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_posts']         = [];
		$GLOBALS['_wp_post_meta']     = [];
		$GLOBALS['_tribe_events']     = [];
		$GLOBALS['_tribe_tickets']    = [];
		$GLOBALS['_tribe_attendees']  = [];

		$this->module = new Events_Calendar_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	// ─── Module identity ─────────────────────────────────

	public function test_module_id_is_events_calendar(): void {
		$this->assertSame( 'events_calendar', $this->module->get_id() );
	}

	public function test_module_name_is_the_events_calendar(): void {
		$this->assertSame( 'The Events Calendar', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority_is_zero(): void {
		$this->assertSame( 0, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_push_only(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo models ────────────────────────────────────

	public function test_declares_event_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'event.event', $models['event'] );
	}

	public function test_declares_ticket_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['ticket'] );
	}

	public function test_declares_attendee_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'event.registration', $models['attendee'] );
	}

	public function test_declares_three_entity_types(): void {
		$this->assertCount( 3, $this->module->get_odoo_models() );
	}

	// ─── Default settings ───────────────────────────────

	public function test_sync_events_enabled_by_default(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_events'] );
	}

	public function test_sync_tickets_enabled_by_default(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_tickets'] );
	}

	public function test_sync_attendees_enabled_by_default(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_attendees'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 3, $this->module->get_default_settings() );
	}

	// ─── Settings fields ────────────────────────────────

	public function test_settings_fields_count(): void {
		$this->assertCount( 3, $this->module->get_settings_fields() );
	}

	public function test_settings_fields_have_labels(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $field ) {
			$this->assertArrayHasKey( 'label', $field );
			$this->assertNotEmpty( $field['label'] );
		}
	}

	public function test_settings_fields_are_checkboxes(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $field ) {
			$this->assertSame( 'checkbox', $field['type'] );
		}
	}

	// ─── Field mappings: event (pass-through) ───────────

	public function test_event_mapping_passes_name(): void {
		$odoo = $this->module->map_to_odoo( 'event', [ 'name' => 'Conference' ] );
		$this->assertSame( 'Conference', $odoo['name'] );
	}

	public function test_event_mapping_passes_date_begin(): void {
		$odoo = $this->module->map_to_odoo( 'event', [ 'date_begin' => '2026-06-15 09:00:00' ] );
		$this->assertSame( '2026-06-15 09:00:00', $odoo['date_begin'] );
	}

	public function test_event_mapping_passes_description(): void {
		$odoo = $this->module->map_to_odoo( 'event', [ 'description' => 'Details' ] );
		$this->assertSame( 'Details', $odoo['description'] );
	}

	// ─── Field mappings: ticket ──────────────────────────

	public function test_ticket_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'ticket', [ 'name' => 'VIP' ] );
		$this->assertSame( 'VIP', $odoo['name'] );
	}

	public function test_ticket_mapping_includes_list_price(): void {
		$odoo = $this->module->map_to_odoo( 'ticket', [ 'list_price' => 25.0 ] );
		$this->assertSame( 25.0, $odoo['list_price'] );
	}

	public function test_ticket_mapping_includes_service_type(): void {
		$odoo = $this->module->map_to_odoo( 'ticket', [ 'type' => 'service' ] );
		$this->assertSame( 'service', $odoo['type'] );
	}

	// ─── Field mappings: attendee (pass-through) ────────

	public function test_attendee_mapping_passes_event_id(): void {
		$odoo = $this->module->map_to_odoo( 'attendee', [ 'event_id' => 100 ] );
		$this->assertSame( 100, $odoo['event_id'] );
	}

	public function test_attendee_mapping_passes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'attendee', [ 'partner_id' => 200 ] );
		$this->assertSame( 200, $odoo['partner_id'] );
	}

	public function test_attendee_mapping_passes_email(): void {
		$odoo = $this->module->map_to_odoo( 'attendee', [ 'email' => 'john@example.com' ] );
		$this->assertSame( 'john@example.com', $odoo['email'] );
	}

	// ─── Dependency status ──────────────────────────────

	public function test_dependency_available_when_class_exists(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_when_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot guard ─────────────────────────────────────

	public function test_boot_does_not_throw(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}
}
