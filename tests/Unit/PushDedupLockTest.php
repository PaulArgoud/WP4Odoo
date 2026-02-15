<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Error_Type;
use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;
use WP4Odoo\Sync_Result;
use PHPUnit\Framework\TestCase;

/**
 * Concrete stub that exposes push_to_odoo for lock testing.
 *
 * Injects a MockTransport into the Odoo_Client via reflection
 * so the real push_to_odoo create path executes fully.
 */
class PushDedupLockTestModule extends Module_Base {

	/** @var MockTransport */
	public MockTransport $mock_transport;

	public function __construct() {
		$transport = new MockTransport();
		$transport->return_value = 42; // Default: create returns ID 42.
		$this->mock_transport    = $transport;

		$client_provider = function () use ( $transport ): Odoo_Client {
			$client = new Odoo_Client();

			// Inject transport + mark connected via reflection.
			$ref = new \ReflectionClass( $client );

			$tp = $ref->getProperty( 'transport' );
			$tp->setValue( $client, $transport );

			$cp = $ref->getProperty( 'connected' );
			$cp->setValue( $client, true );

			return $client;
		};

		parent::__construct( 'lock_test', 'LockTest', $client_provider, wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$this->odoo_models      = [ 'product' => 'product.product' ];
		$this->default_mappings = [
			'product' => [
				'name'  => 'name',
				'price' => 'list_price',
			],
		];
	}

	public function boot(): void {}

	public function get_default_settings(): array {
		return [];
	}

	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return [
			'name'  => 'Test Product',
			'price' => '19.99',
		];
	}
}

/**
 * Unit tests for the Push_Lock trait (advisory lock on push_to_odoo create path).
 *
 * Verifies that the TOCTOU protection pattern works correctly:
 * - Lock is acquired before create
 * - Lock is released on success and on failure
 * - Lock timeout returns Transient error
 * - Update path does NOT acquire lock
 */
class PushDedupLockTest extends TestCase {

	private \WP_DB_Stub $wpdb;
	private PushDedupLockTestModule $module;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$this->module = new PushDedupLockTestModule();

		Queue_Manager::reset();
	}

	protected function tearDown(): void {
		Queue_Manager::reset();
	}

	/**
	 * Helper: get all wpdb calls of a given method.
	 */
	private function get_calls( string $method ): array {
		return array_values(
			array_filter( $this->wpdb->calls, fn( $c ) => $method === $c['method'] )
		);
	}

	/**
	 * Helper: check if any prepare call contains a string.
	 */
	private function has_prepare_containing( string $needle ): bool {
		foreach ( $this->get_calls( 'prepare' ) as $call ) {
			if ( str_contains( $call['args'][0], $needle ) ) {
				return true;
			}
		}
		return false;
	}

	// ─── Create path acquires lock ─────────────────────────

	public function test_create_acquires_advisory_lock(): void {
		// No existing mapping.
		$this->wpdb->get_var_return = null;

		$result = $this->module->push_to_odoo( 'product', 'create', 1 );

		$this->assertTrue( $result->succeeded() );
		$this->assertTrue( $this->has_prepare_containing( 'GET_LOCK' ) );
	}

	public function test_create_releases_lock_on_success(): void {
		$this->wpdb->get_var_return = null;

		$this->module->push_to_odoo( 'product', 'create', 1 );

		$this->assertTrue( $this->has_prepare_containing( 'RELEASE_LOCK' ) );
	}

	public function test_create_releases_lock_on_exception(): void {
		$this->wpdb->get_var_return = null;
		$this->module->mock_transport->throw = new \RuntimeException( 'Odoo down' );

		$result = $this->module->push_to_odoo( 'product', 'create', 1 );

		// Should fail but still release the lock.
		$this->assertFalse( $result->succeeded() );
		$this->assertTrue( $this->has_prepare_containing( 'RELEASE_LOCK' ) );
	}

	public function test_returns_transient_on_lock_timeout(): void {
		// Make GET_LOCK return '0' (timeout).
		$this->wpdb->lock_return    = '0';
		$this->wpdb->get_var_return = null;

		$result = $this->module->push_to_odoo( 'product', 'create', 1 );

		$this->assertFalse( $result->succeeded() );
		$this->assertSame( Error_Type::Transient, $result->get_error_type() );
		$this->assertStringContainsString( 'lock', strtolower( $result->get_message() ) );
	}

	public function test_update_does_not_acquire_lock(): void {
		// Existing mapping: get_var returns odoo_id '42'.
		$this->wpdb->get_var_return = '42';

		$result = $this->module->push_to_odoo( 'product', 'update', 1, 42 );

		$this->assertTrue( $result->succeeded() );
		$this->assertFalse( $this->has_prepare_containing( 'GET_LOCK' ) );
	}

	public function test_lock_name_includes_module_entity_and_wp_id(): void {
		$this->wpdb->get_var_return = null;

		$this->module->push_to_odoo( 'product', 'create', 7 );

		$prepare_calls = $this->get_calls( 'prepare' );
		$lock_call     = null;
		foreach ( $prepare_calls as $call ) {
			if ( str_contains( $call['args'][0], 'GET_LOCK' ) ) {
				$lock_call = $call;
				break;
			}
		}

		$this->assertNotNull( $lock_call );
		// Lock name should be wp4odoo_push_ + md5(lock_test:product:7).
		$expected_name = 'wp4odoo_push_' . md5( 'lock_test:product:7' );
		$this->assertSame( $expected_name, $lock_call['args'][1] );
	}
}
