<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Module_Base;
use WP4Odoo\Modules\MEC_Module;

/**
 * Unit tests for MEC_Module.
 *
 * Tests module identity, exclusive group, Odoo models, settings,
 * field mappings, dependency status, boot guard, and pull support.
 *
 */
#[CoversClass(\WP4Odoo\Modules\MEC_Module::class)]
class MECModuleTest extends EventsModuleTestBase {

	protected function get_module_id(): string {
		return 'mec';
	}

	protected function get_module_name(): string {
		return 'Modern Events Calendar';
	}

	protected function get_attendance_entity(): string {
		return 'booking';
	}

	protected function create_module(): Module_Base {
		return new MEC_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_posts']      = [];
		$GLOBALS['_wp_post_meta']  = [];

		$this->module = new MEC_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	// ─── Module identity (module-specific) ──────────────

	public function test_exclusive_group_is_events(): void {
		$this->assertSame( 'events', $this->module->get_exclusive_group() );
	}

	// ─── Odoo models (module-specific) ──────────────────

	public function test_declares_booking_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'event.registration', $models['booking'] );
	}

	public function test_declares_two_entity_types(): void {
		$this->assertCount( 2, $this->module->get_odoo_models() );
	}

	// ─── Default settings (module-specific) ─────────────

	public function test_sync_bookings_enabled_by_default(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_bookings'] );
	}

	public function test_pull_events_enabled_by_default(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_events'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 3, $this->module->get_default_settings() );
	}

	// ─── Settings fields (module-specific) ──────────────

	public function test_settings_fields_count(): void {
		$this->assertCount( 3, $this->module->get_settings_fields() );
	}

	// ─── Pull: delete ───────────────────────────────────

	public function test_pull_event_delete_removes_post(): void {
		$GLOBALS['_wp_posts'][50] = (object) [
			'post_type'    => 'mec-events',
			'post_title'   => 'Event to delete',
			'post_content' => '',
		];

		$result = $this->module->pull_from_odoo( 'event', 'delete', 100, 50 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── Translatable Fields ────────────────────────────

	public function test_translatable_fields_for_event(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$fields = $method->invoke( $this->module, 'event' );

		$this->assertSame(
			[ 'name' => 'post_title', 'description' => 'post_content' ],
			$fields
		);
	}

	public function test_translatable_fields_empty_for_booking(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'booking' ) );
	}
}
