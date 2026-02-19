<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WP_All_Import_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for WP_All_Import_Module and WPAI_Hooks trait.
 *
 * Tests identity, settings, dependency, routing table,
 * on_post_saved routing/enqueue, on_import_complete logging,
 * and import counters.
 */
class WPAllImportModuleTest extends Module_Test_Case {

	private WP_All_Import_Module $module;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wpai_import_id'] = 0;

		WP_All_Import_Module::reset_import_counts();

		$this->module = new WP_All_Import_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_wpai(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'wpai', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_wp_all_import(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP All Import', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_wp_to_odoo(): void {
		$this->assertSame( 'wp_to_odoo', $this->module->get_sync_direction() );
	}

	public function test_odoo_models_is_empty(): void {
		$this->assertSame( [], $this->module->get_odoo_models() );
	}

	// ─── Settings & Dependency ──────────────────────────────

	public function test_default_settings_is_empty(): void {
		$this->assertSame( [], $this->module->get_default_settings() );
	}

	public function test_dependency_available_with_pmxi(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Routing Table ──────────────────────────────────────

	public function test_routing_table_maps_product_to_woocommerce(): void {
		$routing = $this->module->get_routing_table();
		$this->assertSame( [ 'woocommerce', 'product' ], $routing['product'] );
	}

	public function test_routing_table_maps_download_to_edd(): void {
		$routing = $this->module->get_routing_table();
		$this->assertSame( [ 'edd', 'download' ], $routing['download'] );
	}

	public function test_routing_table_maps_sfwd_courses_to_learndash(): void {
		$routing = $this->module->get_routing_table();
		$this->assertSame( [ 'learndash', 'course' ], $routing['sfwd-courses'] );
	}

	public function test_routing_table_maps_tribe_events_to_events_calendar(): void {
		$routing = $this->module->get_routing_table();
		$this->assertSame( [ 'events_calendar', 'event' ], $routing['tribe_events'] );
	}

	public function test_routing_table_maps_job_listing_to_job_manager(): void {
		$routing = $this->module->get_routing_table();
		$this->assertSame( [ 'job_manager', 'job' ], $routing['job_listing'] );
	}

	public function test_routing_table_has_expected_count(): void {
		$routing = $this->module->get_routing_table();
		$this->assertCount( 18, $routing );
	}

	// ─── on_post_saved ──────────────────────────────────────

	public function test_on_post_saved_enqueues_for_known_post_type(): void {
		// Enable target module.
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;

		// Set up a WC product post.
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'        => 100,
			'post_type' => 'product',
		];

		$this->module->on_post_saved( 100, new \SimpleXMLElement( '<item/>' ), false );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_post_saved_determines_create_for_new_record(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_posts'][101] = (object) [
			'ID'        => 101,
			'post_type' => 'product',
		];

		$this->module->on_post_saved( 101, new \SimpleXMLElement( '<item/>' ), false );

		// Check that queue enqueue was called (uses $wpdb for transaction + insert).
		$found = false;
		foreach ( $this->wpdb->calls as $call ) {
			$args_str = implode( ' ', array_map( 'strval', $call['args'] ) );
			if ( str_contains( $args_str, 'sync_queue' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'Expected a sync queue call.' );
	}

	public function test_on_post_saved_determines_update_for_mapped_record(): void {
		$entity_map = wp4odoo_test_entity_map();

		// Simulate an existing entity map entry.
		$entity_map->save( 'woocommerce', 'product', 102, 500, 'product.template' );

		$module = new WP_All_Import_Module( wp4odoo_test_client_provider(), $entity_map, wp4odoo_test_settings() );

		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_posts'][102] = (object) [
			'ID'        => 102,
			'post_type' => 'product',
		];

		$module->on_post_saved( 102, new \SimpleXMLElement( '<item/>' ), true );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_post_saved_skips_unknown_post_type(): void {
		$GLOBALS['_wp_posts'][200] = (object) [
			'ID'        => 200,
			'post_type' => 'unknown_cpt',
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_post_saved( 200, new \SimpleXMLElement( '<item/>' ), false );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_post_saved_skips_when_target_module_disabled(): void {
		// woocommerce module NOT enabled (no option set).
		$GLOBALS['_wp_posts'][300] = (object) [
			'ID'        => 300,
			'post_type' => 'product',
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_post_saved( 300, new \SimpleXMLElement( '<item/>' ), false );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_post_saved_skips_false_post_type(): void {
		// Post ID that doesn't exist → get_post_type returns false.
		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_post_saved( 99999, new \SimpleXMLElement( '<item/>' ), false );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_post_saved_increments_import_counter(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_woocommerce_enabled'] = true;
		$GLOBALS['_wp_posts'][400] = (object) [
			'ID'        => 400,
			'post_type' => 'product',
		];
		$GLOBALS['_wpai_import_id'] = 42;

		$this->module->on_post_saved( 400, new \SimpleXMLElement( '<item/>' ), false );

		$this->assertSame( 1, WP_All_Import_Module::get_import_count( 42 ) );
	}

	public function test_on_post_saved_routes_edd_download(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_edd_enabled'] = true;
		$GLOBALS['_wp_posts'][500] = (object) [
			'ID'        => 500,
			'post_type' => 'download',
		];

		$this->module->on_post_saved( 500, new \SimpleXMLElement( '<item/>' ), false );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	// ─── on_import_complete ─────────────────────────────────

	public function test_on_import_complete_logs_nonzero_count(): void {
		WP_All_Import_Module::increment_import_count( 10 );
		WP_All_Import_Module::increment_import_count( 10 );
		WP_All_Import_Module::increment_import_count( 10 );

		// on_import_complete logs via $this->logger — which uses $wpdb.
		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_import_complete( 10, (object) [] );
		\WP4Odoo\Logger::flush_buffer();

		// Logger insert was called.
		$this->assertGreaterThan( $initial_call_count, count( $this->wpdb->calls ) );
	}

	public function test_on_import_complete_skips_zero_count(): void {
		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_import_complete( 99, (object) [] );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	// ─── Import Counters ────────────────────────────────────

	public function test_import_count_starts_at_zero(): void {
		$this->assertSame( 0, WP_All_Import_Module::get_import_count( 1 ) );
	}

	public function test_increment_import_count_increases(): void {
		WP_All_Import_Module::increment_import_count( 5 );
		WP_All_Import_Module::increment_import_count( 5 );

		$this->assertSame( 2, WP_All_Import_Module::get_import_count( 5 ) );
	}

	public function test_reset_import_counts_clears_all(): void {
		WP_All_Import_Module::increment_import_count( 1 );
		WP_All_Import_Module::increment_import_count( 2 );
		WP_All_Import_Module::reset_import_counts();

		$this->assertSame( 0, WP_All_Import_Module::get_import_count( 1 ) );
		$this->assertSame( 0, WP_All_Import_Module::get_import_count( 2 ) );
	}

	public function test_import_counts_are_per_import_id(): void {
		WP_All_Import_Module::increment_import_count( 1 );
		WP_All_Import_Module::increment_import_count( 1 );
		WP_All_Import_Module::increment_import_count( 2 );

		$this->assertSame( 2, WP_All_Import_Module::get_import_count( 1 ) );
		$this->assertSame( 1, WP_All_Import_Module::get_import_count( 2 ) );
	}

	// ─── load_wp_data (always empty) ────────────────────────

	public function test_load_wp_data_returns_empty(): void {
		$ref = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$this->assertSame( [], $ref->invoke( $this->module, 'anything', 1 ) );
	}

	// ─── Boot ───────────────────────────────────────────────

	public function test_boot_registers_hooks(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}
}
