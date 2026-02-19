<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Awesome_Support_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for Awesome_Support_Hooks trait.
 *
 * Tests hook callbacks: anti-loop guard, settings guard,
 * queue enqueue behavior, and zero ID guard.
 */
class AwesomeSupportHooksTest extends Module_Test_Case {

	private Awesome_Support_Module $module;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wpas_tickets'] = [];

		$this->module = new Awesome_Support_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── on_ticket_created ─────────────────────────────

	public function test_on_ticket_created_enqueues_job(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_awesome_support_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$this->module->on_ticket_created( 42, [ 'title' => 'Bug' ] );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_ticket_created_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_awesome_support_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		// Simulate importing state via reflection.
		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'awesome_support' => true ] );

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_created( 42, [] );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );

		// Clean up static state.
		$prop->setValue( null, [] );
	}

	public function test_on_ticket_created_skips_when_sync_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_awesome_support_settings'] = [
			'sync_tickets'    => false,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_created( 42, [] );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_ticket_created_skips_zero_ticket_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_awesome_support_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_created( 0, [] );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	// ─── on_ticket_status_updated ──────────────────────

	public function test_on_ticket_status_updated_enqueues_job(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_awesome_support_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$this->module->on_ticket_status_updated( 42 );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_ticket_status_updated_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_awesome_support_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'awesome_support' => true ] );

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_status_updated( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );

		$prop->setValue( null, [] );
	}

	public function test_on_ticket_status_updated_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_awesome_support_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_status_updated( 0 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}
}
