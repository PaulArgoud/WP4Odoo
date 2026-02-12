<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\LifterLMS_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LifterLMS_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * and default settings.
 */
class LifterLMSModuleTest extends TestCase {

	private LifterLMS_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_llms_orders']      = [];
		$GLOBALS['_llms_enrollments'] = [];

		$this->module = new LifterLMS_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_lifterlms(): void {
		$this->assertSame( 'lifterlms', $this->module->get_id() );
	}

	public function test_module_name_is_lifterlms(): void {
		$this->assertSame( 'LifterLMS', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_course_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['course'] );
	}

	public function test_declares_membership_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['membership'] );
	}

	public function test_declares_order_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['order'] );
	}

	public function test_declares_enrollment_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'sale.order', $models['enrollment'] );
	}

	public function test_declares_exactly_four_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 4, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_courses(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_courses'] );
	}

	public function test_default_settings_has_sync_memberships(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_memberships'] );
	}

	public function test_default_settings_has_sync_orders(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_orders'] );
	}

	public function test_default_settings_has_sync_enrollments(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_enrollments'] );
	}

	public function test_default_settings_has_auto_post_invoices(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['auto_post_invoices'] );
	}

	public function test_default_settings_has_exactly_seven_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 7, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_courses(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_courses', $fields );
		$this->assertSame( 'checkbox', $fields['sync_courses']['type'] );
	}

	public function test_settings_fields_exposes_sync_memberships(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_memberships', $fields );
		$this->assertSame( 'checkbox', $fields['sync_memberships']['type'] );
	}

	public function test_settings_fields_exposes_sync_orders(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_orders', $fields );
		$this->assertSame( 'checkbox', $fields['sync_orders']['type'] );
	}

	public function test_settings_fields_exposes_sync_enrollments(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_enrollments', $fields );
		$this->assertSame( 'checkbox', $fields['sync_enrollments']['type'] );
	}

	public function test_settings_fields_exposes_auto_post_invoices(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'auto_post_invoices', $fields );
		$this->assertSame( 'checkbox', $fields['auto_post_invoices']['type'] );
	}

	// ─── Field Mappings: Course ───────────────────────────

	public function test_course_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'course', [ 'title' => 'PHP 101' ] );
		$this->assertSame( 'PHP 101', $odoo['name'] );
	}

	public function test_course_mapping_includes_description(): void {
		$odoo = $this->module->map_to_odoo( 'course', [ 'description' => 'Learn PHP' ] );
		$this->assertSame( 'Learn PHP', $odoo['description_sale'] );
	}

	public function test_course_mapping_includes_list_price(): void {
		$odoo = $this->module->map_to_odoo( 'course', [ 'list_price' => 49.99 ] );
		$this->assertSame( 49.99, $odoo['list_price'] );
	}

	public function test_course_mapping_includes_type(): void {
		$odoo = $this->module->map_to_odoo( 'course', [ 'type' => 'service' ] );
		$this->assertSame( 'service', $odoo['type'] );
	}

	// ─── Field Mappings: Membership ───────────────────────

	public function test_membership_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'membership', [ 'title' => 'Pro Access' ] );
		$this->assertSame( 'Pro Access', $odoo['name'] );
	}

	public function test_membership_mapping_includes_list_price(): void {
		$odoo = $this->module->map_to_odoo( 'membership', [ 'list_price' => 99.0 ] );
		$this->assertSame( 99.0, $odoo['list_price'] );
	}

	// ─── Field Mappings: Order ────────────────────────────

	public function test_order_mapping_includes_move_type(): void {
		$odoo = $this->module->map_to_odoo( 'order', [ 'move_type' => 'out_invoice' ] );
		$this->assertSame( 'out_invoice', $odoo['move_type'] );
	}

	public function test_order_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'order', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_order_mapping_includes_invoice_line_ids(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 5 ] ] ];
		$odoo  = $this->module->map_to_odoo( 'order', [ 'invoice_line_ids' => $lines ] );
		$this->assertSame( $lines, $odoo['invoice_line_ids'] );
	}

	// ─── Field Mappings: Enrollment ───────────────────────

	public function test_enrollment_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'enrollment', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_enrollment_mapping_includes_date_order(): void {
		$odoo = $this->module->map_to_odoo( 'enrollment', [ 'date_order' => '2026-02-01' ] );
		$this->assertSame( '2026-02-01', $odoo['date_order'] );
	}

	public function test_enrollment_mapping_includes_state(): void {
		$odoo = $this->module->map_to_odoo( 'enrollment', [ 'state' => 'sale' ] );
		$this->assertSame( 'sale', $odoo['state'] );
	}

	public function test_enrollment_mapping_includes_order_line(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 42 ] ] ];
		$odoo  = $this->module->map_to_odoo( 'enrollment', [ 'order_line' => $lines ] );
		$this->assertSame( $lines, $odoo['order_line'] );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_unavailable_without_lifterlms(): void {
		// LLMS_VERSION is not defined in our test bootstrap.
		$status = $this->module->get_dependency_status();
		$this->assertFalse( $status['available'] );
	}

	public function test_dependency_has_warning_without_lifterlms(): void {
		$status = $this->module->get_dependency_status();
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
	}

	// ─── Pull Settings ──────────────────────────────────

	public function test_default_settings_has_pull_courses(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_courses'] );
	}

	public function test_default_settings_has_pull_memberships(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_memberships'] );
	}

	public function test_settings_fields_exposes_pull_courses(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_courses', $fields );
		$this->assertSame( 'checkbox', $fields['pull_courses']['type'] );
	}

	public function test_settings_fields_exposes_pull_memberships(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_memberships', $fields );
		$this->assertSame( 'checkbox', $fields['pull_memberships']['type'] );
	}

	// ─── Pull: order/enrollment skipped ─────────────────

	public function test_pull_order_skipped(): void {
		$result = $this->module->pull_from_odoo( 'order', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 0, $result->get_entity_id() );
	}

	public function test_pull_enrollment_skipped(): void {
		$result = $this->module->pull_from_odoo( 'enrollment', 'create', 200, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 0, $result->get_entity_id() );
	}

	// ─── Pull: delete ───────────────────────────────────

	public function test_pull_course_delete_removes_post(): void {
		$GLOBALS['_wp_posts'][50] = (object) [
			'post_type'    => 'llms_course',
			'post_title'   => 'Course to delete',
			'post_content' => '',
		];

		$result = $this->module->pull_from_odoo( 'course', 'delete', 100, 50 );
		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_membership_delete_removes_post(): void {
		$GLOBALS['_wp_posts'][60] = (object) [
			'post_type'    => 'llms_membership',
			'post_title'   => 'Membership to delete',
			'post_content' => '',
		];

		$result = $this->module->pull_from_odoo( 'membership', 'delete', 200, 60 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── map_from_odoo ──────────────────────────────────

	public function test_map_from_odoo_course(): void {
		$odoo_data = [
			'name'             => 'Pulled Course',
			'description_sale' => 'From Odoo',
			'list_price'       => 79.99,
		];

		$wp_data = $this->module->map_from_odoo( 'course', $odoo_data );

		$this->assertSame( 'Pulled Course', $wp_data['title'] );
		$this->assertSame( 'From Odoo', $wp_data['description'] );
		$this->assertSame( 79.99, $wp_data['list_price'] );
	}

	public function test_map_from_odoo_membership(): void {
		$odoo_data = [
			'name'             => 'Pulled Membership',
			'description_sale' => 'Membership from Odoo',
			'list_price'       => 99.0,
		];

		$wp_data = $this->module->map_from_odoo( 'membership', $odoo_data );

		$this->assertSame( 'Pulled Membership', $wp_data['title'] );
		$this->assertSame( 'Membership from Odoo', $wp_data['description'] );
		$this->assertSame( 99.0, $wp_data['list_price'] );
	}

	// ─── Boot Guard ────────────────────────────────────────

	public function test_boot_does_not_crash_without_lifterlms(): void {
		$this->module->boot();
		$this->assertTrue( true ); // No exception thrown.
	}
}
