<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Fluent_Support_Module;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Fluent_Support_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Fluent_Support_Module::class)]
class FluentSupportModuleTest extends TestCase {

	private Fluent_Support_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']              = [];
		$GLOBALS['_wp_users']                = [];
		$GLOBALS['_wp_user_meta']            = [];
		$GLOBALS['_wp_posts']                = [];
		$GLOBALS['_wp_post_meta']            = [];
		$GLOBALS['_fluent_support_tickets']  = [];

		$this->module = new Fluent_Support_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ──────────────────────────────────

	public function test_module_id_is_fluent_support(): void {
		$this->assertSame( 'fluent_support', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'Fluent Support', $this->module->get_name() );
	}

	public function test_exclusive_group_is_helpdesk(): void {
		$this->assertSame( 'helpdesk', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ─────────────────────────────────────

	public function test_declares_ticket_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'helpdesk.ticket', $models['ticket'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 1, $models );
	}

	// ─── Default Settings ─────────────────────────────────

	public function test_default_settings_has_sync_tickets(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_tickets'] );
	}

	public function test_default_settings_has_pull_tickets(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_tickets'] );
	}

	public function test_default_settings_has_odoo_team_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_team_id'] );
	}

	public function test_default_settings_has_odoo_project_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_project_id'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
	}

	// ─── Settings Fields ──────────────────────────────────

	public function test_settings_fields_exposes_sync_tickets(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_tickets', $fields );
		$this->assertSame( 'checkbox', $fields['sync_tickets']['type'] );
	}

	public function test_settings_fields_exposes_pull_tickets(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_tickets', $fields );
		$this->assertSame( 'checkbox', $fields['pull_tickets']['type'] );
	}

	public function test_settings_fields_exposes_odoo_team_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'odoo_team_id', $fields );
		$this->assertSame( 'number', $fields['odoo_team_id']['type'] );
	}

	public function test_settings_fields_exposes_odoo_project_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'odoo_project_id', $fields );
		$this->assertSame( 'number', $fields['odoo_project_id']['type'] );
	}

	public function test_settings_fields_has_exactly_four_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 4, $fields );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_when_constant_defined(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Pull Override ────────────────────────────────────

	public function test_pull_skips_when_pull_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluent_support_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => false,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$result = $this->module->pull_from_odoo( 'ticket', 'update', 55, 42 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_skips_non_update_action(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluent_support_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$result = $this->module->pull_from_odoo( 'ticket', 'create', 55, 42 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Push Override ────────────────────────────────────

	public function test_push_returns_success_for_delete_action_without_odoo_id(): void {
		$result = $this->module->push_to_odoo( 'ticket', 'delete', 42, 0 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Odoo Model Name ──────────────────────────────────

	public function test_ticket_odoo_model_is_helpdesk_ticket(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'helpdesk.ticket', $models['ticket'] );
	}

	// ─── Boot ─────────────────────────────────────────────

	public function test_boot_does_not_throw(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Handler: load_ticket ─────────────────────────────

	public function test_handler_loads_ticket_from_store(): void {
		$GLOBALS['_fluent_support_tickets'][10] = [
			'title'       => 'Cannot login',
			'content'     => 'I cannot log in to my account.',
			'customer_id' => 5,
			'status'      => 'active',
			'priority'    => 'critical',
		];

		$method = new \ReflectionMethod( Fluent_Support_Module::class, 'handler_load_ticket' );

		$data = $method->invoke( $this->module, 10 );

		$this->assertSame( 'Cannot login', $data['name'] );
		$this->assertSame( 'I cannot log in to my account.', $data['description'] );
		$this->assertSame( 5, $data['_user_id'] );
		$this->assertSame( 'active', $data['_wp_status'] );
		$this->assertSame( '3', $data['priority'] ); // critical → urgent → '3'.
	}

	public function test_handler_returns_empty_for_missing_ticket(): void {
		$method = new \ReflectionMethod( Fluent_Support_Module::class, 'handler_load_ticket' );

		$data = $method->invoke( $this->module, 999 );

		$this->assertEmpty( $data );
	}

	// ─── Handler: save_ticket_status ──────────────────────

	public function test_handler_saves_ticket_status(): void {
		$GLOBALS['_fluent_support_tickets'][20] = [
			'title'  => 'Test ticket',
			'status' => 'active',
		];

		$method = new \ReflectionMethod( Fluent_Support_Module::class, 'handler_save_ticket_status' );

		$result = $method->invoke( $this->module, 20, 'closed' );

		$this->assertTrue( $result );
		$this->assertSame( 'closed', $GLOBALS['_fluent_support_tickets'][20]['status'] );
	}

	public function test_handler_save_returns_false_for_missing_ticket(): void {
		$method = new \ReflectionMethod( Fluent_Support_Module::class, 'handler_save_ticket_status' );

		$result = $method->invoke( $this->module, 999, 'closed' );

		$this->assertFalse( $result );
	}
}
