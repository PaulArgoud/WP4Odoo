<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Partner_Service;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Partner_Service.
 *
 * Verifies partner lookup, search, and creation logic
 * using WP_DB_Stub and an Odoo_Client mock.
 */
class PartnerServiceTest extends TestCase {

	private \WP_DB_Stub $wpdb;
	private Entity_Map_Repository $repo;

	/** @var object Mock Odoo client. */
	private object $client;

	private Partner_Service $service;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$this->repo = new Entity_Map_Repository();
		$this->repo->flush_cache();

		// Create a simple mock Odoo client.
		$this->client = new class {
			/** @var array Return value for search(). */
			public array $search_return = [];

			/** @var int Return value for create(). */
			public int $create_return = 0;

			/** @var bool Return value for is_connected(). */
			public bool $connected = true;

			/** @var array<int, array{method: string, args: array}> Recorded calls. */
			public array $calls = [];

			public function is_connected(): bool {
				return $this->connected;
			}

			public function search( string $model, array $domain = [], int $offset = 0, int $limit = 0 ): array {
				$this->calls[] = [ 'method' => 'search', 'args' => [ $model, $domain, $offset, $limit ] ];
				return $this->search_return;
			}

			public function create( string $model, array $values ): int {
				$this->calls[] = [ 'method' => 'create', 'args' => [ $model, $values ] ];
				return $this->create_return;
			}
		};

		$client        = $this->client;
		$this->service = new Partner_Service( fn() => $client, $this->repo );
	}

	// ─── get_partner_id_for_user() ─────────────────────────

	public function test_get_partner_id_for_user_returns_odoo_id_when_mapped(): void {
		$this->wpdb->get_var_return = '42';

		$result = $this->service->get_partner_id_for_user( 10 );

		$this->assertSame( 42, $result );
	}

	public function test_get_partner_id_for_user_returns_null_when_not_mapped(): void {
		$this->wpdb->get_var_return = null;

		$result = $this->service->get_partner_id_for_user( 99 );

		$this->assertNull( $result );
	}

	// ─── get_user_for_partner() ────────────────────────────

	public function test_get_user_for_partner_returns_wp_id_when_mapped(): void {
		$this->wpdb->get_var_return = '5';

		$result = $this->service->get_user_for_partner( 100 );

		$this->assertSame( 5, $result );
	}

	public function test_get_user_for_partner_returns_null_when_not_mapped(): void {
		$this->wpdb->get_var_return = null;

		$result = $this->service->get_user_for_partner( 999 );

		$this->assertNull( $result );
	}

	// ─── get_or_create() ───────────────────────────────────

	public function test_get_or_create_returns_mapped_id_without_calling_odoo(): void {
		// Existing mapping for wp_id=10.
		$this->wpdb->get_var_return = '42';

		$result = $this->service->get_or_create( 'john@example.com', [], 10 );

		$this->assertSame( 42, $result );
		$this->assertEmpty( $this->client->calls, 'No Odoo calls should be made when mapping exists.' );
	}

	public function test_get_or_create_searches_odoo_when_no_mapping(): void {
		$this->wpdb->get_var_return = null;
		$this->client->search_return = [ 55 ];

		$result = $this->service->get_or_create( 'jane@example.com', [], 10 );

		$this->assertSame( 55, $result );
		$this->assertSame( 'search', $this->client->calls[0]['method'] );
		$this->assertSame( 'res.partner', $this->client->calls[0]['args'][0] );
	}

	public function test_get_or_create_saves_mapping_when_found_in_odoo(): void {
		$this->wpdb->get_var_return = null;
		$this->client->search_return = [ 77 ];

		$this->service->get_or_create( 'found@example.com', [], 15 );

		// Verify that an upsert (save) was called on entity_map.
		$upsert_calls = array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'query' && str_contains( $c['args'][0], 'INSERT INTO' ) );
		$this->assertNotEmpty( $upsert_calls );
	}

	public function test_get_or_create_creates_partner_when_not_found(): void {
		$this->wpdb->get_var_return = null;
		$this->client->search_return = [];
		$this->client->create_return = 88;

		$result = $this->service->get_or_create( 'new@example.com', [ 'name' => 'New User' ], 20 );

		$this->assertSame( 88, $result );
		$this->assertSame( 'create', $this->client->calls[1]['method'] );
		$this->assertSame( 'res.partner', $this->client->calls[1]['args'][0] );
		$this->assertSame( 'New User', $this->client->calls[1]['args'][1]['name'] );
		$this->assertSame( 'new@example.com', $this->client->calls[1]['args'][1]['email'] );
	}

	public function test_get_or_create_uses_email_as_name_when_no_name_given(): void {
		$this->wpdb->get_var_return = null;
		$this->client->search_return = [];
		$this->client->create_return = 99;

		$this->service->get_or_create( 'noname@example.com' );

		$create_data = $this->client->calls[1]['args'][1];
		$this->assertSame( 'noname@example.com', $create_data['name'] );
	}

	public function test_get_or_create_without_wp_id_does_not_save_mapping(): void {
		$this->wpdb->get_var_return = null;
		$this->client->search_return = [ 33 ];

		$result = $this->service->get_or_create( 'no-wp@example.com' );

		$this->assertSame( 33, $result );

		// No replace call expected (wp_id = 0).
		$replace_calls = array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'replace' );
		$this->assertEmpty( $replace_calls );
	}
}
