<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\GamiPress_Module;
use WP4Odoo\Modules\GamiPress_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GamiPress_Module, GamiPress_Handler, and GamiPress_Hooks.
 *
 * Tests module configuration, handler data loading/saving/formatting,
 * hook guard logic, pull overrides, and dedup domains.
 */
class GamiPressModuleTest extends TestCase {

	private GamiPress_Module $module;
	private GamiPress_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']        = [];
		$GLOBALS['_wp_transients']     = [];
		$GLOBALS['_wp_users']          = [];
		$GLOBALS['_wp_user_meta']      = [];
		$GLOBALS['_wp_posts']          = [];
		$GLOBALS['_wp_post_meta']      = [];
		$GLOBALS['_gamipress_points']  = [];

		$this->module  = new GamiPress_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new GamiPress_Handler( new Logger( 'gamipress', wp4odoo_test_settings() ) );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_gamipress(): void {
		$this->assertSame( 'gamipress', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'GamiPress', $this->module->get_name() );
	}

	public function test_exclusive_group_is_gamification(): void {
		$this->assertSame( 'gamification', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ─────────────────────────────────────

	public function test_declares_points_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'loyalty.card', $models['points'] );
	}

	public function test_declares_achievement_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.template', $models['achievement'] );
	}

	public function test_declares_rank_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.template', $models['rank'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ─────────────────────────────────

	public function test_default_settings_has_sync_points(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_points'] );
	}

	public function test_default_settings_has_pull_points(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_points'] );
	}

	public function test_default_settings_has_sync_achievements(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_achievements'] );
	}

	public function test_default_settings_has_sync_ranks(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_ranks'] );
	}

	public function test_default_settings_has_odoo_program_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_program_id'] );
	}

	public function test_default_settings_has_exactly_five_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 5, $settings );
	}

	// ─── Settings Fields ──────────────────────────────────

	public function test_settings_fields_exposes_sync_points(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_points', $fields );
		$this->assertSame( 'checkbox', $fields['sync_points']['type'] );
	}

	public function test_settings_fields_exposes_pull_points(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_points', $fields );
		$this->assertSame( 'checkbox', $fields['pull_points']['type'] );
	}

	public function test_settings_fields_exposes_sync_achievements(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_achievements', $fields );
		$this->assertSame( 'checkbox', $fields['sync_achievements']['type'] );
	}

	public function test_settings_fields_exposes_sync_ranks(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_ranks', $fields );
		$this->assertSame( 'checkbox', $fields['sync_ranks']['type'] );
	}

	public function test_settings_fields_exposes_odoo_program_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'odoo_program_id', $fields );
		$this->assertSame( 'number', $fields['odoo_program_id']['type'] );
	}

	public function test_settings_fields_has_exactly_five_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 5, $fields );
	}

	// ─── Field Mappings: Achievement ──────────────────────

	public function test_achievement_mapping_produces_name(): void {
		$odoo = $this->module->map_to_odoo( 'achievement', [ 'title' => 'First Login', 'description' => 'Log in for the first time' ] );
		$this->assertSame( 'First Login', $odoo['name'] );
	}

	public function test_achievement_mapping_produces_service_type(): void {
		$odoo = $this->module->map_to_odoo( 'achievement', [ 'title' => 'Test', 'description' => '' ] );
		$this->assertSame( 'service', $odoo['type'] );
	}

	public function test_achievement_mapping_produces_sale_ok_false(): void {
		$odoo = $this->module->map_to_odoo( 'achievement', [ 'title' => 'Test', 'description' => '' ] );
		$this->assertFalse( $odoo['sale_ok'] );
	}

	public function test_achievement_mapping_produces_purchase_ok_false(): void {
		$odoo = $this->module->map_to_odoo( 'achievement', [ 'title' => 'Test', 'description' => '' ] );
		$this->assertFalse( $odoo['purchase_ok'] );
	}

	// ─── Field Mappings: Rank ─────────────────────────────

	public function test_rank_mapping_produces_name(): void {
		$odoo = $this->module->map_to_odoo( 'rank', [ 'title' => 'Gold', 'description' => 'Gold rank', 'priority' => 10 ] );
		$this->assertSame( 'Gold', $odoo['name'] );
	}

	public function test_rank_mapping_produces_service_type(): void {
		$odoo = $this->module->map_to_odoo( 'rank', [ 'title' => 'Test', 'description' => '', 'priority' => 0 ] );
		$this->assertSame( 'service', $odoo['type'] );
	}

	public function test_rank_mapping_includes_priority(): void {
		$odoo = $this->module->map_to_odoo( 'rank', [ 'title' => 'Gold', 'description' => '', 'priority' => 5 ] );
		$this->assertSame( 5, $odoo['x_gamipress_priority'] );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_with_gamipress(): void {
		$status = $this->module->get_dependency_status();
		// gamipress() is defined in test stubs.
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_no_warning_within_version_range(): void {
		// GAMIPRESS_VERSION is 2.8.0, TESTED_UP_TO is 3.0.
		// 2.8.0 is within range, no warning.
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ───────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Handler: load_points ─────────────────────────────

	public function test_load_points_returns_data_for_valid_user(): void {
		$user               = new \stdClass();
		$user->ID           = 42;
		$user->user_email   = 'test@example.com';
		$user->display_name = 'John Doe';
		$user->first_name   = 'John';
		$user->last_name    = 'Doe';
		$user->user_login   = 'johndoe';

		$GLOBALS['_wp_users'][42]                = $user;
		$GLOBALS['_gamipress_points'][42]['points'] = 150;

		$data = $this->handler->load_points( 42, 'points' );

		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 'test@example.com', $data['email'] );
		$this->assertSame( 'John Doe', $data['name'] );
		$this->assertSame( 150, $data['points'] );
	}

	public function test_load_points_returns_empty_for_nonexistent_user(): void {
		$data = $this->handler->load_points( 999, 'points' );
		$this->assertSame( [], $data );
	}

	public function test_load_points_returns_empty_for_zero_user_id(): void {
		$data = $this->handler->load_points( 0, 'points' );
		$this->assertSame( [], $data );
	}

	public function test_load_points_returns_zero_when_no_points_stored(): void {
		$user               = new \stdClass();
		$user->ID           = 10;
		$user->user_email   = 'user@example.com';
		$user->display_name = 'User';
		$user->first_name   = 'User';
		$user->last_name    = '';
		$user->user_login   = 'user';

		$GLOBALS['_wp_users'][10] = $user;

		$data = $this->handler->load_points( 10, 'points' );

		$this->assertSame( 0, $data['points'] );
	}

	// ─── Handler: load_achievement ────────────────────────

	public function test_load_achievement_returns_data_for_valid_achievement(): void {
		$post               = new \stdClass();
		$post->ID           = 100;
		$post->post_title   = 'First Login';
		$post->post_content = 'Log in for the first time.';
		$post->post_type    = 'achievement-type';

		$GLOBALS['_wp_posts'][100]     = $post;
		$GLOBALS['_wp_post_meta'][100] = [ '_gamipress_points' => 50 ];

		$data = $this->handler->load_achievement( 100 );

		$this->assertSame( 100, $data['id'] );
		$this->assertSame( 'First Login', $data['title'] );
		$this->assertSame( 'Log in for the first time.', $data['description'] );
		$this->assertSame( 50, $data['points'] );
	}

	public function test_load_achievement_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_achievement( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_achievement_returns_empty_for_wrong_post_type(): void {
		$post               = new \stdClass();
		$post->ID           = 200;
		$post->post_title   = 'Not Achievement';
		$post->post_content = 'Regular post.';
		$post->post_type    = 'post';

		$GLOBALS['_wp_posts'][200] = $post;

		$data = $this->handler->load_achievement( 200 );
		$this->assertSame( [], $data );
	}

	public function test_load_achievement_returns_empty_for_zero_id(): void {
		$data = $this->handler->load_achievement( 0 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: load_rank ───────────────────────────────

	public function test_load_rank_returns_data_for_valid_rank(): void {
		$post               = new \stdClass();
		$post->ID           = 300;
		$post->post_title   = 'Gold Rank';
		$post->post_content = 'Achieved gold status.';
		$post->post_type    = 'rank-type';

		$GLOBALS['_wp_posts'][300]     = $post;
		$GLOBALS['_wp_post_meta'][300] = [ '_gamipress_priority' => 10 ];

		$data = $this->handler->load_rank( 300 );

		$this->assertSame( 300, $data['id'] );
		$this->assertSame( 'Gold Rank', $data['title'] );
		$this->assertSame( 'Achieved gold status.', $data['description'] );
		$this->assertSame( 10, $data['priority'] );
	}

	public function test_load_rank_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_rank( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_rank_returns_empty_for_wrong_post_type(): void {
		$post               = new \stdClass();
		$post->ID           = 400;
		$post->post_title   = 'Not Rank';
		$post->post_content = 'Regular page.';
		$post->post_type    = 'page';

		$GLOBALS['_wp_posts'][400] = $post;

		$data = $this->handler->load_rank( 400 );
		$this->assertSame( [], $data );
	}

	public function test_load_rank_returns_empty_for_zero_id(): void {
		$data = $this->handler->load_rank( 0 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: save_points ─────────────────────────────

	public function test_save_points_sets_points_correctly(): void {
		$GLOBALS['_gamipress_points'][42]['points'] = 50;

		$result = $this->handler->save_points( 42, 200, 'points' );

		$this->assertSame( 42, $result );
		$this->assertSame( 200, gamipress_get_user_points( 42, 'points' ) );
	}

	public function test_save_points_deducts_when_target_lower(): void {
		$GLOBALS['_gamipress_points'][42]['points'] = 300;

		$result = $this->handler->save_points( 42, 100, 'points' );

		$this->assertSame( 42, $result );
		$this->assertSame( 100, gamipress_get_user_points( 42, 'points' ) );
	}

	public function test_save_points_returns_zero_for_invalid_user_id(): void {
		$result = $this->handler->save_points( 0, 100, 'points' );
		$this->assertSame( 0, $result );
	}

	public function test_save_points_no_change_when_equal(): void {
		$GLOBALS['_gamipress_points'][42]['points'] = 100;

		$result = $this->handler->save_points( 42, 100, 'points' );

		$this->assertSame( 42, $result );
		$this->assertSame( 100, gamipress_get_user_points( 42, 'points' ) );
	}

	// ─── Handler: format_loyalty_card ─────────────────────

	public function test_format_loyalty_card_structure(): void {
		$card = $this->handler->format_loyalty_card( 150, 10, 5 );

		$this->assertArrayHasKey( 'partner_id', $card );
		$this->assertArrayHasKey( 'program_id', $card );
		$this->assertArrayHasKey( 'points', $card );
	}

	public function test_format_loyalty_card_partner_id(): void {
		$card = $this->handler->format_loyalty_card( 150, 10, 5 );
		$this->assertSame( 10, $card['partner_id'] );
	}

	public function test_format_loyalty_card_program_id(): void {
		$card = $this->handler->format_loyalty_card( 150, 10, 5 );
		$this->assertSame( 5, $card['program_id'] );
	}

	public function test_format_loyalty_card_points_as_float(): void {
		$card = $this->handler->format_loyalty_card( 150, 10, 5 );
		$this->assertIsFloat( $card['points'] );
		$this->assertSame( 150.0, $card['points'] );
	}

	// ─── Handler: format_achievement_product ──────────────

	public function test_format_achievement_product_name(): void {
		$product = $this->handler->format_achievement_product( [ 'title' => 'First Login', 'description' => 'Desc' ] );
		$this->assertSame( 'First Login', $product['name'] );
	}

	public function test_format_achievement_product_type_service(): void {
		$product = $this->handler->format_achievement_product( [ 'title' => 'Test', 'description' => '' ] );
		$this->assertSame( 'service', $product['type'] );
	}

	public function test_format_achievement_product_sale_ok_false(): void {
		$product = $this->handler->format_achievement_product( [ 'title' => 'Test', 'description' => '' ] );
		$this->assertFalse( $product['sale_ok'] );
	}

	public function test_format_achievement_product_purchase_ok_false(): void {
		$product = $this->handler->format_achievement_product( [ 'title' => 'Test', 'description' => '' ] );
		$this->assertFalse( $product['purchase_ok'] );
	}

	public function test_format_achievement_product_description(): void {
		$product = $this->handler->format_achievement_product( [ 'title' => 'Test', 'description' => 'Earn this badge.' ] );
		$this->assertSame( 'Earn this badge.', $product['description_sale'] );
	}

	// ─── Handler: format_rank_product ─────────────────────

	public function test_format_rank_product_name(): void {
		$product = $this->handler->format_rank_product( [ 'title' => 'Gold', 'description' => '', 'priority' => 5 ] );
		$this->assertSame( 'Gold', $product['name'] );
	}

	public function test_format_rank_product_type_service(): void {
		$product = $this->handler->format_rank_product( [ 'title' => 'Gold', 'description' => '', 'priority' => 5 ] );
		$this->assertSame( 'service', $product['type'] );
	}

	public function test_format_rank_product_priority(): void {
		$product = $this->handler->format_rank_product( [ 'title' => 'Gold', 'description' => '', 'priority' => 5 ] );
		$this->assertSame( 5, $product['x_gamipress_priority'] );
	}

	public function test_format_rank_product_sale_ok_false(): void {
		$product = $this->handler->format_rank_product( [ 'title' => 'Gold', 'description' => '', 'priority' => 0 ] );
		$this->assertFalse( $product['sale_ok'] );
	}

	public function test_format_rank_product_purchase_ok_false(): void {
		$product = $this->handler->format_rank_product( [ 'title' => 'Gold', 'description' => '', 'priority' => 0 ] );
		$this->assertFalse( $product['purchase_ok'] );
	}

	// ─── Hooks: on_points_awarded ─────────────────────────

	public function test_on_points_awarded_enqueues_push(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_points' => true ];

		$this->module->on_points_awarded( 42, 100, 'points' );

		$this->assertQueueContains( 'gamipress', 'points', 'create', 42 );
	}

	public function test_on_points_awarded_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_points' => false ];

		$this->module->on_points_awarded( 42, 100, 'points' );

		$this->assertQueueEmpty();
	}

	public function test_on_points_awarded_skips_zero_user_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_points' => true ];

		$this->module->on_points_awarded( 0, 100, 'points' );

		$this->assertQueueEmpty();
	}

	public function test_on_points_awarded_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_points' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing' );
		$prop->setValue( null, [ 'gamipress' => true ] );

		$this->module->on_points_awarded( 42, 100, 'points' );

		$this->assertQueueEmpty();

		// Clean up.
		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_points_deducted ────────────────────────

	public function test_on_points_deducted_enqueues_push(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_points' => true ];

		$this->module->on_points_deducted( 42, 50, 'points' );

		$this->assertQueueContains( 'gamipress', 'points', 'create', 42 );
	}

	public function test_on_points_deducted_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_points' => false ];

		$this->module->on_points_deducted( 42, 50, 'points' );

		$this->assertQueueEmpty();
	}

	public function test_on_points_deducted_skips_zero_user_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_points' => true ];

		$this->module->on_points_deducted( 0, 50, 'points' );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_achievement_earned ─────────────────────

	public function test_on_achievement_earned_enqueues_push(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_achievements' => true ];

		$this->module->on_achievement_earned( 42, 100 );

		$this->assertQueueContains( 'gamipress', 'achievement', 'create', 100 );
	}

	public function test_on_achievement_earned_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_achievements' => false ];

		$this->module->on_achievement_earned( 42, 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_achievement_earned_skips_zero_achievement_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_achievements' => true ];

		$this->module->on_achievement_earned( 42, 0 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_rank_earned ────────────────────────────

	public function test_on_rank_earned_enqueues_push(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_ranks' => true ];

		$this->module->on_rank_earned( 42, 300 );

		$this->assertQueueContains( 'gamipress', 'rank', 'create', 300 );
	}

	public function test_on_rank_earned_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_ranks' => false ];

		$this->module->on_rank_earned( 42, 300 );

		$this->assertQueueEmpty();
	}

	public function test_on_rank_earned_skips_zero_rank_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'sync_ranks' => true ];

		$this->module->on_rank_earned( 42, 0 );

		$this->assertQueueEmpty();
	}

	// ─── Pull: achievement skipped ────────────────────────

	public function test_pull_achievement_skipped(): void {
		$result = $this->module->pull_from_odoo( 'achievement', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: rank skipped ───────────────────────────────

	public function test_pull_rank_skipped(): void {
		$result = $this->module->pull_from_odoo( 'rank', 'create', 200, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: points ─────────────────────────────────────

	public function test_pull_points_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [ 'pull_points' => false ];

		$result = $this->module->pull_from_odoo( 'points', 'update', 55, 42 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Push: points ─────────────────────────────────────

	public function test_push_points_skips_delete_action(): void {
		$result = $this->module->push_to_odoo( 'points', 'delete', 42, 55 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_push_points_fails_when_program_id_not_configured(): void {
		// Set transient to indicate loyalty model is available.
		$GLOBALS['_wp_transients']['wp4odoo_has_loyalty_program'] = 1;
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [
			'sync_points'      => true,
			'pull_points'      => true,
			'odoo_program_id'  => 0,
		];

		$result = $this->module->push_to_odoo( 'points', 'create', 42, 0 );

		$this->assertFalse( $result->succeeded() );
	}

	public function test_push_points_fails_when_user_not_found(): void {
		$GLOBALS['_wp_transients']['wp4odoo_has_loyalty_program'] = 1;
		$GLOBALS['_wp_options']['wp4odoo_module_gamipress_settings'] = [
			'sync_points'      => true,
			'pull_points'      => true,
			'odoo_program_id'  => 5,
		];

		// No user data -> load_points returns empty.
		$result = $this->module->push_to_odoo( 'points', 'create', 999, 0 );

		$this->assertFalse( $result->succeeded() );
	}

	// ─── Dedup Domains ────────────────────────────────────

	public function test_dedup_achievement_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'achievement', [ 'name' => 'First Login' ] );

		$this->assertSame( [ [ 'name', '=', 'First Login' ] ], $domain );
	}

	public function test_dedup_rank_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'rank', [ 'name' => 'Gold' ] );

		$this->assertSame( [ [ 'name', '=', 'Gold' ] ], $domain );
	}

	public function test_dedup_empty_when_no_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'achievement', [] );

		$this->assertSame( [], $domain );
	}

	public function test_dedup_empty_for_points(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'points', [ 'name' => 'test' ] );

		$this->assertSame( [], $domain );
	}

	// ─── map_from_odoo: points ────────────────────────────

	public function test_map_from_odoo_points_rounds_float(): void {
		$data = $this->module->map_from_odoo( 'points', [ 'points' => 150.7 ] );
		$this->assertSame( 151, $data['points'] );
	}

	public function test_map_from_odoo_points_zero_default(): void {
		$data = $this->module->map_from_odoo( 'points', [] );
		$this->assertSame( 0, $data['points'] );
	}

	// ─── Helpers ──────────────────────────────────────────

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
