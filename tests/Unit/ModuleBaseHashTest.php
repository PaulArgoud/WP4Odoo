<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Module_Base;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub for testing Module_Base protected methods.
 */
class Testable_Module extends Module_Base {


	public function __construct() {
		parent::__construct( 'test', 'Test', wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [];
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
}
