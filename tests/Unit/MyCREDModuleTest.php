<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\MyCRED_Module;
use WP4Odoo\Modules\MyCRED_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MyCRED_Module, MyCRED_Handler, and MyCRED_Hooks.
 *
 * Tests module configuration, handler data loading/saving/formatting,
 * hook guard logic, pull overrides, and dedup domains.
 *
 * @since 3.6.0
 */
class MyCREDModuleTest extends TestCase {

	private MyCRED_Module $module;
	private MyCRED_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_users']      = [];
		$GLOBALS['_wp_user_meta']  = [];
		$GLOBALS['_wp_posts']      = [];
		$GLOBALS['_wp_post_meta']  = [];
		$GLOBALS['_mycred_points'] = [];
		$GLOBALS['_mycred_badges'] = [];

		$this->module  = new MyCRED_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new MyCRED_Handler( new Logger( 'mycred', wp4odoo_test_settings() ) );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_mycred(): void {
		$this->assertSame( 'mycred', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'myCRED', $this->module->get_name() );
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

	public function test_declares_badge_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.template', $models['badge'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 2, $models );
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

	public function test_default_settings_has_sync_badges(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_badges'] );
	}

	public function test_default_settings_has_odoo_program_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_program_id'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
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

	public function test_settings_fields_exposes_sync_badges(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_badges', $fields );
		$this->assertSame( 'checkbox', $fields['sync_badges']['type'] );
	}

	public function test_settings_fields_exposes_odoo_program_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'odoo_program_id', $fields );
		$this->assertSame( 'number', $fields['odoo_program_id']['type'] );
	}

	public function test_settings_fields_has_exactly_four_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 4, $fields );
	}

	// ─── Required Modules ─────────────────────────────────

	public function test_required_modules_is_empty(): void {
		$this->assertSame( [], $this->module->get_required_modules() );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_with_mycred(): void {
		$status = $this->module->get_dependency_status();
		// myCRED_VERSION is defined in test stubs.
		$this->assertTrue( $status['available'] );
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

		$GLOBALS['_wp_users'][42]                                = $user;
		$GLOBALS['_mycred_points'][42]['mycred_default'] = 150;

		$data = $this->handler->load_points( 42, 'mycred_default' );

		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 'test@example.com', $data['email'] );
		$this->assertSame( 'John Doe', $data['name'] );
		$this->assertSame( 150, $data['points'] );
	}

	public function test_load_points_returns_empty_for_nonexistent_user(): void {
		$data = $this->handler->load_points( 999, 'mycred_default' );
		$this->assertSame( [], $data );
	}

	public function test_load_points_returns_empty_for_zero_user_id(): void {
		$data = $this->handler->load_points( 0, 'mycred_default' );
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

		$data = $this->handler->load_points( 10, 'mycred_default' );

		$this->assertSame( 0, $data['points'] );
	}

	// ─── Handler: load_badge ──────────────────────────────

	public function test_load_badge_returns_data_for_valid_badge(): void {
		$post               = new \stdClass();
		$post->ID           = 100;
		$post->post_title   = 'First Login';
		$post->post_content = 'Log in for the first time.';
		$post->post_type    = 'mycred_badge';

		$GLOBALS['_wp_posts'][100] = $post;

		$data = $this->handler->load_badge( 100 );

		$this->assertSame( 100, $data['id'] );
		$this->assertSame( 'First Login', $data['title'] );
		$this->assertSame( 'Log in for the first time.', $data['description'] );
	}

	public function test_load_badge_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_badge( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_badge_returns_empty_for_wrong_post_type(): void {
		$post               = new \stdClass();
		$post->ID           = 200;
		$post->post_title   = 'Not Badge';
		$post->post_content = 'Regular post.';
		$post->post_type    = 'post';

		$GLOBALS['_wp_posts'][200] = $post;

		$data = $this->handler->load_badge( 200 );
		$this->assertSame( [], $data );
	}

	public function test_load_badge_returns_empty_for_zero_id(): void {
		$data = $this->handler->load_badge( 0 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: save_points ─────────────────────────────

	public function test_save_points_sets_points_correctly(): void {
		$GLOBALS['_mycred_points'][42]['mycred_default'] = 50;

		$result = $this->handler->save_points( 42, 200, 'mycred_default' );

		$this->assertSame( 42, $result );
		$this->assertSame( 200, mycred_get_users_cred( 42, 'mycred_default' ) );
	}

	public function test_save_points_deducts_when_target_lower(): void {
		$GLOBALS['_mycred_points'][42]['mycred_default'] = 300;

		$result = $this->handler->save_points( 42, 100, 'mycred_default' );

		$this->assertSame( 42, $result );
		$this->assertSame( 100, mycred_get_users_cred( 42, 'mycred_default' ) );
	}

	public function test_save_points_returns_zero_for_invalid_user_id(): void {
		$result = $this->handler->save_points( 0, 100, 'mycred_default' );
		$this->assertSame( 0, $result );
	}

	public function test_save_points_no_change_when_equal(): void {
		$GLOBALS['_mycred_points'][42]['mycred_default'] = 100;

		$result = $this->handler->save_points( 42, 100, 'mycred_default' );

		$this->assertSame( 42, $result );
		$this->assertSame( 100, mycred_get_users_cred( 42, 'mycred_default' ) );
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

	// ─── Handler: format_badge_product ────────────────────

	public function test_format_badge_product_name(): void {
		$product = $this->handler->format_badge_product( [ 'title' => 'First Login', 'description' => 'Desc' ] );
		$this->assertSame( 'First Login', $product['name'] );
	}

	public function test_format_badge_product_description(): void {
		$product = $this->handler->format_badge_product( [ 'title' => 'Test', 'description' => 'Earn this badge.' ] );
		$this->assertSame( 'Earn this badge.', $product['description_sale'] );
	}

	public function test_format_badge_product_type_service(): void {
		$product = $this->handler->format_badge_product( [ 'title' => 'Test', 'description' => '' ] );
		$this->assertSame( 'service', $product['type'] );
	}

	public function test_format_badge_product_sale_ok_false(): void {
		$product = $this->handler->format_badge_product( [ 'title' => 'Test', 'description' => '' ] );
		$this->assertFalse( $product['sale_ok'] );
	}

	public function test_format_badge_product_purchase_ok_false(): void {
		$product = $this->handler->format_badge_product( [ 'title' => 'Test', 'description' => '' ] );
		$this->assertFalse( $product['purchase_ok'] );
	}

	// ─── Hooks: on_points_change ──────────────────────────

	public function test_on_points_change_enqueues_push(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'sync_points' => true ];

		$this->module->on_points_change( 42, 100, 'mycred_default', '' );

		$this->assertQueueContains( 'mycred', 'points', 'create', 42 );
	}

	public function test_on_points_change_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'sync_points' => false ];

		$this->module->on_points_change( 42, 100, 'mycred_default', '' );

		$this->assertQueueEmpty();
	}

	public function test_on_points_change_skips_zero_user_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'sync_points' => true ];

		$this->module->on_points_change( 0, 100, 'mycred_default', '' );

		$this->assertQueueEmpty();
	}

	public function test_on_points_change_skips_odoo_sync_reference(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'sync_points' => true ];

		$this->module->on_points_change( 42, 100, 'mycred_default', 'odoo_sync' );

		$this->assertQueueEmpty();
	}

	public function test_on_points_change_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'sync_points' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing' );
		$prop->setValue( null, [ 'mycred' => true ] );

		$this->module->on_points_change( 42, 100, 'mycred_default', '' );

		$this->assertQueueEmpty();

		// Clean up.
		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_badge_earned ───────────────────────────

	public function test_on_badge_earned_enqueues_push(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'sync_badges' => true ];

		$this->module->on_badge_earned( 42, 100 );

		$this->assertQueueContains( 'mycred', 'badge', 'create', 100 );
	}

	public function test_on_badge_earned_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'sync_badges' => false ];

		$this->module->on_badge_earned( 42, 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_badge_earned_skips_zero_badge_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'sync_badges' => true ];

		$this->module->on_badge_earned( 42, 0 );

		$this->assertQueueEmpty();
	}

	// ─── Pull: badge skipped ──────────────────────────────

	public function test_pull_badge_skipped(): void {
		$result = $this->module->pull_from_odoo( 'badge', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: points skipped when disabled ───────────────

	public function test_pull_points_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [ 'pull_points' => false ];

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
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [
			'sync_points'     => true,
			'pull_points'     => true,
			'odoo_program_id' => 0,
		];

		$result = $this->module->push_to_odoo( 'points', 'create', 42, 0 );

		$this->assertFalse( $result->succeeded() );
	}

	public function test_push_points_fails_when_user_not_found(): void {
		$GLOBALS['_wp_transients']['wp4odoo_has_loyalty_program'] = 1;
		$GLOBALS['_wp_options']['wp4odoo_module_mycred_settings'] = [
			'sync_points'     => true,
			'pull_points'     => true,
			'odoo_program_id' => 5,
		];

		// No user data -> load_points returns empty.
		$result = $this->module->push_to_odoo( 'points', 'create', 999, 0 );

		$this->assertFalse( $result->succeeded() );
	}

	// ─── Dedup Domains ────────────────────────────────────

	public function test_dedup_badge_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'badge', [ 'name' => 'First Login' ] );

		$this->assertSame( [ [ 'name', '=', 'First Login' ] ], $domain );
	}

	public function test_dedup_empty_when_no_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'badge', [] );

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

	// ─── map_to_odoo: badge ───────────────────────────────

	public function test_map_to_odoo_badge_delegates_to_handler(): void {
		$odoo = $this->module->map_to_odoo( 'badge', [ 'title' => 'Gold Star', 'description' => 'A gold star badge' ] );

		$this->assertSame( 'Gold Star', $odoo['name'] );
		$this->assertSame( 'A gold star badge', $odoo['description_sale'] );
		$this->assertSame( 'service', $odoo['type'] );
		$this->assertFalse( $odoo['sale_ok'] );
		$this->assertFalse( $odoo['purchase_ok'] );
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
