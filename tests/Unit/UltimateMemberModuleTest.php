<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Ultimate_Member_Module;
use WP4Odoo\Modules\Ultimate_Member_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Ultimate_Member_Module, Ultimate_Member_Handler,
 * and Ultimate_Member_Hooks.
 */
class UltimateMemberModuleTest extends TestCase {

	private Ultimate_Member_Module $module;
	private Ultimate_Member_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_users']     = [];
		$GLOBALS['_wp_user_meta'] = [];
		$GLOBALS['_um_roles']     = [
			'um_member'    => 'Member',
			'um_moderator' => 'Moderator',
			'um_admin'     => 'Admin',
		];

		$this->wpdb->insert_id = 1;

		$this->module  = new Ultimate_Member_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new Ultimate_Member_Handler( new Logger( 'ultimate_member', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [] );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id(): void {
		$this->assertSame( 'ultimate_member', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'Ultimate Member', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_profile_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'res.partner', $models['profile'] );
	}

	public function test_declares_role_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'res.partner.category', $models['role'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 2, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_profiles(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_profiles'] );
	}

	public function test_default_settings_has_pull_profiles(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_profiles'] );
	}

	public function test_default_settings_has_sync_roles(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_roles'] );
	}

	public function test_default_settings_has_exactly_three_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 3, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_profiles(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_profiles', $fields );
		$this->assertSame( 'checkbox', $fields['sync_profiles']['type'] );
	}

	public function test_settings_fields_exposes_pull_profiles(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_profiles', $fields );
		$this->assertSame( 'checkbox', $fields['pull_profiles']['type'] );
	}

	public function test_settings_fields_exposes_sync_roles(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_roles', $fields );
		$this->assertSame( 'checkbox', $fields['sync_roles']['type'] );
	}

	public function test_settings_fields_has_exactly_three_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 3, $fields );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_available_with_um(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Plugin Version ────────────────────────────────────

	public function test_plugin_version_returns_um_version(): void {
		$method = new \ReflectionMethod( $this->module, 'get_plugin_version' );
		$this->assertSame( UM_VERSION, $method->invoke( $this->module ) );
	}

	// ─── Boot ──────────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Field Mappings: Profile ──────────────────────────

	public function test_profile_map_to_odoo_includes_email(): void {
		$odoo = $this->module->map_to_odoo( 'profile', [
			'user_email'   => 'test@example.com',
			'first_name'   => 'Test',
			'last_name'    => 'User',
			'display_name' => 'Test User',
		] );
		$this->assertSame( 'test@example.com', $odoo['email'] );
	}

	public function test_profile_map_to_odoo_composes_name(): void {
		$odoo = $this->module->map_to_odoo( 'profile', [
			'first_name'   => 'John',
			'last_name'    => 'Doe',
			'display_name' => 'JD',
		] );
		$this->assertSame( 'John Doe', $odoo['name'] );
	}

	public function test_profile_map_to_odoo_includes_phone(): void {
		$odoo = $this->module->map_to_odoo( 'profile', [
			'first_name' => 'Test',
			'phone'      => '+1234567890',
		] );
		$this->assertSame( '+1234567890', $odoo['phone'] );
	}

	public function test_profile_map_to_odoo_includes_company(): void {
		$odoo = $this->module->map_to_odoo( 'profile', [
			'first_name' => 'Test',
			'company'    => 'Acme Inc',
		] );
		$this->assertSame( 'Acme Inc', $odoo['company_name'] );
	}

	// ─── Field Mappings: Role ──────────────────────────────

	public function test_role_map_to_odoo_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'role', [ 'name' => 'Member' ] );
		$this->assertSame( 'Member', $odoo['name'] );
	}

	// ─── Handler: load_profile ─────────────────────────────

	public function test_load_profile_returns_data_for_valid_user(): void {
		$user               = new \WP_User();
		$user->ID           = 1;
		$user->user_email   = 'john@example.com';
		$user->display_name = 'John Doe';
		$user->first_name   = 'John';
		$user->last_name    = 'Doe';
		$user->description  = 'A developer';
		$user->user_url     = 'https://johndoe.com';

		$GLOBALS['_wp_users'][1] = $user;

		$data = $this->handler->load_profile( 1 );

		$this->assertSame( 1, $data['user_id'] );
		$this->assertSame( 'john@example.com', $data['user_email'] );
		$this->assertSame( 'John', $data['first_name'] );
		$this->assertSame( 'Doe', $data['last_name'] );
	}

	public function test_load_profile_returns_empty_for_nonexistent_user(): void {
		$data = $this->handler->load_profile( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_profile_includes_um_meta(): void {
		$user               = new \WP_User();
		$user->ID           = 2;
		$user->user_email   = 'jane@example.com';
		$user->display_name = 'Jane';
		$user->first_name   = 'Jane';
		$user->last_name    = '';
		$user->description  = '';
		$user->user_url     = '';

		$GLOBALS['_wp_users'][2]     = $user;
		$GLOBALS['_wp_user_meta'][2] = [
			'phone_number' => '+33123456789',
			'company'      => 'Acme',
			'country'      => 'France',
			'city'         => 'Paris',
		];

		$data = $this->handler->load_profile( 2 );

		$this->assertSame( '+33123456789', $data['phone'] );
		$this->assertSame( 'Acme', $data['company'] );
		$this->assertSame( 'France', $data['country'] );
		$this->assertSame( 'Paris', $data['city'] );
	}

	// ─── Handler: load_role ────────────────────────────────

	public function test_load_role_returns_data_for_valid_role(): void {
		$slug    = 'um_member';
		$role_id = absint( crc32( $slug ) );

		$data = $this->handler->load_role( $role_id );

		$this->assertSame( 'um_member', $data['slug'] );
		$this->assertSame( 'Member', $data['name'] );
	}

	public function test_load_role_returns_empty_for_nonexistent_role(): void {
		$data = $this->handler->load_role( 999999 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: get_user_role ────────────────────────────

	public function test_get_user_role_returns_role_from_meta(): void {
		$GLOBALS['_wp_user_meta'][5] = [
			'role' => 'um_member',
		];

		$role = $this->handler->get_user_role( 5 );
		$this->assertSame( 'um_member', $role );
	}

	public function test_get_user_role_returns_empty_when_no_meta(): void {
		$role = $this->handler->get_user_role( 99 );
		$this->assertSame( '', $role );
	}

	// ─── Handler: save_profile ─────────────────────────────

	public function test_save_profile_updates_user(): void {
		$id = $this->handler->save_profile(
			[
				'first_name'  => 'Updated',
				'last_name'   => 'Name',
				'description' => 'New bio',
				'user_url'    => 'https://new.com',
			],
			42
		);

		$this->assertSame( 42, $id );
	}

	public function test_save_profile_returns_zero_for_invalid_user_id(): void {
		$id = $this->handler->save_profile( [ 'first_name' => 'Test' ], 0 );
		$this->assertSame( 0, $id );
	}

	// ─── Handler: format_partner ───────────────────────────

	public function test_format_partner_composes_name(): void {
		$values = $this->handler->format_partner( [
			'first_name' => 'Alice',
			'last_name'  => 'Wonder',
		] );
		$this->assertSame( 'Alice Wonder', $values['name'] );
	}

	public function test_format_partner_falls_back_to_display_name(): void {
		$values = $this->handler->format_partner( [
			'first_name'   => '',
			'last_name'    => '',
			'display_name' => 'Nickname',
		] );
		$this->assertSame( 'Nickname', $values['name'] );
	}

	public function test_format_partner_with_role_m2m(): void {
		$values = $this->handler->format_partner(
			[ 'first_name' => 'Test' ],
			[ 100 ]
		);
		$this->assertSame( [ [ 6, 0, [ 100 ] ] ], $values['category_id'] );
	}

	public function test_format_partner_without_roles(): void {
		$values = $this->handler->format_partner( [ 'first_name' => 'Test' ] );
		$this->assertArrayNotHasKey( 'category_id', $values );
	}

	public function test_format_partner_includes_company(): void {
		$values = $this->handler->format_partner( [
			'first_name' => 'Test',
			'company'    => 'Acme',
		] );
		$this->assertSame( 'Acme', $values['company_name'] );
	}

	public function test_format_partner_includes_city(): void {
		$values = $this->handler->format_partner( [
			'first_name' => 'Test',
			'city'       => 'Paris',
		] );
		$this->assertSame( 'Paris', $values['city'] );
	}

	// ─── Handler: format_category ──────────────────────────

	public function test_format_category_includes_name(): void {
		$values = $this->handler->format_category( [ 'name' => 'VIP' ] );
		$this->assertSame( 'VIP', $values['name'] );
	}

	// ─── Handler: parse_profile_from_odoo ──────────────────

	public function test_parse_profile_from_odoo_splits_name(): void {
		$data = $this->handler->parse_profile_from_odoo( [
			'name'    => 'Jane Smith',
			'email'   => 'jane@example.com',
			'phone'   => '+33123456789',
			'comment' => 'A bio',
			'website' => 'https://jane.com',
		] );

		$this->assertSame( 'Jane', $data['first_name'] );
		$this->assertSame( 'Smith', $data['last_name'] );
		$this->assertSame( 'jane@example.com', $data['user_email'] );
	}

	public function test_parse_profile_from_odoo_single_name(): void {
		$data = $this->handler->parse_profile_from_odoo( [
			'name'  => 'Madonna',
			'email' => 'madonna@example.com',
		] );

		$this->assertSame( 'Madonna', $data['first_name'] );
		$this->assertSame( '', $data['last_name'] );
	}

	// ─── Hooks: on_profile_updated ─────────────────────────

	public function test_on_profile_updated_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_profile_updated( 1 );

		$this->assertQueueContains( 'ultimate_member', 'profile', 'create', 1 );
	}

	public function test_on_profile_updated_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => false ];

		$this->module->on_profile_updated( 1 );

		$this->assertQueueEmpty();
	}

	public function test_on_profile_updated_skips_zero_user_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_profile_updated( 0 );

		$this->assertQueueEmpty();
	}

	public function test_on_profile_updated_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'ultimate_member' => true ] );

		$this->module->on_profile_updated( 1 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_registration_complete ───────────────────

	public function test_on_registration_complete_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_registration_complete( 10, [] );

		$this->assertQueueContains( 'ultimate_member', 'profile', 'create', 10 );
	}

	public function test_on_registration_complete_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_registration_complete( 0, [] );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_user_delete ─────────────────────────────

	public function test_on_user_delete_enqueues_delete(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => true ];

		$this->module->save_mapping( 'profile', 7, 700 );

		$this->module->on_user_delete( 7 );

		$this->assertQueueContains( 'ultimate_member', 'profile', 'delete', 7 );
	}

	public function test_on_user_delete_skips_when_no_mapping(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_user_delete( 999 );

		$this->assertQueueEmpty();
	}

	public function test_on_user_delete_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_profiles' => false ];

		$this->module->save_mapping( 'profile', 7, 700 );
		$this->wpdb->calls = [];

		$this->module->on_user_delete( 7 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_role_changed ────────────────────────────

	public function test_on_role_changed_enqueues_role_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_roles' => true ];

		$this->module->on_role_changed( 5, 'um_moderator' );

		$role_wp_id = absint( crc32( 'um_moderator' ) );
		$this->assertQueueContains( 'ultimate_member', 'role', 'create', $role_wp_id );
	}

	public function test_on_role_changed_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_roles' => false ];

		$this->module->on_role_changed( 5, 'um_moderator' );

		$this->assertQueueEmpty();
	}

	public function test_on_role_changed_skips_zero_user_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'sync_roles' => true ];

		$this->module->on_role_changed( 0, 'um_moderator' );

		$this->assertQueueEmpty();
	}

	// ─── Pull: role skipped ────────────────────────────────

	public function test_pull_role_skipped(): void {
		$result = $this->module->pull_from_odoo( 'role', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: profile ─────────────────────────────────────

	public function test_pull_profile_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_ultimate_member_settings'] = [ 'pull_profiles' => false ];

		$result = $this->module->pull_from_odoo( 'profile', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Dedup Domains ─────────────────────────────────────

	public function test_dedup_profile_by_email(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$domain = $method->invoke( $this->module, 'profile', [ 'email' => 'test@example.com' ] );
		$this->assertSame( [ [ 'email', '=', 'test@example.com' ] ], $domain );
	}

	public function test_dedup_role_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$domain = $method->invoke( $this->module, 'role', [ 'name' => 'Member' ] );
		$this->assertSame( [ [ 'name', '=', 'Member' ] ], $domain );
	}

	public function test_dedup_empty_when_no_key(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$domain = $method->invoke( $this->module, 'profile', [] );
		$this->assertSame( [], $domain );
	}

	// ─── map_from_odoo ─────────────────────────────────────

	public function test_map_from_odoo_profile(): void {
		$odoo_data = [
			'name'    => 'John Doe',
			'email'   => 'john@example.com',
			'phone'   => '+1234567890',
			'comment' => 'A developer',
			'website' => 'https://john.com',
		];

		$wp_data = $this->module->map_from_odoo( 'profile', $odoo_data );

		$this->assertSame( 'John', $wp_data['first_name'] );
		$this->assertSame( 'Doe', $wp_data['last_name'] );
		$this->assertSame( 'john@example.com', $wp_data['user_email'] );
	}

	// ─── Helpers ───────────────────────────────────────────

	private function assertQueueContains( string $module, string $entity, string $action, int $wp_id ): void {
		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => 'insert' === $c['method'] );
		foreach ( $inserts as $call ) {
			$data = $call['args'][1] ?? [];
			if ( ( $data['module'] ?? '' ) === $module
				&& ( $data['entity_type'] ?? '' ) === $entity
				&& ( $data['action'] ?? '' ) === $action
				&& ( $data['wp_id'] ?? 0 ) === $wp_id ) {
				$this->assertTrue( true );
				return;
			}
		}
		$this->fail( "Queue does not contain [{$module}, {$entity}, {$action}, {$wp_id}]" );
	}

	private function assertQueueEmpty(): void {
		$inserts = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'insert' === $c['method'] && str_contains( $c['args'][0] ?? '', 'sync_queue' )
		);
		$this->assertEmpty( $inserts, 'Queue should be empty.' );
	}
}
