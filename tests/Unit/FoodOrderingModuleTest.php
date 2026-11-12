<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\Food_Ordering_Module;

/**
 * Unit tests for Food_Ordering_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Food_Ordering_Module::class)]
class FoodOrderingModuleTest extends Module_Test_Case {

	private Food_Ordering_Module $module;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->module = new Food_Ordering_Module(
			wp4odoo_test_client_provider(),
			wp4odoo_test_entity_map(),
			wp4odoo_test_settings()
		);
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
	}

	// ─── Identity ────────────────────────────────────────────

	public function test_module_identity(): void {
		$this->assertModuleIdentity(
			$this->module,
			'food_ordering',
			'Food Ordering',
			'',
			'wp_to_odoo'
		);
	}

	public function test_module_id_is_food_ordering(): void {
		$this->assertSame( 'food_ordering', $this->module->get_id() );
	}

	public function test_module_name_is_food_ordering(): void {
		$this->assertSame( 'Food Ordering', $this->module->get_name() );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_odoo_models(): void {
		$this->assertOdooModels( $this->module, [ 'order' => 'pos.order' ] );
	}

	public function test_declares_order_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'pos.order', $models['order'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 1, $models );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings(): void {
		$this->assertDefaultSettings( $this->module, [
			'sync_gloriafoood'  => true,
			'sync_wppizza'      => true,
			'sync_restropress'  => true,
		] );
	}

	public function test_default_settings_has_sync_gloriafoood(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_gloriafoood'] );
	}

	public function test_default_settings_has_sync_wppizza(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_wppizza'] );
	}

	public function test_default_settings_has_sync_restropress(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_restropress'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 3, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_are_checkboxes(): void {
		$this->assertSettingsFieldsAreCheckboxes( $this->module, [
			'sync_gloriafoood',
			'sync_wppizza',
			'sync_restropress',
		] );
	}

	public function test_settings_fields_has_sync_gloriafoood(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_gloriafoood']['type'] );
	}

	public function test_settings_fields_has_sync_wppizza(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_wppizza']['type'] );
	}

	public function test_settings_fields_has_sync_restropress(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_restropress']['type'] );
	}

	public function test_settings_fields_count(): void {
		$this->assertCount( 3, $this->module->get_settings_fields() );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Deduplication ──────────────────────────────────────

	public function test_order_dedup_with_pos_reference(): void {
		$method = new \ReflectionMethod( Food_Ordering_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'order', [ 'pos_reference' => 'WP-GLORIAFOOOD-20240115120000' ] );

		$this->assertCount( 1, $domain );
		$this->assertSame( [ 'pos_reference', '=', 'WP-GLORIAFOOOD-20240115120000' ], $domain[0] );
	}

	public function test_order_dedup_without_pos_reference_returns_empty(): void {
		$method = new \ReflectionMethod( Food_Ordering_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'order', [ 'amount_total' => 33.0 ] );

		$this->assertEmpty( $domain );
	}

	public function test_unknown_entity_dedup_returns_empty(): void {
		$method = new \ReflectionMethod( Food_Ordering_Module::class, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'unknown', [ 'pos_reference' => 'WP-TEST-123' ] );

		$this->assertEmpty( $domain );
	}

	// ─── Boot ───────────────────────────────────────────────

	public function test_boot_does_not_throw(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_to_odoo_returns_identity(): void {
		$data = [
			'partner_id'    => 42,
			'date_order'    => '2024-01-15 12:00:00',
			'amount_total'  => 33.0,
			'pos_reference' => 'WP-GLORIAFOOOD-20240115120000',
			'lines'         => [ [ 0, 0, [ 'full_product_name' => 'Pizza', 'qty' => 1.0, 'price_unit' => 12.50 ] ] ],
			'note'          => 'Extra cheese',
		];

		$mapped = $this->module->map_to_odoo( 'order', $data );

		$this->assertSame( $data, $mapped );
	}

	public function test_map_to_odoo_passes_through_all_fields(): void {
		$data = [
			'partner_id'   => 7,
			'amount_total' => 15.0,
		];

		$mapped = $this->module->map_to_odoo( 'order', $data );

		$this->assertSame( 7, $mapped['partner_id'] );
		$this->assertSame( 15.0, $mapped['amount_total'] );
	}

	// ─── load_wp_data ───────────────────────────────────────

	public function test_load_wp_data_for_order_reads_from_option(): void {
		$method = new \ReflectionMethod( Food_Ordering_Module::class, 'load_wp_data' );

		$stored_data = [
			'partner_id'    => 42,
			'date_order'    => '2024-01-15 12:00:00',
			'amount_total'  => 33.0,
			'pos_reference' => 'WP-GLORIAFOOOD-20240115120000',
			'lines'         => [ [ 0, 0, [ 'full_product_name' => 'Pizza', 'qty' => 1.0, 'price_unit' => 12.50 ] ] ],
		];

		$GLOBALS['_wp_options']['wp4odoo_food_order_99'] = $stored_data;

		$data = $method->invoke( $this->module, 'order', 99 );

		$this->assertSame( $stored_data, $data );
	}

	public function test_load_wp_data_returns_empty_when_no_option(): void {
		$method = new \ReflectionMethod( Food_Ordering_Module::class, 'load_wp_data' );

		$data = $method->invoke( $this->module, 'order', 999 );

		$this->assertEmpty( $data );
	}

	public function test_load_wp_data_returns_empty_for_non_order_entity(): void {
		$method = new \ReflectionMethod( Food_Ordering_Module::class, 'load_wp_data' );

		$data = $method->invoke( $this->module, 'product', 1 );

		$this->assertEmpty( $data );
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_default_mappings_include_partner_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'partner_id', $ref->getValue( $this->module )['order']['partner_id'] );
	}

	public function test_default_mappings_include_date_order(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'date_order', $ref->getValue( $this->module )['order']['date_order'] );
	}

	public function test_default_mappings_include_amount_total(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'amount_total', $ref->getValue( $this->module )['order']['amount_total'] );
	}

	public function test_default_mappings_include_pos_reference(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'pos_reference', $ref->getValue( $this->module )['order']['pos_reference'] );
	}

	public function test_default_mappings_include_lines(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'lines', $ref->getValue( $this->module )['order']['lines'] );
	}

	public function test_default_mappings_include_note(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'note', $ref->getValue( $this->module )['order']['note'] );
	}
}
