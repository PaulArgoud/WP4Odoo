<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\Project_Manager_Module;

/**
 * Unit tests for Project_Manager_Module.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Project_Manager_Module::class)]
class ProjectManagerModuleTest extends TestCase {

	private Project_Manager_Module $module;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options'] = [];

		$this->module = new Project_Manager_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'project_manager', $ref->getValue( $this->module ) );
	}

	public function test_module_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP Project Manager', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_project_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'project.project', $ref->getValue( $this->module )['project'] );
	}

	public function test_declares_task_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'project.task', $ref->getValue( $this->module )['task'] );
	}

	public function test_declares_timesheet_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'account.analytic.line', $ref->getValue( $this->module )['timesheet'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 3, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_default_settings_has_sync_projects(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_projects'] );
	}

	public function test_default_settings_has_sync_tasks(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_tasks'] );
	}

	public function test_default_settings_has_sync_timesheets(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_timesheets'] );
	}

	public function test_default_settings_has_pull_projects(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_projects'] );
	}

	public function test_default_settings_has_pull_tasks(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_tasks'] );
	}

	public function test_default_settings_has_exactly_five_keys(): void {
		$this->assertCount( 5, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_has_sync_projects(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_projects', $fields );
		$this->assertSame( 'checkbox', $fields['sync_projects']['type'] );
	}

	public function test_settings_fields_has_sync_tasks(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_tasks', $fields );
		$this->assertSame( 'checkbox', $fields['sync_tasks']['type'] );
	}

	public function test_settings_fields_has_sync_timesheets(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_timesheets', $fields );
		$this->assertSame( 'checkbox', $fields['sync_timesheets']['type'] );
	}

	public function test_settings_fields_has_pull_projects(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_projects', $fields );
		$this->assertSame( 'checkbox', $fields['pull_projects']['type'] );
	}

	public function test_settings_fields_has_pull_tasks(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_tasks', $fields );
		$this->assertSame( 'checkbox', $fields['pull_tasks']['type'] );
	}

	public function test_settings_fields_has_exactly_five_entries(): void {
		$this->assertCount( 5, $this->module->get_settings_fields() );
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_project_mapping_has_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'name', $mappings['project']['title'] );
	}

	public function test_project_mapping_has_description(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'description', $mappings['project']['description'] );
	}

	public function test_task_mapping_has_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'name', $mappings['task']['title'] );
	}

	public function test_task_mapping_has_deadline(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'date_deadline', $mappings['task']['due_date'] );
	}

	// ─── map_to_odoo ────────────────────────────────────────

	public function test_map_timesheet_returns_raw_data(): void {
		$input = [
			'name'        => 'Development',
			'project_id'  => 1,
			'task_id'     => 5,
			'employee_id' => 3,
			'unit_amount' => 2.5,
			'date'        => '2025-06-15',
		];

		$data = $this->module->map_to_odoo( 'timesheet', $input );

		$this->assertSame( $input, $data );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_when_constant_defined(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}
}
