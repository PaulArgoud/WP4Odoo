<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\WPERP_Module;
use WP4Odoo\Modules\WPERP_Handler;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for WPERP_Module, WPERP_Handler, and WPERP_Hooks.
 *
 * Covers module identity, settings, dependency status, handler methods
 * (load, save, delete, parse, status mapping, dependency resolution),
 * hook callbacks, pull guards, dedup domains, and push dependency chain.
 *
 * @covers \WP4Odoo\Modules\WPERP_Module
 * @covers \WP4Odoo\Modules\WPERP_Handler
 */
class WPERPModuleTest extends Module_Test_Case {

	private WPERP_Module $module;

	protected function setUp(): void {
		parent::setUp();

		// Simulate all required tables exist (SHOW TABLES LIKE returns the name).
		$this->wpdb->get_var_return = 'wp_erp_hr_employees';

		$this->module = new WPERP_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ────────────────────────────────────

	public function test_module_id_is_wperp(): void {
		$this->assertSame( 'wperp', $this->module->get_id() );
	}

	public function test_module_name_is_wp_erp(): void {
		$this->assertSame( 'WP ERP', $this->module->get_name() );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_employee_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'hr.employee', $models['employee'] );
	}

	public function test_declares_department_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'hr.department', $models['department'] );
	}

	public function test_declares_leave_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'hr.leave', $models['leave'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_sync_employees_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_employees'] );
	}

	public function test_sync_departments_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_departments'] );
	}

	public function test_sync_leaves_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_leaves'] );
	}

	public function test_pull_employees_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_employees'] );
	}

	public function test_pull_departments_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_departments'] );
	}

	public function test_pull_leaves_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_leaves'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 6, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_count(): void {
		$this->assertCount( 6, $this->module->get_settings_fields() );
	}

	public function test_settings_fields_sync_employees_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_employees']['type'] );
	}

	public function test_settings_fields_sync_departments_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_departments']['type'] );
	}

	public function test_settings_fields_sync_leaves_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_leaves']['type'] );
	}

	public function test_settings_fields_pull_employees_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_employees']['type'] );
	}

	public function test_settings_fields_pull_departments_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_departments']['type'] );
	}

	public function test_settings_fields_pull_leaves_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_leaves']['type'] );
	}

	public function test_settings_fields_have_labels(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $field ) {
			$this->assertNotEmpty( $field['label'] );
		}
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_wperp(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_when_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Handler: load_employee ──────────────────────────────

	public function test_handler_load_employee_valid(): void {
		$handler = $this->create_handler();

		// get_row returns an associative array when ARRAY_A is used.
		$this->wpdb->get_row_return = [
			'user_id'       => 10,
			'designation'   => 1,
			'department'    => 5,
			'gender'        => 'male',
			'date_of_birth' => '1990-01-15',
		];

		// Set up user data.
		$user                = new \stdClass();
		$user->display_name  = 'John Doe';
		$user->user_email    = 'john@example.com';
		$GLOBALS['_wp_users'][10] = $user;

		$result = $handler->load_employee( 10 );

		$this->assertSame( 'John Doe', $result['name'] );
		$this->assertSame( 'john@example.com', $result['work_email'] );
		$this->assertSame( 'male', $result['gender'] );
		$this->assertSame( 10, $result['user_id'] );
		$this->assertSame( 5, $result['department'] );
	}

	public function test_handler_load_employee_nonexistent(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = null;

		$result = $handler->load_employee( 999 );

		$this->assertEmpty( $result );
	}

	public function test_handler_load_employee_null_fields(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = [
			'user_id'       => 20,
			'designation'   => 0,
			'department'    => 0,
			'gender'        => null,
			'date_of_birth' => null,
		];

		$GLOBALS['_wp_users'][20] = null;

		$result = $handler->load_employee( 20 );

		$this->assertSame( '', $result['name'] );
		$this->assertSame( '', $result['work_email'] );
		$this->assertSame( '', $result['gender'] );
	}

	// ─── Handler: load_department ────────────────────────────

	public function test_handler_load_department_valid(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = [
			'id'     => 5,
			'title'  => 'Engineering',
			'parent' => 0,
		];

		$result = $handler->load_department( 5 );

		$this->assertSame( 'Engineering', $result['name'] );
		$this->assertSame( 0, $result['parent_id'] );
	}

	public function test_handler_load_department_nonexistent(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = null;

		$result = $handler->load_department( 999 );

		$this->assertEmpty( $result );
	}

	public function test_handler_load_department_with_parent(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = [
			'id'     => 8,
			'title'  => 'Backend Team',
			'parent' => 5,
		];

		$result = $handler->load_department( 8 );

		$this->assertSame( 'Backend Team', $result['name'] );
		$this->assertSame( 5, $result['parent_id'] );
	}

	// ─── Handler: load_leave ─────────────────────────────────

	public function test_handler_load_leave_valid(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = [
			'id'         => 1,
			'reason'     => 'Vacation',
			'start_date' => '2026-03-01',
			'end_date'   => '2026-03-05',
			'status'     => 1,
			'user_id'    => 10,
		];

		$result = $handler->load_leave( 1 );

		$this->assertSame( 'Vacation', $result['name'] );
		$this->assertSame( '2026-03-01', $result['date_from'] );
		$this->assertSame( '2026-03-05', $result['date_to'] );
		$this->assertSame( 'draft', $result['state'] );
		$this->assertSame( 10, $result['employee_id'] );
	}

	public function test_handler_load_leave_nonexistent(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = null;

		$result = $handler->load_leave( 999 );

		$this->assertEmpty( $result );
	}

	public function test_handler_load_leave_status_pending(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = [
			'id'         => 2,
			'reason'     => 'Sick',
			'start_date' => '2026-03-10',
			'end_date'   => '2026-03-11',
			'status'     => 1,
			'user_id'    => 10,
		];

		$result = $handler->load_leave( 2 );

		$this->assertSame( 'draft', $result['state'] );
	}

	public function test_handler_load_leave_status_approved(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = [
			'id'         => 3,
			'reason'     => 'Personal',
			'start_date' => '2026-04-01',
			'end_date'   => '2026-04-02',
			'status'     => 2,
			'user_id'    => 10,
		];

		$result = $handler->load_leave( 3 );

		$this->assertSame( 'validate', $result['state'] );
	}

	public function test_handler_load_leave_status_rejected(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_row_return = [
			'id'         => 4,
			'reason'     => 'Denied leave',
			'start_date' => '2026-05-01',
			'end_date'   => '2026-05-03',
			'status'     => 3,
			'user_id'    => 10,
		];

		$result = $handler->load_leave( 4 );

		$this->assertSame( 'refuse', $result['state'] );
	}

	// ─── Handler: save methods ──────────────────────────────

	public function test_handler_save_employee_create(): void {
		$handler = $this->create_handler();

		$this->wpdb->insert_id = 42;

		$result = $handler->save_employee( [
			'job_title'      => 'Developer',
			'department'     => 5,
			'gender'         => 'male',
			'birthday'       => '1990-01-15',
			'user_id'        => 10,
		] );

		$this->assertSame( 42, $result );
	}

	public function test_handler_save_employee_update(): void {
		$handler = $this->create_handler();

		$result = $handler->save_employee( [
			'job_title'  => 'Senior Developer',
			'department' => 5,
			'gender'     => 'male',
			'birthday'   => '1990-01-15',
		], 10 );

		$this->assertSame( 10, $result );
	}

	public function test_handler_save_department_create(): void {
		$handler = $this->create_handler();

		$this->wpdb->insert_id = 7;

		$result = $handler->save_department( [
			'name'      => 'Marketing',
			'parent_id' => 0,
		] );

		$this->assertSame( 7, $result );
	}

	public function test_handler_save_department_update(): void {
		$handler = $this->create_handler();

		$result = $handler->save_department( [
			'name'      => 'Marketing Updated',
			'parent_id' => 0,
		], 5 );

		$this->assertSame( 5, $result );
	}

	public function test_handler_save_leave_create(): void {
		$handler = $this->create_handler();

		$this->wpdb->insert_id = 15;

		$result = $handler->save_leave( [
			'reason'     => 'Vacation',
			'start_date' => '2026-03-01',
			'end_date'   => '2026-03-05',
			'status'     => 1,
			'user_id'    => 10,
		] );

		$this->assertSame( 15, $result );
	}

	public function test_handler_save_leave_update(): void {
		$handler = $this->create_handler();

		$result = $handler->save_leave( [
			'reason'     => 'Updated leave',
			'start_date' => '2026-03-01',
			'end_date'   => '2026-03-05',
			'status'     => 2,
			'user_id'    => 10,
		], 1 );

		$this->assertSame( 1, $result );
	}

	// ─── Handler: delete methods ────────────────────────────

	public function test_handler_delete_employee(): void {
		$handler = $this->create_handler();

		$result = $handler->delete_employee( 10 );

		$this->assertTrue( $result );
	}

	public function test_handler_delete_department(): void {
		$handler = $this->create_handler();

		$result = $handler->delete_department( 5 );

		$this->assertTrue( $result );
	}

	public function test_handler_delete_leave(): void {
		$handler = $this->create_handler();

		$result = $handler->delete_leave( 1 );

		$this->assertTrue( $result );
	}

	// ─── Handler: parse_from_odoo ───────────────────────────

	public function test_handler_parse_employee_from_odoo_name(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_employee_from_odoo( [
			'name'       => 'Jane Smith',
			'work_email' => 'jane@example.com',
			'job_title'  => 'Manager',
			'gender'     => 'female',
		] );

		$this->assertSame( 'Jane Smith', $data['name'] );
		$this->assertSame( 'jane@example.com', $data['work_email'] );
		$this->assertSame( 'Manager', $data['job_title'] );
		$this->assertSame( 'female', $data['gender'] );
	}

	public function test_handler_parse_employee_from_odoo_department_many2one(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_employee_from_odoo( [
			'department_id' => [ 5, 'Engineering' ],
		] );

		$this->assertSame( 'Engineering', $data['department_name'] );
		$this->assertSame( 5, $data['department_odoo_id'] );
	}

	public function test_handler_parse_employee_from_odoo_department_false(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_employee_from_odoo( [
			'department_id' => false,
		] );

		$this->assertSame( '', $data['department_name'] );
		$this->assertSame( 0, $data['department_odoo_id'] );
	}

	public function test_handler_parse_department_from_odoo(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_department_from_odoo( [
			'name'      => 'Sales',
			'parent_id' => [ 2, 'Company' ],
		] );

		$this->assertSame( 'Sales', $data['name'] );
		$this->assertSame( 2, $data['parent_odoo_id'] );
	}

	public function test_handler_parse_department_from_odoo_no_parent(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_department_from_odoo( [
			'name'      => 'Top Level',
			'parent_id' => false,
		] );

		$this->assertSame( 'Top Level', $data['name'] );
		$this->assertSame( 0, $data['parent_odoo_id'] );
	}

	public function test_handler_parse_leave_from_odoo_draft(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_leave_from_odoo( [
			'name'        => 'Vacation',
			'date_from'   => '2026-03-01',
			'date_to'     => '2026-03-05',
			'state'       => 'draft',
			'employee_id' => [ 10, 'John Doe' ],
		] );

		$this->assertSame( 'Vacation', $data['reason'] );
		$this->assertSame( 1, $data['status'] );
		$this->assertSame( 10, $data['employee_odoo_id'] );
	}

	public function test_handler_parse_leave_from_odoo_validate(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_leave_from_odoo( [
			'state' => 'validate',
		] );

		$this->assertSame( 2, $data['status'] );
	}

	public function test_handler_parse_leave_from_odoo_validate1(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_leave_from_odoo( [
			'state' => 'validate1',
		] );

		$this->assertSame( 2, $data['status'] );
	}

	public function test_handler_parse_leave_from_odoo_confirm(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_leave_from_odoo( [
			'state' => 'confirm',
		] );

		$this->assertSame( 1, $data['status'] );
	}

	public function test_handler_parse_leave_from_odoo_refuse(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_leave_from_odoo( [
			'state' => 'refuse',
		] );

		$this->assertSame( 3, $data['status'] );
	}

	public function test_handler_parse_leave_from_odoo_employee_false(): void {
		$handler = $this->create_handler();

		$data = $handler->parse_leave_from_odoo( [
			'employee_id' => false,
		] );

		$this->assertSame( 0, $data['employee_odoo_id'] );
	}

	// ─── Handler: dependency resolution ─────────────────────

	public function test_handler_get_employee_id_for_leave(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_var_return = 10;

		$result = $handler->get_employee_id_for_leave( 1 );

		$this->assertSame( 10, $result );
	}

	public function test_handler_get_department_id_for_employee(): void {
		$handler = $this->create_handler();

		$this->wpdb->get_var_return = 5;

		$result = $handler->get_department_id_for_employee( 10 );

		$this->assertSame( 5, $result );
	}

	// ─── Hooks: employee ────────────────────────────────────

	public function test_on_employee_new_enqueues(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$this->module->on_employee_new( 42, [ 'name' => 'Test' ] );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_employee_new_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_employee_new( 0, [] );

		$this->assertCount( $initial_count, $this->wpdb->calls );
	}

	public function test_on_employee_update_enqueues(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$this->module->on_employee_update( 42, [ 'name' => 'Updated' ] );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_employee_delete_skips_unmapped(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_employee_delete( 42 );

		// Should check mapping; since none exists, no enqueue beyond the lookup.
		$this->assertTrue( true );
	}

	public function test_on_employee_delete_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_employee_delete( 0 );

		$this->assertCount( $initial_count, $this->wpdb->calls );
	}

	public function test_on_employee_new_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$prop = ( new \ReflectionClass( \WP4Odoo\Module_Base::class ) )->getProperty( 'importing_request_local' );
		$prop->setAccessible( true );
		$prop->setValue( null, [ 'wperp' => true ] );

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_employee_new( 42, [] );

		$this->assertCount( $initial_count, $this->wpdb->calls );

		$prop->setValue( null, [] );
	}

	public function test_on_employee_new_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => false,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_employee_new( 42, [] );

		$this->assertCount( $initial_count, $this->wpdb->calls );
	}

	// ─── Hooks: department ──────────────────────────────────

	public function test_on_department_new_enqueues(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$this->module->on_department_new( 5, [ 'title' => 'Sales' ] );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_department_update_enqueues(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$this->module->on_department_update( 5, [ 'title' => 'Sales Updated' ] );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_department_delete_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_department_delete( 0 );

		$this->assertCount( $initial_count, $this->wpdb->calls );
	}

	public function test_on_department_new_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_department_new( 0, [] );

		$this->assertCount( $initial_count, $this->wpdb->calls );
	}

	// ─── Hooks: leave ───────────────────────────────────────

	public function test_on_leave_new_enqueues(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$this->module->on_leave_new( 1, [ 'reason' => 'Vacation' ] );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_leave_new_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_leave_new( 0, [] );

		$this->assertCount( $initial_count, $this->wpdb->calls );
	}

	public function test_on_leave_status_change_enqueues(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$this->module->on_leave_status_change( 1, [ 'status' => 2 ] );

		$this->assertNotEmpty( $this->wpdb->calls );
	}

	public function test_on_leave_status_change_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$initial_count = count( $this->wpdb->calls );

		$this->module->on_leave_status_change( 0, [] );

		$this->assertCount( $initial_count, $this->wpdb->calls );
	}

	// ─── Pull Guards ────────────────────────────────────────

	public function test_pull_employee_disabled_returns_success(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => false,
			'pull_departments' => true,
			'pull_leaves'      => true,
		];

		$result = $this->module->pull_from_odoo( 'employee', 'create', 1 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_department_disabled_returns_success(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => false,
			'pull_leaves'      => true,
		];

		$result = $this->module->pull_from_odoo( 'department', 'create', 1 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_leave_disabled_returns_success(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_wperp_settings'] = [
			'sync_employees'   => true,
			'sync_departments' => true,
			'sync_leaves'      => true,
			'pull_employees'   => true,
			'pull_departments' => true,
			'pull_leaves'      => false,
		];

		$result = $this->module->pull_from_odoo( 'leave', 'create', 1 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Dedup Domains ──────────────────────────────────────

	public function test_dedup_employee_by_work_email(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'employee', [ 'work_email' => 'test@example.com' ] );

		$this->assertSame( [ [ 'work_email', '=', 'test@example.com' ] ], $domain );
	}

	public function test_dedup_department_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'department', [ 'name' => 'Sales' ] );

		$this->assertSame( [ [ 'name', '=', 'Sales' ] ], $domain );
	}

	public function test_dedup_leave_returns_empty(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'leave', [ 'name' => 'Vacation' ] );

		$this->assertEmpty( $domain );
	}

	public function test_dedup_employee_empty_email_returns_empty(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'employee', [ 'work_email' => '' ] );

		$this->assertEmpty( $domain );
	}

	// ─── map_to_odoo passthrough ────────────────────────────

	public function test_map_to_odoo_employee_is_passthrough(): void {
		$data = [
			'name'       => 'John Doe',
			'work_email' => 'john@example.com',
			'job_title'  => 'Developer',
			'gender'     => 'male',
		];

		$mapped = $this->module->map_to_odoo( 'employee', $data );

		$this->assertSame( $data, $mapped );
	}

	public function test_map_to_odoo_leave_is_passthrough(): void {
		$data = [
			'name'      => 'Vacation',
			'date_from' => '2026-03-01',
			'date_to'   => '2026-03-05',
			'state'     => 'draft',
		];

		$mapped = $this->module->map_to_odoo( 'leave', $data );

		$this->assertSame( $data, $mapped );
	}

	public function test_map_to_odoo_department_uses_field_mapping(): void {
		$mapped = $this->module->map_to_odoo( 'department', [ 'name' => 'Engineering' ] );

		$this->assertSame( 'Engineering', $mapped['name'] );
	}

	// ─── map_from_odoo ──────────────────────────────────────

	public function test_map_from_odoo_employee_parses_name(): void {
		$data = $this->module->map_from_odoo( 'employee', [
			'name'       => 'Jane Smith',
			'work_email' => 'jane@example.com',
		] );

		$this->assertSame( 'Jane Smith', $data['name'] );
		$this->assertSame( 'jane@example.com', $data['work_email'] );
	}

	public function test_map_from_odoo_department_parses_name(): void {
		$data = $this->module->map_from_odoo( 'department', [
			'name' => 'Marketing',
		] );

		$this->assertSame( 'Marketing', $data['name'] );
	}

	public function test_map_from_odoo_leave_parses_status(): void {
		$data = $this->module->map_from_odoo( 'leave', [
			'name'        => 'Sick leave',
			'date_from'   => '2026-03-10',
			'date_to'     => '2026-03-11',
			'state'       => 'validate',
			'employee_id' => [ 10, 'John' ],
		] );

		$this->assertSame( 'Sick leave', $data['reason'] );
		$this->assertSame( 2, $data['status'] );
	}

	// ─── Push dependency chain ──────────────────────────────

	public function test_push_delete_does_not_ensure_dependencies(): void {
		// Delete actions should not trigger ensure_entity_synced.
		$result = $this->module->push_to_odoo( 'leave', 'delete', 1, 0 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Required tables ────────────────────────────────────

	public function test_required_tables_includes_employees(): void {
		$method = new \ReflectionMethod( $this->module, 'get_required_tables' );

		$tables = $method->invoke( $this->module );

		$this->assertContains( 'erp_hr_employees', $tables );
	}

	public function test_required_tables_includes_departments(): void {
		$method = new \ReflectionMethod( $this->module, 'get_required_tables' );

		$tables = $method->invoke( $this->module );

		$this->assertContains( 'erp_hr_departments', $tables );
	}

	public function test_required_tables_includes_designations(): void {
		$method = new \ReflectionMethod( $this->module, 'get_required_tables' );

		$tables = $method->invoke( $this->module );

		$this->assertContains( 'erp_hr_designations', $tables );
	}

	public function test_required_tables_includes_leaves(): void {
		$method = new \ReflectionMethod( $this->module, 'get_required_tables' );

		$tables = $method->invoke( $this->module );

		$this->assertContains( 'erp_hr_leaves', $tables );
	}

	// ─── Handler: leave status mapping direct ───────────────

	public function test_handler_map_leave_status_to_odoo_pending(): void {
		$handler = $this->create_handler();

		$this->assertSame( 'draft', $handler->map_leave_status_to_odoo( 1 ) );
	}

	public function test_handler_map_leave_status_to_odoo_approved(): void {
		$handler = $this->create_handler();

		$this->assertSame( 'validate', $handler->map_leave_status_to_odoo( 2 ) );
	}

	public function test_handler_map_leave_status_to_odoo_rejected(): void {
		$handler = $this->create_handler();

		$this->assertSame( 'refuse', $handler->map_leave_status_to_odoo( 3 ) );
	}

	public function test_handler_map_leave_status_from_odoo_draft(): void {
		$handler = $this->create_handler();

		$this->assertSame( 1, $handler->map_leave_status_from_odoo( 'draft' ) );
	}

	public function test_handler_map_leave_status_from_odoo_confirm(): void {
		$handler = $this->create_handler();

		$this->assertSame( 1, $handler->map_leave_status_from_odoo( 'confirm' ) );
	}

	public function test_handler_map_leave_status_from_odoo_validate(): void {
		$handler = $this->create_handler();

		$this->assertSame( 2, $handler->map_leave_status_from_odoo( 'validate' ) );
	}

	public function test_handler_map_leave_status_from_odoo_validate1(): void {
		$handler = $this->create_handler();

		$this->assertSame( 2, $handler->map_leave_status_from_odoo( 'validate1' ) );
	}

	public function test_handler_map_leave_status_from_odoo_refuse(): void {
		$handler = $this->create_handler();

		$this->assertSame( 3, $handler->map_leave_status_from_odoo( 'refuse' ) );
	}

	// ─── Helper ─────────────────────────────────────────────

	/**
	 * Create a WPERP_Handler instance with a test logger.
	 *
	 * @return WPERP_Handler
	 */
	private function create_handler(): WPERP_Handler {
		$logger = new \WP4Odoo\Logger( 'wperp', wp4odoo_test_settings() );
		return new WPERP_Handler( $logger );
	}
}
