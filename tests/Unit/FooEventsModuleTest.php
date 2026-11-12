<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Module_Base;
use WP4Odoo\Modules\FooEvents_Module;

/**
 * Unit tests for FooEvents_Module.
 *
 * Tests module identity, required modules, Odoo models, settings,
 * field mappings, dependency status, boot guard, and pull support.
 *
 */
#[CoversClass(\WP4Odoo\Modules\FooEvents_Module::class)]
class FooEventsModuleTest extends EventsModuleTestBase {

	protected function get_module_id(): string {
		return 'fooevents';
	}

	protected function get_module_name(): string {
		return 'FooEvents';
	}

	protected function get_attendance_entity(): string {
		return 'attendee';
	}

	protected function create_module(): Module_Base {
		return new FooEvents_Module(
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

		$this->module = new FooEvents_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	// ─── Module identity (module-specific) ──────────────

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_requires_woocommerce(): void {
		$this->assertSame( [ 'woocommerce' ], $this->module->get_required_modules() );
	}

	// ─── Odoo models (module-specific) ──────────────────

	public function test_declares_attendee_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'event.registration', $models['attendee'] );
	}

	public function test_declares_two_entity_types(): void {
		$this->assertCount( 2, $this->module->get_odoo_models() );
	}

	// ─── Default settings (module-specific) ─────────────

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

	// ─── Settings fields (module-specific) ──────────────

	public function test_settings_fields_count(): void {
		$this->assertCount( 3, $this->module->get_settings_fields() );
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
}
