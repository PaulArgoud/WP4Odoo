<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Contact_Refiner;
use WP4Odoo\API\Odoo_Client;
use PHPUnit\Framework\TestCase;

/**
 * Mock Odoo_Client for testing Contact_Refiner.
 *
 * Records method calls and returns configurable values for search() and read().
 */
class MockOdooClient extends Odoo_Client {

	/** @var array<string, array> */
	public array $search_returns = [];

	/** @var array<string, array> */
	public array $read_returns = [];

	/** @var array<int, array{method: string, model: string, domain?: array, ids?: array, fields?: array}> */
	public array $calls = [];

	/**
	 * Skip parent constructor to avoid Logger creation.
	 */
	public function __construct() {
		// Do not call parent - we don't need Logger for mock.
	}

	/**
	 * Mock search method.
	 *
	 * @param string $model  The Odoo model.
	 * @param array  $domain Search domain.
	 * @param int    $offset Offset.
	 * @param int    $limit  Limit.
	 * @param string $order  Order.
	 * @return array Array of IDs.
	 */
	public function search( string $model, array $domain = [], int $offset = 0, int $limit = 0, string $order = '' ): array {
		$this->calls[] = [
			'method' => 'search',
			'model'  => $model,
			'domain' => $domain,
		];

		$key = $model . ':search';
		return $this->search_returns[ $key ] ?? [];
	}

	/**
	 * Mock read method.
	 *
	 * @param string $model  The Odoo model.
	 * @param array  $ids    Record IDs.
	 * @param array  $fields Fields to read.
	 * @return array Array of records.
	 */
	public function read( string $model, array $ids, array $fields = [] ): array {
		$this->calls[] = [
			'method' => 'read',
			'model'  => $model,
			'ids'    => $ids,
			'fields' => $fields,
		];

		$key = $model . ':read';
		return $this->read_returns[ $key ] ?? [];
	}
}

/**
 * Tests for Contact_Refiner class.
 *
 * @package WP4Odoo\Tests\Unit
 */
class ContactRefinerTest extends TestCase {

	private MockOdooClient $mock_client;
	private Contact_Refiner $refiner;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		$GLOBALS['_wp_options'] = [];

		$this->mock_client = new MockOdooClient();
		$this->refiner     = new Contact_Refiner( fn() => $this->mock_client );
	}

	// ─── refine_to_odoo Tests ───────────────────────────────

	/**
	 * Test composing name from first_name + last_name.
	 */
	public function test_refine_to_odoo_composes_full_name(): void {
		$odoo_values = [];
		$wp_data     = [
			'first_name' => 'John',
			'last_name'  => 'Doe',
		];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertSame( 'John Doe', $result['name'] );
	}

	/**
	 * Test composing name with only first_name.
	 */
	public function test_refine_to_odoo_composes_name_first_only(): void {
		$odoo_values = [];
		$wp_data     = [
			'first_name' => 'Alice',
			'last_name'  => '',
		];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertSame( 'Alice', $result['name'] );
	}

	/**
	 * Test composing name with only last_name.
	 */
	public function test_refine_to_odoo_composes_name_last_only(): void {
		$odoo_values = [];
		$wp_data     = [
			'first_name' => '',
			'last_name'  => 'Smith',
		];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertSame( 'Smith', $result['name'] );
	}

	/**
	 * Test removal of x_wp_first_name and x_wp_last_name from output.
	 */
	public function test_refine_to_odoo_removes_wp_first_last_fields(): void {
		$odoo_values = [
			'email'             => 'test@example.com',
			'x_wp_first_name'   => 'John',
			'x_wp_last_name'    => 'Doe',
		];
		$wp_data     = [];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertArrayNotHasKey( 'x_wp_first_name', $result );
		$this->assertArrayNotHasKey( 'x_wp_last_name', $result );
		$this->assertSame( 'test@example.com', $result['email'] );
	}

	/**
	 * Test resolving country code to Odoo ID.
	 */
	public function test_refine_to_odoo_resolves_country_code(): void {
		$this->mock_client->search_returns['res.country:search'] = [ 10 ];

		$odoo_values = [
			'country_id' => 'fr',
		];
		$wp_data     = [];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertSame( 10, $result['country_id'] );
		$this->assertCount( 1, $this->mock_client->calls );
		$this->assertSame( 'search', $this->mock_client->calls[0]['method'] );
		$this->assertSame( 'res.country', $this->mock_client->calls[0]['model'] );
		$this->assertSame( [ [ 'code', '=', 'FR' ] ], $this->mock_client->calls[0]['domain'] );
	}

	/**
	 * Test resolving state name within country.
	 */
	public function test_refine_to_odoo_resolves_state_within_country(): void {
		$this->mock_client->search_returns['res.country:search']       = [ 10 ];
		$this->mock_client->search_returns['res.country.state:search'] = [ 5 ];

		$odoo_values = [
			'country_id' => 'fr',
			'state_id'   => 'Île-de-France',
		];
		$wp_data     = [];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertSame( 10, $result['country_id'] );
		$this->assertSame( 5, $result['state_id'] );
		$this->assertCount( 2, $this->mock_client->calls );

		// Verify country search.
		$this->assertSame( 'search', $this->mock_client->calls[0]['method'] );
		$this->assertSame( 'res.country', $this->mock_client->calls[0]['model'] );

		// Verify state search.
		$this->assertSame( 'search', $this->mock_client->calls[1]['method'] );
		$this->assertSame( 'res.country.state', $this->mock_client->calls[1]['model'] );
		$this->assertSame(
			[ [ 'country_id', '=', 10 ], [ 'name', 'ilike', 'Île-de-France' ] ],
			$this->mock_client->calls[1]['domain']
		);
	}

	/**
	 * Test setting country_id=false when country not found.
	 */
	public function test_refine_to_odoo_sets_false_when_country_not_found(): void {
		$this->mock_client->search_returns['res.country:search'] = [];

		$odoo_values = [
			'country_id' => 'XX',
			'state_id'   => 'SomeState',
		];
		$wp_data     = [];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertFalse( $result['country_id'] );
		$this->assertFalse( $result['state_id'] );
	}

	/**
	 * Test removing country_id/state_id when country_id is not a string.
	 */
	public function test_refine_to_odoo_removes_country_when_not_string(): void {
		$odoo_values = [
			'email'      => 'test@example.com',
			'country_id' => 10,
			'state_id'   => 5,
		];
		$wp_data     = [];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertArrayNotHasKey( 'country_id', $result );
		$this->assertArrayNotHasKey( 'state_id', $result );
		$this->assertSame( 'test@example.com', $result['email'] );
	}

	/**
	 * Test stripping empty string values from result.
	 */
	public function test_refine_to_odoo_strips_empty_strings(): void {
		$odoo_values = [
			'email' => 'test@example.com',
			'phone' => '',
			'city'  => 'Paris',
		];
		$wp_data     = [];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertArrayNotHasKey( 'phone', $result );
		$this->assertSame( 'test@example.com', $result['email'] );
		$this->assertSame( 'Paris', $result['city'] );
	}

	/**
	 * Test stripping null values from result.
	 */
	public function test_refine_to_odoo_strips_null_values(): void {
		$odoo_values = [
			'email' => 'test@example.com',
			'phone' => null,
			'city'  => 'Paris',
		];
		$wp_data     = [];

		$result = $this->refiner->refine_to_odoo( $odoo_values, $wp_data, 'res.partner' );

		$this->assertArrayNotHasKey( 'phone', $result );
		$this->assertSame( 'test@example.com', $result['email'] );
		$this->assertSame( 'Paris', $result['city'] );
	}

	// ─── refine_from_odoo Tests ─────────────────────────────

	/**
	 * Test splitting display_name into first_name + last_name.
	 */
	public function test_refine_from_odoo_splits_display_name(): void {
		$wp_data   = [
			'display_name' => 'Jane Doe',
		];
		$odoo_data = [];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		$this->assertSame( 'Jane', $result['first_name'] );
		$this->assertSame( 'Doe', $result['last_name'] );
	}

	/**
	 * Test splitting single-word name (first_name only, empty last_name).
	 */
	public function test_refine_from_odoo_splits_single_word_name(): void {
		$wp_data   = [
			'display_name' => 'Alice',
		];
		$odoo_data = [];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		$this->assertSame( 'Alice', $result['first_name'] );
		$this->assertSame( '', $result['last_name'] );
	}

	/**
	 * Test resolving country_id Many2one [10, 'France'] -> billing_country 'FR'.
	 */
	public function test_refine_from_odoo_resolves_country_many2one(): void {
		$this->mock_client->read_returns['res.country:read'] = [
			[ 'code' => 'FR' ],
		];

		$wp_data   = [
			'display_name' => 'Test User',
		];
		$odoo_data = [
			'country_id' => [ 10, 'France' ],
		];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		$this->assertSame( 'FR', $result['billing_country'] );
		$this->assertCount( 1, $this->mock_client->calls );
		$this->assertSame( 'read', $this->mock_client->calls[0]['method'] );
		$this->assertSame( 'res.country', $this->mock_client->calls[0]['model'] );
		$this->assertSame( [ 10 ], $this->mock_client->calls[0]['ids'] );
		$this->assertSame( [ 'code' ], $this->mock_client->calls[0]['fields'] );
	}

	/**
	 * Test resolving state_id Many2one [5, 'Île-de-France'] -> billing_state 'Île-de-France'.
	 *
	 * State name is extracted directly from the Many2one tuple (no API call needed).
	 */
	public function test_refine_from_odoo_resolves_state_many2one(): void {
		$wp_data   = [
			'display_name' => 'Test User',
		];
		$odoo_data = [
			'state_id' => [ 5, 'Île-de-France' ],
		];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		$this->assertSame( 'Île-de-France', $result['billing_state'] );
		// No API call needed — name extracted from Many2one tuple.
		$this->assertCount( 0, $this->mock_client->calls );
	}

	/**
	 * Test returning empty billing_country when country_id is false.
	 */
	public function test_refine_from_odoo_returns_empty_country_when_false(): void {
		$wp_data   = [
			'display_name' => 'Test User',
		];
		$odoo_data = [
			'country_id' => false,
		];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		$this->assertSame( '', $result['billing_country'] );
		$this->assertEmpty( $this->mock_client->calls );
	}

	/**
	 * Test removal of x_wp_first_name and x_wp_last_name from output.
	 */
	public function test_refine_from_odoo_removes_wp_first_last_fields(): void {
		$wp_data   = [
			'display_name'      => 'Bob Smith',
			'email'             => 'bob@example.com',
			'x_wp_first_name'   => 'Bob',
			'x_wp_last_name'    => 'Smith',
		];
		$odoo_data = [];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		$this->assertArrayNotHasKey( 'x_wp_first_name', $result );
		$this->assertArrayNotHasKey( 'x_wp_last_name', $result );
		$this->assertSame( 'bob@example.com', $result['email'] );
	}

	/**
	 * Test that existing first_name is not overwritten by display_name splitting.
	 */
	public function test_refine_from_odoo_does_not_overwrite_existing_first_name(): void {
		$wp_data   = [
			'display_name' => 'Jane Doe',
			'first_name'   => 'Janet',
		];
		$odoo_data = [];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		// Should keep the existing first_name.
		$this->assertSame( 'Janet', $result['first_name'] );
		$this->assertArrayNotHasKey( 'last_name', $result );
	}

	/**
	 * Test resolving country with integer Many2one value (just ID).
	 */
	public function test_refine_from_odoo_resolves_country_integer_many2one(): void {
		$this->mock_client->read_returns['res.country:read'] = [
			[ 'code' => 'US' ],
		];

		$wp_data   = [
			'display_name' => 'Test User',
		];
		$odoo_data = [
			'country_id' => 20,
		];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		$this->assertSame( 'US', $result['billing_country'] );
		$this->assertCount( 1, $this->mock_client->calls );
		$this->assertSame( [ 20 ], $this->mock_client->calls[0]['ids'] );
	}

	/**
	 * Test handling empty read result for country resolution.
	 */
	public function test_refine_from_odoo_handles_empty_country_read(): void {
		$this->mock_client->read_returns['res.country:read'] = [];

		$wp_data   = [
			'display_name' => 'Test User',
		];
		$odoo_data = [
			'country_id' => [ 10, 'France' ],
		];

		$result = $this->refiner->refine_from_odoo( $wp_data, $odoo_data, 'res.partner' );

		$this->assertSame( '', $result['billing_country'] );
	}
}
