<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Module_Base;
use WP4Odoo\Modules\Dokan_Module;
use WP4Odoo\Modules\Food_Ordering_Module;
use WP4Odoo\Modules\Sprout_Invoices_Module;
use WP4Odoo\Modules\WC_Returns_Module;
use WP4Odoo\Modules\WPERP_Module;
use WP4Odoo\Schema_Cache;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Tests that Module_Base::map_to_odoo() reconciles mapped fields with the Odoo schema.
 */
#[CoversClass(Module_Base::class)]
class SchemaReconciliationTest extends Module_Test_Case {

	private WPERP_Module $erp;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb->get_var_return = 'wp_erp_hr_employees';
		$this->erp                  = new WPERP_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		Schema_Cache::flush();
	}

	protected function tearDown(): void {
		Schema_Cache::flush();
		parent::tearDown();
	}

	/**
	 * @param array<int, string> $fields Field names present in the fake Odoo schema.
	 */
	private function seed_schema( string $model, array $fields ): void {
		$schema = [];
		foreach ( $fields as $f ) {
			$schema[ $f ] = [ 'type' => 'char' ];
		}
		$GLOBALS['_wp_transients'][ 'wp4odoo_schema_' . md5( $model ) ] = $schema;
		Schema_Cache::flush();
	}

	public function test_renamed_field_is_sent_under_its_new_name(): void {
		$this->seed_schema( 'hr.employee', [ 'name', 'work_email', 'job_title', 'sex' ] );

		$mapped = $this->erp->map_to_odoo( 'employee', [ 'name' => 'Ada', 'gender' => 'female' ] );

		$this->assertSame( 'female', $mapped['sex'] );
		$this->assertArrayNotHasKey( 'gender', $mapped );
		$this->assertSame( 'Ada', $mapped['name'] );
	}

	public function test_known_field_is_left_untouched(): void {
		$this->seed_schema( 'hr.employee', [ 'name', 'gender', 'sex' ] );

		$mapped = $this->erp->map_to_odoo( 'employee', [ 'name' => 'Ada', 'gender' => 'female' ] );

		$this->assertSame( 'female', $mapped['gender'] );
		$this->assertArrayNotHasKey( 'sex', $mapped );
	}

	public function test_unknown_field_without_alias_is_dropped(): void {
		$this->seed_schema( 'hr.employee', [ 'name', 'work_email' ] );

		$mapped = $this->erp->map_to_odoo( 'employee', [ 'name' => 'Ada', 'gender' => 'female' ] );

		$this->assertSame( [ 'name' => 'Ada' ], $mapped );
	}

	public function test_nothing_changes_when_schema_is_unavailable(): void {
		// No seeded schema and a client that cannot be reached: values pass through.
		$mapped = $this->erp->map_to_odoo( 'employee', [ 'name' => 'Ada', 'gender' => 'female' ] );

		$this->assertSame( 'female', $mapped['gender'] );
	}

	public function test_sprout_payment_ref_becomes_memo(): void {
		$this->seed_schema( 'account.payment', [ 'partner_id', 'amount', 'date', 'memo' ] );
		$module = new Sprout_Invoices_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$mapped = $module->map_to_odoo( 'payment', [ 'partner_id' => 5, 'amount' => 10.0, 'ref' => 'INV-1' ] );

		$this->assertSame( 'INV-1', $mapped['memo'] );
		$this->assertArrayNotHasKey( 'ref', $mapped );
	}

	public function test_wc_returns_picking_lines_use_move_ids_on_odoo_19(): void {
		$this->seed_schema( 'stock.picking', [ 'origin', 'picking_type_id', 'location_id', 'location_dest_id', 'move_ids' ] );
		$module = new WC_Returns_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$lines  = [ [ 0, 0, [ 'product_id' => 1 ] ] ];
		$mapped = $module->map_to_odoo( 'return_picking', [ 'origin' => 'WC-1', 'move_ids_without_package' => $lines ] );

		$this->assertSame( $lines, $mapped['move_ids'] );
		$this->assertArrayNotHasKey( 'move_ids_without_package', $mapped );
	}

	public function test_food_order_note_becomes_internal_note(): void {
		$this->seed_schema( 'pos.order', [ 'partner_id', 'internal_note' ] );
		$module = new Food_Ordering_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$mapped = $module->map_to_odoo( 'order', [ 'partner_id' => 3, 'note' => 'No onions' ] );

		$this->assertSame( 'No onions', $mapped['internal_note'] );
		$this->assertArrayNotHasKey( 'note', $mapped );
	}

	public function test_marketplace_payout_dedup_uses_memo_after_rename(): void {
		$module = new Dokan_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$method = new \ReflectionMethod( $module, 'get_dedup_domain' );
		$method->setAccessible( true );

		$this->assertSame( [ [ 'memo', '=', 'WD-9' ] ], $method->invoke( $module, 'payout', [ 'memo' => 'WD-9' ] ) );
		$this->assertSame( [ [ 'ref', '=', 'WD-9' ] ], $method->invoke( $module, 'payout', [ 'ref' => 'WD-9' ] ) );
	}

	public function test_sprout_payment_dedup_uses_memo_after_rename(): void {
		$module = new Sprout_Invoices_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$method = new \ReflectionMethod( $module, 'get_dedup_domain' );
		$method->setAccessible( true );

		$this->assertSame( [ [ 'memo', '=', 'P-1' ] ], $method->invoke( $module, 'payment', [ 'memo' => 'P-1' ] ) );
		$this->assertSame( [ [ 'ref', '=', 'I-1' ] ], $method->invoke( $module, 'invoice', [ 'ref' => 'I-1' ] ) );
	}
}
