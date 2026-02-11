<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Module_Base;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub for testing Module_Base protected methods.
 */
class Testable_Module extends Module_Base {

	protected string $id   = 'test';
	protected string $name = 'Test';

	public function __construct() {
		parent::__construct( wp4odoo_test_client_provider(), wp4odoo_test_entity_map() );
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
}
