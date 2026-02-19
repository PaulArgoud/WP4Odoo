<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\BuddyBoss_Module;
use WP4Odoo\Modules\BuddyBoss_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BuddyBoss_Module, BuddyBoss_Handler, and BuddyBoss_Hooks.
 *
 * Tests module configuration, handler data loading/saving, format/parse
 * methods, and hook guard logic.
 */
class BuddyBossModuleTest extends TestCase {

	private BuddyBoss_Module $module;
	private BuddyBoss_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']     = [];
		$GLOBALS['_wp_users']       = [];
		$GLOBALS['_wp_user_meta']   = [];
		$GLOBALS['_bp_xprofile']    = [];
		$GLOBALS['_bp_groups']      = [];
		$GLOBALS['_bp_user_groups'] = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new BuddyBoss_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new BuddyBoss_Handler( new Logger( 'buddyboss', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		// Clean up importing flag.
		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [] );

		unset(
			$GLOBALS['_bp_xprofile'],
			$GLOBALS['_bp_groups'],
			$GLOBALS['_bp_user_groups']
		);
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_buddyboss(): void {
		$this->assertSame( 'buddyboss', $this->module->get_id() );
	}

	public function test_module_name_is_buddyboss(): void {
		$this->assertSame( 'BuddyBoss', $this->module->get_name() );
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

	public function test_declares_group_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'res.partner.category', $models['group'] );
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

	public function test_default_settings_has_sync_groups(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_groups'] );
	}

	public function test_default_settings_has_sync_group_members(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_group_members'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
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

	public function test_settings_fields_exposes_sync_groups(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_groups', $fields );
		$this->assertSame( 'checkbox', $fields['sync_groups']['type'] );
	}

	public function test_settings_fields_exposes_sync_group_members(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_group_members', $fields );
		$this->assertSame( 'checkbox', $fields['sync_group_members']['type'] );
	}

	public function test_settings_fields_has_exactly_four_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 4, $fields );
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

	public function test_profile_map_to_odoo_includes_comment(): void {
		$odoo = $this->module->map_to_odoo( 'profile', [
			'first_name'  => 'Test',
			'description' => 'A bio text',
		] );
		$this->assertSame( 'A bio text', $odoo['comment'] );
	}

	public function test_profile_map_to_odoo_includes_website(): void {
		$odoo = $this->module->map_to_odoo( 'profile', [
			'first_name' => 'Test',
			'user_url'   => 'https://example.com',
		] );
		$this->assertSame( 'https://example.com', $odoo['website'] );
	}

	// ─── Field Mappings: Group ─────────────────────────────

	public function test_group_map_to_odoo_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'group', [ 'name' => 'Developers' ] );
		$this->assertSame( 'Developers', $odoo['name'] );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_available_with_bp_version(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_no_warning_within_tested_range(): void {
		// BP_VERSION is 2.6.0, TESTED_UP_TO is 2.7 — within range.
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
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
		$this->assertSame( 'A developer', $data['description'] );
		$this->assertSame( 'https://johndoe.com', $data['user_url'] );
	}

	public function test_load_profile_returns_empty_for_nonexistent_user(): void {
		$data = $this->handler->load_profile( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_profile_includes_xprofile_data(): void {
		$user               = new \WP_User();
		$user->ID           = 2;
		$user->user_email   = 'jane@example.com';
		$user->display_name = 'Jane';
		$user->first_name   = 'Jane';
		$user->last_name    = '';
		$user->description  = '';
		$user->user_url     = '';

		$GLOBALS['_wp_users'][2]          = $user;
		$GLOBALS['_bp_xprofile'][2]       = [
			'Phone'    => '+33123456789',
			'Location' => 'Paris',
		];

		$data = $this->handler->load_profile( 2 );

		$this->assertSame( '+33123456789', $data['phone'] );
		$this->assertSame( 'Paris', $data['location'] );
	}

	// ─── Handler: load_group ───────────────────────────────

	public function test_load_group_returns_data_for_valid_group(): void {
		$group              = new \stdClass();
		$group->id          = 10;
		$group->name        = 'Developers';
		$group->description = 'Developer community';
		$group->status      = 'public';

		$GLOBALS['_bp_groups'][10] = $group;

		$data = $this->handler->load_group( 10 );

		$this->assertSame( 10, $data['id'] );
		$this->assertSame( 'Developers', $data['name'] );
		$this->assertSame( 'Developer community', $data['description'] );
		$this->assertSame( 'public', $data['status'] );
	}

	public function test_load_group_returns_empty_for_nonexistent_group(): void {
		$data = $this->handler->load_group( 999 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: get_user_group_ids ───────────────────────

	public function test_get_user_group_ids_returns_group_ids(): void {
		$GLOBALS['_bp_user_groups'][5] = [
			'groups' => [ 10, 20, 30 ],
			'total'  => 3,
		];

		$ids = $this->handler->get_user_group_ids( 5 );
		$this->assertSame( [ 10, 20, 30 ], $ids );
	}

	public function test_get_user_group_ids_returns_empty_for_no_groups(): void {
		$ids = $this->handler->get_user_group_ids( 99 );
		$this->assertSame( [], $ids );
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

	public function test_save_profile_sets_xprofile_fields(): void {
		$GLOBALS['_bp_xprofile'] = [];

		$this->handler->save_profile(
			[
				'first_name' => 'Test',
				'last_name'  => 'User',
				'phone'      => '+1234567890',
				'location'   => 'New York',
			],
			10
		);

		$this->assertSame( '+1234567890', $GLOBALS['_bp_xprofile'][10]['Phone'] );
		$this->assertSame( 'New York', $GLOBALS['_bp_xprofile'][10]['Location'] );
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

	public function test_format_partner_first_name_only(): void {
		$values = $this->handler->format_partner( [
			'first_name' => 'Alice',
			'last_name'  => '',
		] );

		$this->assertSame( 'Alice', $values['name'] );
	}

	public function test_format_partner_falls_back_to_display_name(): void {
		$values = $this->handler->format_partner( [
			'first_name'   => '',
			'last_name'    => '',
			'display_name' => 'Nickname',
		] );

		$this->assertSame( 'Nickname', $values['name'] );
	}

	public function test_format_partner_includes_email(): void {
		$values = $this->handler->format_partner( [
			'first_name' => 'Test',
			'user_email' => 'test@example.com',
		] );

		$this->assertSame( 'test@example.com', $values['email'] );
	}

	public function test_format_partner_includes_phone(): void {
		$values = $this->handler->format_partner( [
			'first_name' => 'Test',
			'phone'      => '+33123456789',
		] );

		$this->assertSame( '+33123456789', $values['phone'] );
	}

	public function test_format_partner_with_group_m2m(): void {
		$values = $this->handler->format_partner(
			[ 'first_name' => 'Test' ],
			[ 100, 200 ]
		);

		$this->assertSame( [ [ 6, 0, [ 100, 200 ] ] ], $values['category_id'] );
	}

	public function test_format_partner_without_groups(): void {
		$values = $this->handler->format_partner( [ 'first_name' => 'Test' ] );

		$this->assertArrayNotHasKey( 'category_id', $values );
	}

	// ─── Handler: format_category ──────────────────────────

	public function test_format_category_includes_name(): void {
		$values = $this->handler->format_category( [ 'name' => 'VIP' ] );
		$this->assertSame( 'VIP', $values['name'] );
	}

	public function test_format_category_empty_name(): void {
		$values = $this->handler->format_category( [] );
		$this->assertSame( '', $values['name'] );
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
		$this->assertSame( '+33123456789', $data['phone'] );
		$this->assertSame( 'A bio', $data['description'] );
		$this->assertSame( 'https://jane.com', $data['user_url'] );
	}

	public function test_parse_profile_from_odoo_single_name(): void {
		$data = $this->handler->parse_profile_from_odoo( [
			'name'  => 'Madonna',
			'email' => 'madonna@example.com',
		] );

		$this->assertSame( 'Madonna', $data['first_name'] );
		$this->assertSame( '', $data['last_name'] );
	}

	public function test_parse_profile_from_odoo_empty_fields(): void {
		$data = $this->handler->parse_profile_from_odoo( [] );

		$this->assertSame( '', $data['first_name'] );
		$this->assertSame( '', $data['user_email'] );
		$this->assertSame( '', $data['phone'] );
		$this->assertSame( '', $data['description'] );
		$this->assertSame( '', $data['user_url'] );
	}

	// ─── Hooks: on_profile_updated ─────────────────────────

	public function test_on_profile_updated_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_profile_updated( 1, [ 1, 2 ], false );

		$this->assertQueueContains( 'buddyboss', 'profile', 'create', 1 );
	}

	public function test_on_profile_updated_enqueues_update_when_mapped(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => true ];

		// Create a mapping first.
		$this->module->save_mapping( 'profile', 5, 500 );

		$this->module->on_profile_updated( 5, [ 1 ], false );

		$this->assertQueueContains( 'buddyboss', 'profile', 'update', 5 );
	}

	public function test_on_profile_updated_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => false ];

		$this->module->on_profile_updated( 1, [ 1 ], false );

		$this->assertQueueEmpty();
	}

	public function test_on_profile_updated_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'buddyboss' => true ] );

		$this->module->on_profile_updated( 1, [ 1 ], false );

		$this->assertQueueEmpty();

		$prop->setValue( null, [] );
	}

	public function test_on_profile_updated_skips_zero_user_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_profile_updated( 0, [ 1 ], false );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_user_activated ──────────────────────────

	public function test_on_user_activated_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_user_activated( 10 );

		$this->assertQueueContains( 'buddyboss', 'profile', 'create', 10 );
	}

	public function test_on_user_activated_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => false ];

		$this->module->on_user_activated( 10 );

		$this->assertQueueEmpty();
	}

	public function test_on_user_activated_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_user_activated( 0 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_user_delete ─────────────────────────────

	public function test_on_user_delete_enqueues_delete(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => true ];

		// Create a mapping first.
		$this->module->save_mapping( 'profile', 7, 700 );

		$this->module->on_user_delete( 7 );

		$this->assertQueueContains( 'buddyboss', 'profile', 'delete', 7 );
	}

	public function test_on_user_delete_skips_when_no_mapping(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => true ];

		$this->module->on_user_delete( 999 );

		$this->assertQueueEmpty();
	}

	public function test_on_user_delete_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_profiles' => false ];

		$this->module->save_mapping( 'profile', 7, 700 );
		$this->wpdb->calls = []; // Reset after save_mapping insert.

		$this->module->on_user_delete( 7 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_group_saved ─────────────────────────────

	public function test_on_group_saved_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_groups' => true ];

		$group     = new \stdClass();
		$group->id = 15;

		$this->module->on_group_saved( $group );

		$this->assertQueueContains( 'buddyboss', 'group', 'create', 15 );
	}

	public function test_on_group_saved_enqueues_update_when_mapped(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_groups' => true ];

		$this->module->save_mapping( 'group', 15, 1500 );

		$group     = new \stdClass();
		$group->id = 15;

		$this->module->on_group_saved( $group );

		$this->assertQueueContains( 'buddyboss', 'group', 'update', 15 );
	}

	public function test_on_group_saved_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_groups' => false ];

		$group     = new \stdClass();
		$group->id = 15;

		$this->module->on_group_saved( $group );

		$this->assertQueueEmpty();
	}

	public function test_on_group_saved_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'sync_groups' => true ];

		$group     = new \stdClass();
		$group->id = 0;

		$this->module->on_group_saved( $group );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_group_member_changed ────────────────────

	public function test_on_group_member_changed_re_pushes_profile(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [
			'sync_group_members' => true,
			'sync_profiles'      => true,
		];

		$this->module->save_mapping( 'profile', 3, 300 );

		$this->module->on_group_member_changed( 10, 3 );

		$this->assertQueueContains( 'buddyboss', 'profile', 'update', 3 );
	}

	public function test_on_group_member_changed_skips_when_group_members_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [
			'sync_group_members' => false,
			'sync_profiles'      => true,
		];

		$this->module->save_mapping( 'profile', 3, 300 );
		$this->wpdb->calls = [];

		$this->module->on_group_member_changed( 10, 3 );

		$this->assertQueueEmpty();
	}

	public function test_on_group_member_changed_skips_when_profiles_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [
			'sync_group_members' => true,
			'sync_profiles'      => false,
		];

		$this->module->save_mapping( 'profile', 3, 300 );
		$this->wpdb->calls = [];

		$this->module->on_group_member_changed( 10, 3 );

		$this->assertQueueEmpty();
	}

	public function test_on_group_member_changed_skips_zero_user_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [
			'sync_group_members' => true,
			'sync_profiles'      => true,
		];

		$this->module->on_group_member_changed( 10, 0 );

		$this->assertQueueEmpty();
	}

	public function test_on_group_member_changed_skips_when_no_mapping(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [
			'sync_group_members' => true,
			'sync_profiles'      => true,
		];

		$this->module->on_group_member_changed( 10, 3 );

		$this->assertQueueEmpty();
	}

	// ─── Pull: group skipped ───────────────────────────────

	public function test_pull_group_skipped(): void {
		$result = $this->module->pull_from_odoo( 'group', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: profile ─────────────────────────────────────

	public function test_pull_profile_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [ 'pull_profiles' => false ];

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

	public function test_dedup_group_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'group', [ 'name' => 'Developers' ] );

		$this->assertSame( [ [ 'name', '=', 'Developers' ] ], $domain );
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
		$this->assertSame( '+1234567890', $wp_data['phone'] );
		$this->assertSame( 'A developer', $wp_data['description'] );
		$this->assertSame( 'https://john.com', $wp_data['user_url'] );
	}

	// ─── map_to_odoo with group M2M ───────────────────────

	public function test_map_to_odoo_profile_with_group_members(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [
			'sync_group_members' => true,
		];

		// Set up group mappings.
		$this->module->save_mapping( 'group', 10, 100 );
		$this->module->save_mapping( 'group', 20, 200 );

		// Set up user group memberships.
		$GLOBALS['_bp_user_groups'][5] = [
			'groups' => [ 10, 20 ],
			'total'  => 2,
		];

		$odoo = $this->module->map_to_odoo( 'profile', [
			'_wp_entity_id' => 5,
			'user_id'       => 5,
			'first_name'    => 'Test',
			'last_name'     => 'User',
			'user_email'    => 'test@example.com',
		] );

		$this->assertArrayHasKey( 'category_id', $odoo );
		$this->assertSame( [ [ 6, 0, [ 100, 200 ] ] ], $odoo['category_id'] );
	}

	public function test_map_to_odoo_profile_without_group_members(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_buddyboss_settings'] = [
			'sync_group_members' => false,
		];

		$odoo = $this->module->map_to_odoo( 'profile', [
			'_wp_entity_id' => 5,
			'first_name'    => 'Test',
			'last_name'     => 'User',
		] );

		$this->assertArrayNotHasKey( 'category_id', $odoo );
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
