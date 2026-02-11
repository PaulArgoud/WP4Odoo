<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Module_Base;
use WP4Odoo\Sync_Result;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub for testing Module_Base protected methods.
 */
class Testable_Module extends Module_Base {

	/**
	 * Custom default settings for testing auto_post_invoice().
	 *
	 * @var array
	 */
	public array $test_settings = [];

	/**
	 * Recorded push_to_odoo() calls.
	 *
	 * @var array<int, array{entity_type: string, action: string, wp_id: int}>
	 */
	public array $push_calls = [];

	public function __construct() {
		parent::__construct( 'test', 'Test', wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	public function boot(): void {}

	/**
	 * Override push_to_odoo() to record calls without hitting Odoo.
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		$this->push_calls[] = compact( 'entity_type', 'action', 'wp_id' );
		return Sync_Result::success( 0 );
	}

	public function get_default_settings(): array {
		return $this->test_settings;
	}

	/**
	 * Expose generate_sync_hash() for testing.
	 */
	public function hash( array $data ): string {
		return $this->generate_sync_hash( $data );
	}

	/**
	 * Expose is_importing() for testing.
	 */
	public function check_importing(): bool {
		return $this->is_importing();
	}

	/**
	 * Expose mark_importing() for testing.
	 */
	public function start_importing(): void {
		self::mark_importing();
	}

	/**
	 * Expose clear_importing() for testing.
	 */
	public function stop_importing(): void {
		self::clear_importing();
	}

	/**
	 * Expose auto_post_invoice() for testing.
	 */
	public function test_auto_post_invoice( string $setting_key, string $entity_type, int $wp_id ): void {
		$this->auto_post_invoice( $setting_key, $entity_type, $wp_id );
	}

	/**
	 * Expose ensure_entity_synced() for testing.
	 */
	public function test_ensure_entity_synced( string $entity_type, int $wp_id ): void {
		$this->ensure_entity_synced( $entity_type, $wp_id );
	}
}

/**
 * Unit tests for Module_Base::generate_sync_hash().
 */
class ModuleBaseHashTest extends TestCase {

	private Testable_Module $module;

	protected function setUp(): void {
		$this->module = new Testable_Module();
	}

	public function test_consistent_hash(): void {
		$data = [ 'email' => 'test@example.com', 'name' => 'John' ];
		$hash1 = $this->module->hash( $data );
		$hash2 = $this->module->hash( $data );

		$this->assertSame( $hash1, $hash2 );
		$this->assertSame( 64, strlen( $hash1 ), 'SHA-256 hash should be 64 hex chars' );
	}

	public function test_key_order_independent(): void {
		$hash1 = $this->module->hash( [ 'a' => 1, 'b' => 2 ] );
		$hash2 = $this->module->hash( [ 'b' => 2, 'a' => 1 ] );

		$this->assertSame( $hash1, $hash2 );
	}

	public function test_different_data_different_hash(): void {
		$hash1 = $this->module->hash( [ 'email' => 'alice@example.com' ] );
		$hash2 = $this->module->hash( [ 'email' => 'bob@example.com' ] );

		$this->assertNotSame( $hash1, $hash2 );
	}

	public function test_empty_data(): void {
		$hash = $this->module->hash( [] );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $hash );
	}

	// ─── Dependency Status (default) ──────────────────────

	public function test_default_dependency_status_is_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_default_dependency_status_has_no_notices(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Anti-loop flag ──────────────────────────────────

	protected function tearDown(): void {
		// Always clear the flag to prevent test pollution.
		$this->module->stop_importing();
	}

	public function test_is_importing_defaults_to_false(): void {
		$this->assertFalse( $this->module->check_importing() );
	}

	public function test_mark_importing_sets_flag(): void {
		$this->module->start_importing();

		$this->assertTrue( $this->module->check_importing() );
	}

	public function test_clear_importing_resets_flag(): void {
		$this->module->start_importing();
		$this->module->stop_importing();

		$this->assertFalse( $this->module->check_importing() );
	}

	public function test_importing_flag_is_shared_across_instances(): void {
		$other = new Testable_Module();
		$this->module->start_importing();

		$this->assertTrue( $other->check_importing() );
	}

	// ─── Synthetic IDs ──────────────────────────────────────

	public function test_encode_synthetic_id(): void {
		$id = Module_Base::encode_synthetic_id( 5, 42 );

		$this->assertSame( 5_000_042, $id );
	}

	public function test_decode_synthetic_id(): void {
		[ $primary, $secondary ] = Module_Base::decode_synthetic_id( 5_000_042 );

		$this->assertSame( 5, $primary );
		$this->assertSame( 42, $secondary );
	}

	public function test_synthetic_id_roundtrip(): void {
		$encoded = Module_Base::encode_synthetic_id( 123, 456 );
		[ $primary, $secondary ] = Module_Base::decode_synthetic_id( $encoded );

		$this->assertSame( 123, $primary );
		$this->assertSame( 456, $secondary );
	}

	public function test_encode_synthetic_id_overflow_throws(): void {
		$this->expectException( \OverflowException::class );

		Module_Base::encode_synthetic_id( 1, 1_000_000 );
	}

	public function test_encode_synthetic_id_boundary_accepts_max(): void {
		$id = Module_Base::encode_synthetic_id( 1, 999_999 );

		$this->assertSame( 1_999_999, $id );
	}

	// ─── auto_post_invoice() ────────────────────────────────

	public function test_auto_post_invoice_skips_when_setting_disabled(): void {
		$this->module->test_settings = [];

		// Should not throw — just returns early.
		$this->module->test_auto_post_invoice( 'auto_post', 'invoice', 1 );

		$this->assertTrue( true, 'No exception means early return.' );
	}

	public function test_auto_post_invoice_skips_when_no_mapping(): void {
		global $wpdb;
		$wpdb             = new \WP_DB_Stub();
		$wpdb->get_var_return = null; // No mapping.

		$this->module->test_settings = [ 'auto_post' => '1' ];

		$this->module->test_auto_post_invoice( 'auto_post', 'invoice', 99 );

		$this->assertTrue( true, 'No exception means early return (no mapping).' );
	}

	public function test_auto_post_invoice_catches_exception_when_client_fails(): void {
		global $wpdb;
		$wpdb             = new \WP_DB_Stub();
		$wpdb->get_var_return = '42'; // Mapping exists → odoo_id=42.

		$this->module->test_settings = [ 'auto_post' => '1' ];

		// Client is unconfigured → execute() throws RuntimeException,
		// which is caught internally by auto_post_invoice().
		$this->module->test_auto_post_invoice( 'auto_post', 'invoice', 10 );

		$this->assertTrue( true, 'Exception was caught internally — no uncaught throw.' );
	}

	// ─── ensure_entity_synced() ─────────────────────────────

	public function test_ensure_entity_synced_skips_zero_wp_id(): void {
		$this->module->test_ensure_entity_synced( 'product', 0 );

		$this->assertEmpty( $this->module->push_calls );
	}

	public function test_ensure_entity_synced_skips_negative_wp_id(): void {
		$this->module->test_ensure_entity_synced( 'product', -1 );

		$this->assertEmpty( $this->module->push_calls );
	}

	public function test_ensure_entity_synced_skips_existing_mapping(): void {
		global $wpdb;
		$wpdb             = new \WP_DB_Stub();
		$wpdb->get_var_return = '55'; // Mapping exists.

		$this->module->test_ensure_entity_synced( 'product', 10 );

		$this->assertEmpty( $this->module->push_calls );
	}

	public function test_ensure_entity_synced_pushes_when_no_mapping(): void {
		global $wpdb;
		$wpdb             = new \WP_DB_Stub();
		$wpdb->get_var_return = null; // No mapping.

		$this->module->test_ensure_entity_synced( 'product', 10 );

		$this->assertCount( 1, $this->module->push_calls );
		$this->assertSame( 'product', $this->module->push_calls[0]['entity_type'] );
		$this->assertSame( 'create', $this->module->push_calls[0]['action'] );
		$this->assertSame( 10, $this->module->push_calls[0]['wp_id'] );
	}
}
