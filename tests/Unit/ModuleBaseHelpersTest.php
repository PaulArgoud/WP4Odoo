<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub exposing should_sync() and poll_entity_changes() for testing.
 */
class ModuleBaseHelpersTestModule extends Module_Base {

	/**
	 * Overridable settings returned by get_settings().
	 *
	 * @var array<string, mixed>
	 */
	public array $test_settings = [];

	/**
	 * Whether the module is currently importing (test override).
	 *
	 * @var bool
	 */
	public bool $test_importing = false;

	public function __construct() {
		parent::__construct( 'helpers_test', 'HelpersTest', wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$this->odoo_models = [
			'product' => 'product.product',
			'order'   => 'sale.order',
		];
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [
			'sync_products' => true,
			'sync_orders'   => true,
		];
	}

	public function get_settings(): array {
		return array_merge( $this->get_default_settings(), $this->test_settings );
	}

	protected function is_importing(): bool {
		return $this->test_importing;
	}

	/**
	 * Expose should_sync() for testing.
	 */
	public function test_should_sync( string $setting_key ): bool {
		return $this->should_sync( $setting_key );
	}

	/**
	 * Expose poll_entity_changes() for testing.
	 */
	public function test_poll_entity_changes( string $entity_type, array $items, string $id_field = 'id' ): void {
		$this->poll_entity_changes( $entity_type, $items, $id_field );
	}
}

/**
 * Tests for Module_Base::should_sync() and poll_entity_changes().
 */
class ModuleBaseHelpersTest extends TestCase {

	private ModuleBaseHelpersTestModule $module;

	/** @var \WP_DB_Stub */
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		// Initialize wpdb stub.
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$this->wpdb->insert_id = 1;
		$wpdb = $this->wpdb;

		$this->module = new ModuleBaseHelpersTestModule();
	}

	// ─── should_sync() ──────────────────────────────────

	public function test_should_sync_returns_true_when_enabled_and_not_importing(): void {
		$this->module->test_settings  = [ 'sync_products' => true ];
		$this->module->test_importing = false;

		$this->assertTrue( $this->module->test_should_sync( 'sync_products' ) );
	}

	public function test_should_sync_returns_false_when_importing(): void {
		$this->module->test_settings  = [ 'sync_products' => true ];
		$this->module->test_importing = true;

		$this->assertFalse( $this->module->test_should_sync( 'sync_products' ) );
	}

	public function test_should_sync_returns_false_when_setting_disabled(): void {
		$this->module->test_settings  = [ 'sync_products' => false ];
		$this->module->test_importing = false;

		$this->assertFalse( $this->module->test_should_sync( 'sync_products' ) );
	}

	public function test_should_sync_returns_true_for_default_enabled_setting(): void {
		// 'sync_products' defaults to true via get_default_settings().
		$this->module->test_settings  = [];
		$this->module->test_importing = false;

		$this->assertTrue( $this->module->test_should_sync( 'sync_products' ) );
	}

	public function test_should_sync_returns_false_for_nonexistent_key(): void {
		$this->module->test_settings  = [];
		$this->module->test_importing = false;

		$this->assertFalse( $this->module->test_should_sync( 'sync_nonexistent' ) );
	}

	public function test_should_sync_importing_overrides_enabled_setting(): void {
		$this->module->test_settings  = [ 'sync_products' => true ];
		$this->module->test_importing = true;

		$this->assertFalse( $this->module->test_should_sync( 'sync_products' ) );
	}

	// ─── poll_entity_changes() ──────────────────────────

	public function test_poll_enqueues_create_for_new_items(): void {
		// No existing mappings.
		$this->wpdb->get_results_return = [];

		$items = [
			[ 'id' => 10, 'name' => 'Widget A' ],
			[ 'id' => 20, 'name' => 'Widget B' ],
		];

		$this->module->test_poll_entity_changes( 'product', $items );

		$inserts = $this->get_queue_inserts();
		$creates = array_filter( $inserts, fn( $data ) => 'create' === $data['action'] );

		$this->assertCount( 2, $creates );
	}

	public function test_poll_enqueues_delete_for_missing_items(): void {
		// Existing mapping for wp_id=99 that is no longer in the items.
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => 99, 'odoo_id' => 555, 'sync_hash' => 'abc123' ],
		];

		// Poll with empty items — item 99 should be deleted.
		$this->module->test_poll_entity_changes( 'product', [] );

		$inserts = $this->get_queue_inserts();
		$deletes = array_filter( $inserts, fn( $data ) => 'delete' === $data['action'] );

		$this->assertCount( 1, $deletes );
		$delete = array_values( $deletes )[0];
		$this->assertSame( 99, $delete['wp_id'] );
		$this->assertSame( 555, $delete['odoo_id'] );
	}

	public function test_poll_uses_custom_id_field(): void {
		$this->wpdb->get_results_return = [];

		$items = [
			[ 'orderNumber' => 42, 'total' => 100.0 ],
		];

		$this->module->test_poll_entity_changes( 'order', $items, 'orderNumber' );

		$inserts = $this->get_queue_inserts();
		$this->assertCount( 1, $inserts );
		$this->assertSame( 42, $inserts[0]['wp_id'] );
	}

	public function test_poll_skips_unchanged_items(): void {
		// Compute the expected hash for the item data (without the id field).
		$hash_data = [ 'name' => 'Widget A' ];
		$hash      = $this->module->generate_sync_hash( $hash_data );

		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => 10, 'odoo_id' => 100, 'sync_hash' => $hash ],
		];

		$item = [ 'id' => 10, 'name' => 'Widget A' ];

		$this->module->test_poll_entity_changes( 'product', [ $item ] );

		$inserts = $this->get_queue_inserts();
		$this->assertEmpty( $inserts );
	}

	public function test_poll_enqueues_update_for_changed_items(): void {
		// Existing mapping with an old hash that won't match.
		$this->wpdb->get_results_return = [
			(object) [ 'wp_id' => 10, 'odoo_id' => 100, 'sync_hash' => 'old_hash_that_does_not_match' ],
		];

		$item = [ 'id' => 10, 'name' => 'Widget A (updated)' ];

		$this->module->test_poll_entity_changes( 'product', [ $item ] );

		$inserts = $this->get_queue_inserts();
		$updates = array_filter( $inserts, fn( $data ) => 'update' === $data['action'] );

		$this->assertCount( 1, $updates );
		$update = array_values( $updates )[0];
		$this->assertSame( 10, $update['wp_id'] );
		$this->assertSame( 100, $update['odoo_id'] );
	}

	// ─── Helpers ────────────────────────────────────────

	/**
	 * Extract queue insert data from $wpdb->calls.
	 *
	 * Queue_Manager::push() → Sync_Queue_Repository::enqueue() → $wpdb->insert().
	 * We filter for inserts into the sync_queue table and return their data arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_queue_inserts(): array {
		$inserts = array_filter(
			$this->wpdb->calls,
			fn( $call ) => 'insert' === $call['method'] && str_contains( $call['args'][0], 'sync_queue' )
		);

		return array_values( array_map( fn( $call ) => $call['args'][1], $inserts ) );
	}
}
