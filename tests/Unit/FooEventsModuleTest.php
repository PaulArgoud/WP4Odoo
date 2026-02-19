<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\FooEvents_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FooEvents_Module.
 *
 * Tests module identity, required modules, Odoo models, settings,
 * field mappings, dependency status, boot guard, and pull support.
 *
 * @covers \WP4Odoo\Modules\FooEvents_Module
 */
class FooEventsModuleTest extends TestCase {

	private FooEvents_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_posts']      = [];
		$GLOBALS['_wp_post_meta']  = [];

		$this->module = new FooEvents_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	// ─── Module identity ─────────────────────────────────

	public function test_module_id_is_fooevents(): void {
		$this->assertSame( 'fooevents', $this->module->get_id() );
	}

	public function test_module_name_is_fooevents(): void {
		$this->assertSame( 'FooEvents', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	public function test_requires_woocommerce(): void {
		$this->assertSame( [ 'woocommerce' ], $this->module->get_required_modules() );
	}

	// ─── Odoo models ────────────────────────────────────

	public function test_declares_event_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'event.event', $models['event'] );
	}

	public function test_declares_attendee_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'event.registration', $models['attendee'] );
	}

	public function test_declares_two_entity_types(): void {
		$this->assertCount( 2, $this->module->get_odoo_models() );
	}

	// ─── Default settings ───────────────────────────────

	public function test_sync_events_enabled_by_default(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_events'] );
	}

	public function test_sync_attendees_enabled_by_default(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_attendees'] );
	}

	public function test_pull_events_disabled_by_default(): void {
		$settings = $this->module->get_default_settings();
		$this->assertFalse( $settings['pull_events'] );
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
		$odoo = $this->module->map_to_odoo( 'event', [ 'name' => 'Music Festival' ] );
		$this->assertSame( 'Music Festival', $odoo['name'] );
	}

	public function test_event_mapping_passes_date_begin(): void {
		$odoo = $this->module->map_to_odoo( 'event', [ 'date_begin' => '2026-07-20 18:00:00' ] );
		$this->assertSame( '2026-07-20 18:00:00', $odoo['date_begin'] );
	}

	public function test_event_mapping_passes_description(): void {
		$odoo = $this->module->map_to_odoo( 'event', [ 'description' => 'Details' ] );
		$this->assertSame( 'Details', $odoo['description'] );
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
		$odoo = $this->module->map_to_odoo( 'attendee', [ 'email' => 'jane@example.com' ] );
		$this->assertSame( 'jane@example.com', $odoo['email'] );
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

	// ─── Pull: attendee skipped ─────────────────────────

	public function test_pull_attendee_skipped(): void {
		$result = $this->module->pull_from_odoo( 'attendee', 'update', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	public function test_pull_attendee_create_skipped(): void {
		$result = $this->module->pull_from_odoo( 'attendee', 'create', 200, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: delete ───────────────────────────────────

	public function test_pull_event_delete_removes_post(): void {
		$GLOBALS['_wp_posts'][50] = (object) [
			'post_type'    => 'product',
			'post_title'   => 'Event to delete',
			'post_content' => '',
		];

		$result = $this->module->pull_from_odoo( 'event', 'delete', 100, 50 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── map_from_odoo ──────────────────────────────────

	public function test_map_from_odoo_event_parses_calendar_fields(): void {
		$odoo_data = [
			'name'        => 'Pulled Festival',
			'start'       => '2026-08-01 18:00:00',
			'stop'        => '2026-08-02 02:00:00',
			'allday'      => false,
			'description' => 'From Odoo',
		];

		$wp_data = $this->module->map_from_odoo( 'event', $odoo_data );

		$this->assertSame( 'Pulled Festival', $wp_data['name'] );
		$this->assertSame( '2026-08-01 18:00:00', $wp_data['start_date'] );
		$this->assertSame( '2026-08-02 02:00:00', $wp_data['end_date'] );
	}

	public function test_map_from_odoo_event_with_event_model(): void {
		$GLOBALS['_wp_transients']['wp4odoo_has_event_event'] = 1;

		$module = new FooEvents_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);

		$odoo_data = [
			'name'        => 'Pulled Festival',
			'date_begin'  => '2026-08-01 18:00:00',
			'date_end'    => '2026-08-02 02:00:00',
			'date_tz'     => 'America/New_York',
			'description' => 'From Odoo',
		];

		$wp_data = $module->map_from_odoo( 'event', $odoo_data );

		$this->assertSame( 'Pulled Festival', $wp_data['name'] );
		$this->assertSame( '2026-08-01 18:00:00', $wp_data['start_date'] );
		$this->assertSame( '2026-08-02 02:00:00', $wp_data['end_date'] );
		$this->assertSame( 'America/New_York', $wp_data['timezone'] );
	}

	// ─── Boot guard ─────────────────────────────────────

	public function test_boot_does_not_throw(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}
}
