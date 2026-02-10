<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\API\Transport;
use PHPUnit\Framework\TestCase;

/**
 * Mock Transport implementation for testing Odoo_Client.
 *
 * Records all execute_kw calls and returns configurable values or exceptions.
 */
class MockTransport implements Transport {

	/**
	 * Configurable return value for execute_kw.
	 *
	 * @var mixed
	 */
	public mixed $return_value = null;

	/**
	 * Optional exception to throw from execute_kw.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $throw = null;

	/**
	 * Recorded execute_kw calls.
	 *
	 * @var array<int, array{model: string, method: string, args: array, kwargs: array}>
	 */
	public array $calls = [];

	/**
	 * Authenticate against Odoo.
	 *
	 * @param string $username The Odoo login.
	 * @return int Always returns 1 (stub).
	 */
	public function authenticate( string $username ): int {
		return 1;
	}

	/**
	 * Execute a method on an Odoo model.
	 *
	 * Records the call, optionally throws, or returns configured value.
	 *
	 * @param string               $model  Odoo model name.
	 * @param string               $method Method name.
	 * @param array<int, mixed>    $args   Positional arguments.
	 * @param array<string, mixed> $kwargs Keyword arguments.
	 * @return mixed
	 * @throws \Throwable If $throw is set.
	 */
	public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
		$this->calls[] = [
			'model'  => $model,
			'method' => $method,
			'args'   => $args,
			'kwargs' => $kwargs,
		];

		if ( $this->throw ) {
			throw $this->throw;
		}

		return $this->return_value;
	}

	/**
	 * Get the authenticated user ID.
	 *
	 * @return int|null Always returns 1 (stub).
	 */
	public function get_uid(): ?int {
		return 1;
	}
}

/**
 * Test suite for Odoo_Client.
 *
 * Uses reflection to inject a mock transport, bypassing ensure_connected()
 * so we can test CRUD methods without real Odoo credentials.
 *
 * @package WP4Odoo\Tests\Unit
 */
class OdooClientTest extends TestCase {

	/**
	 * Stub wpdb instance.
	 *
	 * @var \WP_DB_Stub
	 */
	private \WP_DB_Stub $wpdb;

	/**
	 * Odoo_Client instance under test.
	 *
	 * @var Odoo_Client
	 */
	private Odoo_Client $client;

	/**
	 * Mock transport injected into $client.
	 *
	 * @var MockTransport
	 */
	private MockTransport $transport;

	/**
	 * Set up test environment before each test.
	 *
	 * Initializes wpdb stub, creates Odoo_Client, and injects mock transport
	 * via reflection to bypass authentication.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;
		$this->wpdb            = new \WP_DB_Stub();
		$wpdb                  = $this->wpdb;
		$GLOBALS['_wp_options'] = [];

		// Enable logging so Logger doesn't short-circuit.
		$GLOBALS['_wp_options']['wp4odoo_log_settings'] = [
			'enabled' => true,
			'level'   => 'debug',
		];

		$this->client    = new Odoo_Client();
		$this->transport = new MockTransport();

		// Inject mock transport via reflection.
		$ref = new \ReflectionClass( $this->client );

		$tp = $ref->getProperty( 'transport' );
		$tp->setAccessible( true );
		$tp->setValue( $this->client, $this->transport );

		$cp = $ref->getProperty( 'connected' );
		$cp->setAccessible( true );
		$cp->setValue( $this->client, true );
	}

	/**
	 * Test is_connected returns true after mock injection.
	 *
	 * @return void
	 */
	public function test_is_connected_returns_true_after_injection(): void {
		$this->assertTrue( $this->client->is_connected() );
	}

	/**
	 * Test search delegates to transport correctly.
	 *
	 * @return void
	 */
	public function test_search_delegates_to_transport(): void {
		$this->transport->return_value = [ 1, 2, 3 ];

		$result = $this->client->search( 'res.partner', [ [ 'active', '=', true ] ] );

		$this->assertSame( [ 1, 2, 3 ], $result );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'res.partner', $this->transport->calls[0]['model'] );
		$this->assertSame( 'search', $this->transport->calls[0]['method'] );
		$this->assertSame( [ [ [ 'active', '=', true ] ] ], $this->transport->calls[0]['args'] );
		$this->assertSame( [], $this->transport->calls[0]['kwargs'] );
	}

	/**
	 * Test search passes offset, limit, and order as kwargs.
	 *
	 * @return void
	 */
	public function test_search_passes_offset_limit_order_as_kwargs(): void {
		$this->transport->return_value = [ 5, 6 ];

		$result = $this->client->search( 'res.partner', [], 10, 20, 'name asc' );

		$this->assertSame( [ 5, 6 ], $result );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame(
			[
				'offset' => 10,
				'limit'  => 20,
				'order'  => 'name asc',
			],
			$this->transport->calls[0]['kwargs']
		);
	}

	/**
	 * Test search returns empty array when transport returns non-array.
	 *
	 * @return void
	 */
	public function test_search_returns_empty_array_for_non_array_result(): void {
		$this->transport->return_value = null;

		$result = $this->client->search( 'res.partner', [] );

		$this->assertSame( [], $result );
	}

	/**
	 * Test search_read includes fields in kwargs.
	 *
	 * @return void
	 */
	public function test_search_read_includes_fields_in_kwargs(): void {
		$this->transport->return_value = [
			[ 'id' => 1, 'name' => 'Alice' ],
			[ 'id' => 2, 'name' => 'Bob' ],
		];

		$result = $this->client->search_read(
			'res.partner',
			[ [ 'active', '=', true ] ],
			[ 'name', 'email' ],
			0,
			10,
			'name asc'
		);

		$this->assertCount( 2, $result );
		$this->assertSame( 'Alice', $result[0]['name'] );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'search_read', $this->transport->calls[0]['method'] );
		$this->assertSame(
			[
				'fields' => [ 'name', 'email' ],
				'limit'  => 10,
				'order'  => 'name asc',
			],
			$this->transport->calls[0]['kwargs']
		);
	}

	/**
	 * Test read delegates correctly.
	 *
	 * @return void
	 */
	public function test_read_delegates_correctly(): void {
		$this->transport->return_value = [
			[ 'id' => 1, 'name' => 'Alice' ],
		];

		$result = $this->client->read( 'res.partner', [ 1 ], [ 'name', 'email' ] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'Alice', $result[0]['name'] );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'res.partner', $this->transport->calls[0]['model'] );
		$this->assertSame( 'read', $this->transport->calls[0]['method'] );
		$this->assertSame( [ [ 1 ] ], $this->transport->calls[0]['args'] );
		$this->assertSame( [ 'fields' => [ 'name', 'email' ] ], $this->transport->calls[0]['kwargs'] );
	}

	/**
	 * Test create returns int id.
	 *
	 * @return void
	 */
	public function test_create_returns_int_id(): void {
		$this->transport->return_value = 42;

		$result = $this->client->create( 'res.partner', [ 'name' => 'Charlie' ] );

		$this->assertSame( 42, $result );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'create', $this->transport->calls[0]['method'] );
		$this->assertSame( [ [ 'name' => 'Charlie' ] ], $this->transport->calls[0]['args'] );
	}

	/**
	 * Test write returns bool.
	 *
	 * @return void
	 */
	public function test_write_returns_bool(): void {
		$this->transport->return_value = true;

		$result = $this->client->write( 'res.partner', [ 1, 2 ], [ 'active' => false ] );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'write', $this->transport->calls[0]['method'] );
		$this->assertSame( [ [ 1, 2 ], [ 'active' => false ] ], $this->transport->calls[0]['args'] );
	}

	/**
	 * Test unlink delegates correctly.
	 *
	 * @return void
	 */
	public function test_unlink_delegates_correctly(): void {
		$this->transport->return_value = true;

		$result = $this->client->unlink( 'res.partner', [ 1 ] );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'unlink', $this->transport->calls[0]['method'] );
		$this->assertSame( [ [ 1 ] ], $this->transport->calls[0]['args'] );
	}

	/**
	 * Test search_count returns int.
	 *
	 * @return void
	 */
	public function test_search_count_returns_int(): void {
		$this->transport->return_value = 15;

		$result = $this->client->search_count( 'res.partner', [ [ 'active', '=', true ] ] );

		$this->assertSame( 15, $result );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'search_count', $this->transport->calls[0]['method'] );
		$this->assertSame( [ [ [ 'active', '=', true ] ] ], $this->transport->calls[0]['args'] );
	}

	/**
	 * Test fields_get returns array.
	 *
	 * @return void
	 */
	public function test_fields_get_returns_array(): void {
		$this->transport->return_value = [
			'name' => [ 'type' => 'char', 'string' => 'Name' ],
			'email' => [ 'type' => 'char', 'string' => 'Email' ],
		];

		$result = $this->client->fields_get( 'res.partner', [ 'string', 'type' ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertSame( 'char', $result['name']['type'] );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'fields_get', $this->transport->calls[0]['method'] );
		$this->assertSame( [ 'attributes' => [ 'string', 'type' ] ], $this->transport->calls[0]['kwargs'] );
	}

	/**
	 * Test execute passes arbitrary method.
	 *
	 * @return void
	 */
	public function test_execute_passes_arbitrary_method(): void {
		$this->transport->return_value = [ 'success' => true ];

		$result = $this->client->execute( 'sale.order', 'action_confirm', [ [ 1 ] ], [ 'context' => [] ] );

		$this->assertSame( [ 'success' => true ], $result );
		$this->assertCount( 1, $this->transport->calls );
		$this->assertSame( 'action_confirm', $this->transport->calls[0]['method'] );
		$this->assertSame( [ [ 1 ] ], $this->transport->calls[0]['args'] );
		$this->assertSame( [ 'context' => [] ], $this->transport->calls[0]['kwargs'] );
	}

	/**
	 * Test call re-throws transport exceptions.
	 *
	 * @return void
	 */
	public function test_call_rethrows_transport_exceptions(): void {
		$this->transport->throw = new \RuntimeException( 'Transport error' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Transport error' );

		$this->client->search( 'res.partner', [] );
	}

	/**
	 * Test ensure_connected throws when no URL configured.
	 *
	 * Creates a NEW Odoo_Client without mock injection so ensure_connected()
	 * attempts to read credentials and fails.
	 *
	 * @return void
	 */
	public function test_ensure_connected_throws_when_no_url_configured(): void {
		// Create a fresh client without mock injection.
		$fresh_client = new Odoo_Client();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'not configured' );

		// Trigger ensure_connected() by calling any CRUD method.
		$fresh_client->search( 'res.partner', [] );
	}
}
