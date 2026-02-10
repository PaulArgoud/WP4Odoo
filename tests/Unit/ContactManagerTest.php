<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Contact_Manager;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Contact_Manager class.
 *
 * @package WP4Odoo\Tests\Unit
 */
class ContactManagerTest extends TestCase {

	private Contact_Manager $manager;

	/**
	 * Default settings for the Contact_Manager.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']   = [
			'wp4odoo_log_settings' => [ 'enabled' => true, 'level' => 'debug' ],
		];
		$GLOBALS['_wp_users']     = [];
		$GLOBALS['_wp_user_meta'] = [];

		$this->settings = [
			'sync_users_as_contacts' => true,
			'create_users_on_pull'   => true,
			'default_user_role'      => 'subscriber',
			'sync_role'              => '',
		];

		$this->manager = new Contact_Manager(
			new Logger( 'test' ),
			fn() => $this->settings
		);
	}

	/**
	 * Clean up globals after each test.
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['_wp_users'],
			$GLOBALS['_wp_user_meta'],
			$GLOBALS['_wp_options']
		);
	}

	// ─── load_contact_data Tests ────────────────────────────

	/**
	 * Test that load returns empty array when user does not exist.
	 */
	public function test_load_returns_empty_when_user_not_found(): void {
		$result = $this->manager->load_contact_data( 999 );

		$this->assertSame( [], $result );
	}

	/**
	 * Test that load returns user data fields from WP_User object.
	 */
	public function test_load_returns_user_data(): void {
		$user                = new \WP_User( 1 );
		$user->display_name  = 'John Doe';
		$user->user_email    = 'john@example.com';
		$user->first_name    = 'John';
		$user->last_name     = 'Doe';
		$user->description   = 'A test user.';
		$user->user_url      = 'https://example.com';

		$GLOBALS['_wp_users'][1] = $user;

		$result = $this->manager->load_contact_data( 1 );

		$this->assertSame( 'John Doe', $result['display_name'] );
		$this->assertSame( 'john@example.com', $result['user_email'] );
		$this->assertSame( 'John', $result['first_name'] );
		$this->assertSame( 'Doe', $result['last_name'] );
		$this->assertSame( 'A test user.', $result['description'] );
		$this->assertSame( 'https://example.com', $result['user_url'] );
	}

	/**
	 * Test that load reads billing meta fields from user meta.
	 */
	public function test_load_reads_billing_meta(): void {
		$user             = new \WP_User( 2 );
		$user->user_email = 'meta@example.com';

		$GLOBALS['_wp_users'][2] = $user;
		$GLOBALS['_wp_user_meta'][2] = [
			'billing_phone'     => '+33123456789',
			'billing_company'   => 'Acme Corp',
			'billing_address_1' => '10 Rue de Rivoli',
			'billing_address_2' => 'Apt 5',
			'billing_city'      => 'Paris',
			'billing_postcode'  => '75001',
			'billing_country'   => 'FR',
			'billing_state'     => 'Île-de-France',
		];

		$result = $this->manager->load_contact_data( 2 );

		$this->assertSame( '+33123456789', $result['billing_phone'] );
		$this->assertSame( 'Acme Corp', $result['billing_company'] );
		$this->assertSame( '10 Rue de Rivoli', $result['billing_address_1'] );
		$this->assertSame( 'Apt 5', $result['billing_address_2'] );
		$this->assertSame( 'Paris', $result['billing_city'] );
		$this->assertSame( '75001', $result['billing_postcode'] );
		$this->assertSame( 'FR', $result['billing_country'] );
		$this->assertSame( 'Île-de-France', $result['billing_state'] );
	}

	// ─── save_contact_data Tests ────────────────────────────

	/**
	 * Test that save returns 0 for invalid (empty) email.
	 */
	public function test_save_returns_zero_for_invalid_email(): void {
		$result = $this->manager->save_contact_data( [ 'user_email' => '' ], 0 );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test that save updates an existing user when wp_id > 0.
	 */
	public function test_save_updates_existing_user(): void {
		$user                = new \WP_User( 5 );
		$user->user_email    = 'existing@example.com';
		$GLOBALS['_wp_users'][5] = $user;

		$data = [
			'user_email'   => 'existing@example.com',
			'display_name' => 'Updated Name',
			'first_name'   => 'Updated',
			'last_name'    => 'Name',
			'description'  => 'Updated bio.',
			'user_url'     => 'https://updated.example.com',
		];

		$result = $this->manager->save_contact_data( $data, 5 );

		$this->assertSame( 5, $result );
	}

	/**
	 * Test that save creates a new user when creation is enabled and wp_id = 0.
	 */
	public function test_save_creates_new_user_when_enabled(): void {
		$this->settings['create_users_on_pull'] = true;

		$data = [
			'user_email'   => 'newuser@example.com',
			'display_name' => 'New User',
			'first_name'   => 'New',
			'last_name'    => 'User',
			'description'  => '',
			'user_url'     => '',
		];

		$result = $this->manager->save_contact_data( $data, 0 );

		// wp_insert_user returns incrementing IDs starting from 100.
		$this->assertGreaterThan( 0, $result );
	}

	/**
	 * Test that save returns 0 when user creation on pull is disabled.
	 */
	public function test_save_returns_zero_when_creation_disabled(): void {
		$this->settings['create_users_on_pull'] = false;

		$data = [
			'user_email'   => 'newuser@example.com',
			'display_name' => 'New User',
		];

		$result = $this->manager->save_contact_data( $data, 0 );

		$this->assertSame( 0, $result );
	}

	/**
	 * Test that save deduplicates by email: existing user is updated instead of created.
	 */
	public function test_save_deduplicates_by_email(): void {
		$user             = new \WP_User( 42 );
		$user->user_email = 'duplicate@example.com';
		$user->user_login = 'duplicate';

		$GLOBALS['_wp_users'][42] = $user;

		$data = [
			'user_email'   => 'duplicate@example.com',
			'display_name' => 'Dedup User',
			'first_name'   => 'Dedup',
			'last_name'    => 'User',
			'description'  => '',
			'user_url'     => '',
		];

		// wp_id = 0 triggers dedup lookup; should find user 42 by email.
		$result = $this->manager->save_contact_data( $data, 0 );

		$this->assertSame( 42, $result );
	}

	// ─── should_sync_user Tests ─────────────────────────────

	/**
	 * Test that should_sync returns false when sync is disabled.
	 */
	public function test_should_sync_returns_false_when_disabled(): void {
		$this->settings['sync_users_as_contacts'] = false;

		$result = $this->manager->should_sync_user( 1 );

		$this->assertFalse( $result );
	}

	/**
	 * Test that should_sync returns true for any user when sync_role is empty.
	 */
	public function test_should_sync_returns_true_for_all_roles(): void {
		$this->settings['sync_role'] = '';

		$user        = new \WP_User( 10 );
		$user->roles = [ 'editor' ];

		$GLOBALS['_wp_users'][10] = $user;

		$result = $this->manager->should_sync_user( 10 );

		$this->assertTrue( $result );
	}

	/**
	 * Test that should_sync filters by role — matching role.
	 */
	public function test_should_sync_filters_by_role_match(): void {
		$this->settings['sync_role'] = 'subscriber';

		$user        = new \WP_User( 20 );
		$user->roles = [ 'subscriber' ];

		$GLOBALS['_wp_users'][20] = $user;

		$result = $this->manager->should_sync_user( 20 );

		$this->assertTrue( $result );
	}

	/**
	 * Test that should_sync filters by role — non-matching role.
	 */
	public function test_should_sync_filters_by_role_no_match(): void {
		$this->settings['sync_role'] = 'subscriber';

		$user        = new \WP_User( 30 );
		$user->roles = [ 'administrator' ];

		$GLOBALS['_wp_users'][30] = $user;

		$result = $this->manager->should_sync_user( 30 );

		$this->assertFalse( $result );
	}
}
