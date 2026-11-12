<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Sync_Queue_Repository;
use WP4Odoo\Settings_Repository;
use WP4Odoo\API\Odoo_Auth;
use WP4Odoo\API\Odoo_Client;

/**
 * Unit tests for WordPress multisite support.
 *
 * Tests blog_id scoping in repositories, company_id injection
 * in the Odoo client, and multisite credential resolution.
 *
 */
#[CoversClass(\WP4Odoo\Entity_Map_Repository::class)]
#[CoversClass(\WP4Odoo\Sync_Queue_Repository::class)]
#[CoversClass(\WP4Odoo\Settings_Repository::class)]
#[CoversClass(\WP4Odoo\API\Odoo_Auth::class)]
#[CoversClass(\WP4Odoo\API\Odoo_Client::class)]
class MultisiteTest extends TestCase {

	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;

		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_wp_site_options']  = [];
		$GLOBALS['_wp_current_blog_id'] = 1;
		$GLOBALS['_wp_is_multisite'] = false;
		$GLOBALS['_wp_switched_stack'] = [];
		$GLOBALS['_wp_transients']    = [];
		$GLOBALS['_wp_actions']       = [];

		Odoo_Auth::flush_credentials_cache();
	}

	protected function tearDown(): void {
		Odoo_Auth::flush_credentials_cache();
	}

	// ═══════════════════════════════════════════════════════
	// Entity_Map_Repository — blog_id scoping
	// ═══════════════════════════════════════════════════════

	public function test_entity_map_defaults_to_current_blog_id(): void {
		$GLOBALS['_wp_current_blog_id'] = 3;

		$repo = new Entity_Map_Repository();
		$ref  = new \ReflectionProperty( $repo, 'blog_id' );

		$this->assertSame( 3, $ref->getValue( $repo ) );
	}

	public function test_entity_map_accepts_explicit_blog_id(): void {
		$repo = new Entity_Map_Repository( 7 );
		$ref  = new \ReflectionProperty( $repo, 'blog_id' );

		$this->assertSame( 7, $ref->getValue( $repo ) );
	}

	public function test_entity_map_get_odoo_id_includes_blog_id(): void {
		$this->wpdb->get_var_return = '42';

		$repo   = new Entity_Map_Repository( 5 );
		$result = $repo->get_odoo_id( 'crm', 'contact', 10 );

		$this->assertSame( 42, $result );

		// Verify the SQL includes blog_id.
		$prepare_calls = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'prepare' === $c['method']
		);
		$last_prepare  = end( $prepare_calls );
		$this->assertStringContainsString( 'blog_id', $last_prepare['args'][0] );
	}

	public function test_entity_map_get_wp_id_includes_blog_id(): void {
		$this->wpdb->get_var_return = '10';

		$repo   = new Entity_Map_Repository( 5 );
		$result = $repo->get_wp_id( 'crm', 'contact', 42 );

		$this->assertSame( 10, $result );

		$prepare_calls = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'prepare' === $c['method']
		);
		$last_prepare  = end( $prepare_calls );
		$this->assertStringContainsString( 'blog_id', $last_prepare['args'][0] );
	}

	public function test_entity_map_save_includes_blog_id(): void {
		$repo = new Entity_Map_Repository( 3 );
		$repo->save( 'crm', 'contact', 10, 42, 'res.partner' );

		// save() uses $wpdb->query( $wpdb->prepare(...) ), so check prepare calls.
		$prepare_calls = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'prepare' === $c['method'] && str_contains( $c['args'][0], 'INSERT' )
		);
		$this->assertNotEmpty( $prepare_calls );
		$last_prepare = end( $prepare_calls );
		$this->assertStringContainsString( 'blog_id', $last_prepare['args'][0] );
	}

	public function test_entity_map_remove_includes_blog_id(): void {
		$repo = new Entity_Map_Repository( 3 );
		$repo->remove( 'crm', 'contact', 10 );

		$prepare_calls = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'prepare' === $c['method']
		);
		$last_prepare  = end( $prepare_calls );
		$this->assertStringContainsString( 'blog_id', $last_prepare['args'][0] );
	}

	// ═══════════════════════════════════════════════════════
	// Sync_Queue_Repository — blog_id scoping
	// ═══════════════════════════════════════════════════════

	public function test_sync_queue_defaults_to_current_blog_id(): void {
		$GLOBALS['_wp_current_blog_id'] = 4;

		$repo = new Sync_Queue_Repository();
		$ref  = new \ReflectionProperty( $repo, 'blog_id' );

		$this->assertSame( 4, $ref->getValue( $repo ) );
	}

	public function test_sync_queue_accepts_explicit_blog_id(): void {
		$repo = new Sync_Queue_Repository( 8 );
		$ref  = new \ReflectionProperty( $repo, 'blog_id' );

		$this->assertSame( 8, $ref->getValue( $repo ) );
	}

	// ═══════════════════════════════════════════════════════
	// Settings_Repository — company_id + multisite
	// ═══════════════════════════════════════════════════════

	public function test_connection_defaults_include_company_id(): void {
		$defaults = Settings_Repository::connection_defaults();

		$this->assertArrayHasKey( 'company_id', $defaults );
		$this->assertSame( 0, $defaults['company_id'] );
	}

	public function test_get_connection_returns_company_id(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url'        => 'https://odoo.example.com',
			'database'   => 'testdb',
			'username'   => 'admin',
			'api_key'    => '',
			'protocol'   => 'jsonrpc',
			'timeout'    => 30,
			'company_id' => 5,
		];

		$settings   = new Settings_Repository();
		$connection = $settings->get_connection();

		$this->assertSame( 5, $connection['company_id'] );
	}

	public function test_get_connection_clamps_negative_company_id(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'company_id' => -3,
		];

		$settings   = new Settings_Repository();
		$connection = $settings->get_connection();

		$this->assertSame( 0, $connection['company_id'] );
	}

	public function test_effective_connection_returns_local_in_single_site(): void {
		$GLOBALS['_wp_is_multisite'] = false;
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url'      => 'https://local.odoo.com',
			'database' => 'local',
		];

		$settings = new Settings_Repository();
		$result   = $settings->get_effective_connection();

		$this->assertSame( 'https://local.odoo.com', $result['url'] );
	}

	public function test_effective_connection_falls_back_to_network(): void {
		$GLOBALS['_wp_is_multisite'] = true;
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url' => '', // No local connection.
		];
		$GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_CONNECTION ] = [
			'url'      => 'https://network.odoo.com',
			'database' => 'networkdb',
			'username' => 'admin',
			'api_key'  => '',
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];
		$GLOBALS['_wp_current_blog_id'] = 2;
		$GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_SITE_COMPANIES ] = [
			'2' => 10,
		];

		$settings = new Settings_Repository();
		$result   = $settings->get_effective_connection();

		$this->assertSame( 'https://network.odoo.com', $result['url'] );
		$this->assertSame( 10, $result['company_id'] );
	}

	public function test_effective_connection_prefers_local_when_configured(): void {
		$GLOBALS['_wp_is_multisite'] = true;
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url'        => 'https://local.odoo.com',
			'database'   => 'localdb',
			'company_id' => 3,
		];
		$GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_CONNECTION ] = [
			'url' => 'https://network.odoo.com',
		];

		$settings = new Settings_Repository();
		$result   = $settings->get_effective_connection();

		$this->assertSame( 'https://local.odoo.com', $result['url'] );
		$this->assertSame( 3, $result['company_id'] );
	}

	public function test_is_using_network_connection_false_in_single_site(): void {
		$GLOBALS['_wp_is_multisite'] = false;

		$settings = new Settings_Repository();
		$this->assertFalse( $settings->is_using_network_connection() );
	}

	public function test_is_using_network_connection_true_when_local_empty(): void {
		$GLOBALS['_wp_is_multisite'] = true;
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [ 'url' => '' ];
		$GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_CONNECTION ] = [
			'url' => 'https://network.odoo.com',
		];

		$settings = new Settings_Repository();
		$this->assertTrue( $settings->is_using_network_connection() );
	}

	public function test_get_site_company_id_from_network_mapping(): void {
		$GLOBALS['_wp_is_multisite']    = true;
		$GLOBALS['_wp_current_blog_id'] = 5;
		$GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_SITE_COMPANIES ] = [
			'5' => 42,
		];

		$settings = new Settings_Repository();
		$this->assertSame( 42, $settings->get_site_company_id() );
	}

	public function test_get_site_company_id_returns_zero_when_not_mapped(): void {
		$GLOBALS['_wp_is_multisite']    = true;
		$GLOBALS['_wp_current_blog_id'] = 99;

		$settings = new Settings_Repository();
		$this->assertSame( 0, $settings->get_site_company_id() );
	}

	public function test_save_network_connection(): void {
		$GLOBALS['_wp_is_multisite'] = true;

		$settings = new Settings_Repository();
		$settings->save_network_connection( [ 'url' => 'https://shared.odoo.com' ] );

		$stored = $GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_CONNECTION ];
		$this->assertSame( 'https://shared.odoo.com', $stored['url'] );
	}

	public function test_save_network_site_companies(): void {
		$settings = new Settings_Repository();
		$settings->save_network_site_companies( [ 1 => 10, 2 => 20 ] );

		$stored = $GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_SITE_COMPANIES ];
		$this->assertSame( [ 1 => 10, 2 => 20 ], $stored );
	}

	// ═══════════════════════════════════════════════════════
	// Odoo_Auth — credentials with company_id
	// ═══════════════════════════════════════════════════════

	public function test_get_credentials_includes_company_id(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url'        => 'https://odoo.test.com',
			'database'   => 'testdb',
			'username'   => 'admin',
			'api_key'    => '',
			'protocol'   => 'jsonrpc',
			'timeout'    => 30,
			'company_id' => 7,
		];

		$credentials = Odoo_Auth::get_credentials();

		$this->assertSame( 7, $credentials['company_id'] );
	}

	public function test_get_credentials_defaults_company_id_to_zero(): void {
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url'      => 'https://odoo.test.com',
			'database' => 'testdb',
			'username' => 'admin',
			'api_key'  => '',
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];

		$credentials = Odoo_Auth::get_credentials();

		$this->assertSame( 0, $credentials['company_id'] );
	}

	public function test_get_credentials_multisite_network_fallback(): void {
		$GLOBALS['_wp_is_multisite'] = true;
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url' => '', // No local connection.
		];
		$GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_CONNECTION ] = [
			'url'      => 'https://network.odoo.com',
			'database' => 'networkdb',
			'username' => 'admin',
			'api_key'  => '',
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];
		$GLOBALS['_wp_current_blog_id'] = 3;
		$GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_SITE_COMPANIES ] = [
			'3' => 15,
		];

		$credentials = Odoo_Auth::get_credentials();

		$this->assertSame( 'https://network.odoo.com', $credentials['url'] );
		$this->assertSame( 15, $credentials['company_id'] );
	}

	public function test_get_credentials_no_fallback_when_local_configured(): void {
		$GLOBALS['_wp_is_multisite'] = true;
		$GLOBALS['_wp_options'][ Settings_Repository::OPT_CONNECTION ] = [
			'url'        => 'https://local.odoo.com',
			'database'   => 'localdb',
			'company_id' => 5,
		];
		$GLOBALS['_wp_site_options'][ Settings_Repository::OPT_NETWORK_CONNECTION ] = [
			'url' => 'https://network.odoo.com',
		];

		$credentials = Odoo_Auth::get_credentials();

		$this->assertSame( 'https://local.odoo.com', $credentials['url'] );
		$this->assertSame( 5, $credentials['company_id'] );
	}

	// ═══════════════════════════════════════════════════════
	// Odoo_Client — company_id context injection
	// ═══════════════════════════════════════════════════════

	/**
	 * Create an Odoo_Client with MockTransport injected via reflection.
	 *
	 * @param MockTransport $transport The mock transport.
	 * @return Odoo_Client
	 */
	private function create_client_with_transport( MockTransport $transport ): Odoo_Client {
		$client = new Odoo_Client();
		$ref    = new \ReflectionClass( $client );

		$tp = $ref->getProperty( 'transport' );
		$tp->setValue( $client, $transport );

		$cp = $ref->getProperty( 'connected' );
		$cp->setValue( $client, true );

		return $client;
	}

	public function test_client_injects_company_id_into_context(): void {
		$transport               = new MockTransport();
		$transport->return_value = [ 1, 2, 3 ];

		$client = $this->create_client_with_transport( $transport );

		// Set company_id via reflection.
		$ref = new \ReflectionProperty( $client, 'company_id' );
		$ref->setValue( $client, 10 );

		$client->search( 'res.partner', [] );

		$last_call = end( $transport->calls );
		$this->assertNotEmpty( $transport->calls );
		$this->assertArrayHasKey( 'context', $last_call['kwargs'] );
		$this->assertSame( [ 10 ], $last_call['kwargs']['context']['allowed_company_ids'] );
	}

	public function test_client_skips_company_id_when_zero(): void {
		$transport               = new MockTransport();
		$transport->return_value = [ 1, 2, 3 ];

		$client = $this->create_client_with_transport( $transport );

		$client->search( 'res.partner', [] );

		$last_call = end( $transport->calls );
		$this->assertNotEmpty( $transport->calls );

		// No context should be injected for company_id = 0.
		if ( isset( $last_call['kwargs']['context'] ) ) {
			$this->assertArrayNotHasKey( 'allowed_company_ids', $last_call['kwargs']['context'] );
		} else {
			$this->assertArrayNotHasKey( 'context', $last_call['kwargs'] );
		}
	}

	public function test_client_does_not_overwrite_existing_allowed_company_ids(): void {
		$transport               = new MockTransport();
		$transport->return_value = [ 1 ];

		$client = $this->create_client_with_transport( $transport );

		$ref = new \ReflectionProperty( $client, 'company_id' );
		$ref->setValue( $client, 10 );

		// Pass explicit context with allowed_company_ids.
		$client->execute(
			'res.partner',
			'search',
			[ [] ],
			[ 'context' => [ 'allowed_company_ids' => [ 5, 6 ] ] ]
		);

		$last_call = end( $transport->calls );
		// Should preserve the caller's allowed_company_ids, not overwrite.
		$this->assertSame( [ 5, 6 ], $last_call['kwargs']['context']['allowed_company_ids'] );
	}

	// ═══════════════════════════════════════════════════════
	// switch_to_blog / restore_current_blog stubs
	// ═══════════════════════════════════════════════════════

	public function test_switch_to_blog_updates_current_blog_id(): void {
		$GLOBALS['_wp_current_blog_id'] = 1;

		switch_to_blog( 5 );
		$this->assertSame( 5, get_current_blog_id() );

		restore_current_blog();
		$this->assertSame( 1, get_current_blog_id() );
	}

	public function test_nested_switch_to_blog(): void {
		$GLOBALS['_wp_current_blog_id'] = 1;

		switch_to_blog( 2 );
		switch_to_blog( 3 );
		$this->assertSame( 3, get_current_blog_id() );

		restore_current_blog();
		$this->assertSame( 2, get_current_blog_id() );

		restore_current_blog();
		$this->assertSame( 1, get_current_blog_id() );
	}
}
