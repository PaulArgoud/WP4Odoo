<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WC_Rental_Module;
use WP4Odoo\Modules\WC_Rental_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Rental_Module, WC_Rental_Handler, and WC_Rental_Hooks.
 */
class WCRentalModuleTest extends TestCase {

	private WC_Rental_Module $module;
	private WC_Rental_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new WC_Rental_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new WC_Rental_Handler( new Logger( 'wc_rental', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [] );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id(): void {
		$this->assertSame( 'wc_rental', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'WC Rental', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_push_only(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	// ─── Required Modules ──────────────────────────────────

	public function test_requires_woocommerce(): void {
		$this->assertSame( [ 'woocommerce' ], $this->module->get_required_modules() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_rental_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'sale.order', $models['rental'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 1, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_rentals(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_rentals'] );
	}

	public function test_default_settings_has_rental_meta_key(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( '_rental', $settings['rental_meta_key'] );
	}

	public function test_default_settings_has_rental_start(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( '_rental_start_date', $settings['rental_start'] );
	}

	public function test_default_settings_has_rental_return(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( '_rental_return_date', $settings['rental_return'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_rentals(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_rentals', $fields );
		$this->assertSame( 'checkbox', $fields['sync_rentals']['type'] );
	}

	public function test_settings_fields_exposes_rental_meta_key(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'rental_meta_key', $fields );
		$this->assertSame( 'text', $fields['rental_meta_key']['type'] );
	}

	public function test_settings_fields_exposes_rental_start(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'rental_start', $fields );
		$this->assertSame( 'text', $fields['rental_start']['type'] );
	}

	public function test_settings_fields_exposes_rental_return(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'rental_return', $fields );
		$this->assertSame( 'text', $fields['rental_return']['type'] );
	}

	public function test_settings_fields_has_exactly_four_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 4, $fields );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_available_with_woocommerce(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Boot ──────────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── map_to_odoo: identity pass-through ────────────────

	public function test_map_to_odoo_returns_input(): void {
		$data = [
			'partner_id'  => 42,
			'date_order'  => '2026-01-15',
			'state'       => 'sale',
			'is_rental'   => true,
			'pickup_date' => '2026-02-01',
			'return_date' => '2026-02-10',
		];

		$result = $this->module->map_to_odoo( 'rental', $data );
		$this->assertSame( $data, $result );
	}

	// ─── Dedup Domains ─────────────────────────────────────

	public function test_dedup_rental_by_ref(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$domain = $method->invoke( $this->module, 'rental', [ 'client_order_ref' => 'WC-RENTAL-100' ] );
		$this->assertSame( [ [ 'client_order_ref', '=', 'WC-RENTAL-100' ] ], $domain );
	}

	public function test_dedup_empty_without_ref(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$domain = $method->invoke( $this->module, 'rental', [] );
		$this->assertSame( [], $domain );
	}

	// ─── Hooks: on_order_status_changed ────────────────────

	public function test_on_order_status_changed_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_rental_settings'] = [ 'sync_rentals' => false ];

		$this->module->on_order_status_changed( 100, 'pending', 'completed' );

		$this->assertQueueEmpty();
	}

	public function test_on_order_status_changed_skips_non_syncable_status(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_rental_settings'] = [ 'sync_rentals' => true ];

		$this->module->on_order_status_changed( 100, 'completed', 'cancelled' );

		$this->assertQueueEmpty();
	}

	public function test_on_order_status_changed_skips_zero_order_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_rental_settings'] = [ 'sync_rentals' => true ];

		$this->module->on_order_status_changed( 0, 'pending', 'completed' );

		$this->assertQueueEmpty();
	}

	public function test_on_order_status_changed_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wc_rental_settings'] = [ 'sync_rentals' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'wc_rental' => true ] );

		$this->module->on_order_status_changed( 100, 'pending', 'completed' );

		$this->assertQueueEmpty();
	}

	// ─── Handler: order_has_rental_items ───────────────────

	public function test_order_has_rental_items_returns_false_for_missing_order(): void {
		$this->assertFalse( $this->handler->order_has_rental_items( 999, '_rental' ) );
	}

	// ─── Helpers ───────────────────────────────────────────

	private function assertQueueContains( string $module, string $entity, string $action, int $wp_id ): void {
		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => 'insert' === $c['method'] );
		foreach ( $inserts as $call ) {
			$data = $call['args'][1] ?? [];
			if ( ( $data['module'] ?? '' ) === $module
				&& ( $data['entity_type'] ?? '' ) === $entity
				&& ( $data['action'] ?? '' ) === $action
				&& ( $data['wp_id'] ?? 0 ) === $wp_id ) {
				$this->assertTrue( true );
				return;
			}
		}
		$this->fail( "Queue does not contain [{$module}, {$entity}, {$action}, {$wp_id}]" );
	}

	private function assertQueueEmpty(): void {
		$inserts = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'insert' === $c['method'] && str_contains( $c['args'][0] ?? '', 'sync_queue' )
		);
		$this->assertEmpty( $inserts, 'Queue should be empty.' );
	}
}
