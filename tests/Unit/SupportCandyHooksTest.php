<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\SupportCandy_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for SupportCandy_Hooks trait.
 *
 * Tests hook callbacks: anti-loop guard, settings guard,
 * queue enqueue behavior, and ticket ID extraction.
 */
class SupportCandyHooksTest extends Module_Test_Case {

	private SupportCandy_Module $module;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_supportcandy_tickets']    = [];
		$GLOBALS['_supportcandy_ticketmeta'] = [];

		$this->module = new SupportCandy_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── on_ticket_created ─────────────────────────────

	public function test_on_ticket_created_enqueues_job_with_int(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$this->module->on_ticket_created( 42 );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_ticket_created_extracts_id_from_object(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$ticket     = new \stdClass();
		$ticket->id = 42;

		$this->module->on_ticket_created( $ticket );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_ticket_created_extracts_id_from_array(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$this->module->on_ticket_created( [ 'id' => 42 ] );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_ticket_created_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'supportcandy' => true ] );

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_created( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );

		$prop->setValue( null, [] );
	}

	public function test_on_ticket_created_skips_when_sync_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => false,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_created( 42 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_ticket_created_skips_zero_ticket_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_created( 0 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	// ─── on_ticket_status_changed ──────────────────────

	public function test_on_ticket_status_changed_enqueues_job(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$this->module->on_ticket_status_changed( 42, 1, 3, 1 );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_ticket_status_changed_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'supportcandy' => true ] );

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_status_changed( 42, 1, 3, 1 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );

		$prop->setValue( null, [] );
	}

	public function test_on_ticket_status_changed_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$initial_call_count = count( $this->wpdb->calls );

		$this->module->on_ticket_status_changed( 0, 1, 3, 1 );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}

	public function test_on_ticket_created_returns_zero_for_unrecognized_type(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_supportcandy_settings'] = [
			'sync_tickets'    => true,
			'pull_tickets'    => true,
			'odoo_team_id'    => 1,
			'odoo_project_id' => 0,
		];

		$initial_call_count = count( $this->wpdb->calls );

		// Pass a string — extract_ticket_id returns 0.
		$this->module->on_ticket_created( 'invalid' );

		$this->assertCount( $initial_call_count, $this->wpdb->calls );
	}
}
