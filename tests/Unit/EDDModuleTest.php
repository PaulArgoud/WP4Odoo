<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\EDD_Module;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EDD_Module.
 *
 * Tests module configuration, entity type declarations, and default settings.
 * EDD-specific hook callbacks require full EDD stubs and are tested in integration.
 */
class EDDModuleTest extends TestCase {

	private EDD_Module $module;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options'] = [];

		$this->module = new EDD_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_edd(): void {
		$this->assertSame( 'edd', $this->module->get_id() );
	}

	public function test_module_name_is_edd(): void {
		$this->assertSame( 'Easy Digital Downloads', $this->module->get_name() );
	}

	public function test_exclusive_group(): void {
		$this->assertSame( 'ecommerce', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority(): void {
		$this->assertSame( 20, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_download_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.template', $models['download'] );
	}

	public function test_declares_order_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'sale.order', $models['order'] );
	}

	public function test_declares_invoice_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['invoice'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_downloads(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_downloads'] );
	}

	public function test_default_settings_has_sync_orders(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_orders'] );
	}

	public function test_default_settings_has_auto_confirm_orders(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['auto_confirm_orders'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 3, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_three_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 3, $fields );
	}

	public function test_settings_fields_sync_downloads_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_downloads']['type'] );
	}

	public function test_settings_fields_sync_orders_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_orders']['type'] );
	}

	public function test_settings_fields_auto_confirm_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['auto_confirm_orders']['type'] );
	}

	// ─── Field Mappings ────────────────────────────────────

	public function test_download_mapping_includes_title(): void {
		$result = $this->module->map_to_odoo( 'download', [ 'post_title' => 'eBook Pro' ] );
		$this->assertSame( 'eBook Pro', $result['name'] );
	}

	public function test_download_mapping_includes_price(): void {
		$result = $this->module->map_to_odoo( 'download', [ '_edd_price' => '19.99' ] );
		$this->assertSame( '19.99', $result['list_price'] );
	}

	public function test_download_mapping_includes_description(): void {
		$result = $this->module->map_to_odoo( 'download', [ 'post_content' => 'A great eBook.' ] );
		$this->assertSame( 'A great eBook.', $result['description_sale'] );
	}

	public function test_order_mapping_includes_total(): void {
		$result = $this->module->map_to_odoo( 'order', [ 'total' => '50.00' ] );
		$this->assertSame( '50.00', $result['amount_total'] );
	}

	public function test_order_mapping_includes_status(): void {
		$result = $this->module->map_to_odoo( 'order', [ 'status' => 'sale' ] );
		$this->assertSame( 'sale', $result['state'] );
	}

	public function test_invoice_mapping_includes_state(): void {
		$result = $this->module->map_to_odoo( 'invoice', [ '_invoice_state' => 'posted' ] );
		$this->assertSame( 'posted', $result['state'] );
	}

	public function test_invoice_mapping_includes_partner(): void {
		$result = $this->module->map_to_odoo( 'invoice', [ '_wp4odoo_partner_id' => 42 ] );
		$this->assertSame( 42, $result['partner_id'] );
	}

	// ─── Reverse Mappings ──────────────────────────────────

	public function test_map_from_odoo_download(): void {
		$result = $this->module->map_from_odoo( 'download', [ 'name' => 'Odoo eBook', 'list_price' => '9.99' ] );
		$this->assertSame( 'Odoo eBook', $result['post_title'] );
		$this->assertSame( '9.99', $result['_edd_price'] );
	}

	public function test_map_from_odoo_order(): void {
		$result = $this->module->map_from_odoo( 'order', [ 'amount_total' => '100.00', 'state' => 'sale' ] );
		$this->assertSame( '100.00', $result['total'] );
		$this->assertSame( 'sale', $result['status'] );
	}

	// ─── Boot Guard ────────────────────────────────────────

	public function test_boot_does_not_crash_without_edd(): void {
		// Easy_Digital_Downloads class IS defined in stubs, so boot() will proceed.
		// This test verifies no fatal error occurs during boot.
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_status_available_with_edd(): void {
		// Easy_Digital_Downloads class is defined in stubs.
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_status_has_no_notices_when_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Translatable Fields ──────────────────────────────

	public function test_translatable_fields_for_download(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$fields = $method->invoke( $this->module, 'download' );

		$this->assertSame(
			[ 'name' => 'post_title', 'description_sale' => 'post_content' ],
			$fields
		);
	}

	public function test_translatable_fields_empty_for_order(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'order' ) );
	}

	public function test_translatable_fields_empty_for_invoice(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'invoice' ) );
	}
}
