<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\Project_Manager_Handler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Project_Manager_Handler.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Project_Manager_Handler::class)]
class ProjectManagerHandlerTest extends TestCase {

	private Project_Manager_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->handler = new Project_Manager_Handler( new Logger( 'test' ) );
	}

	// ─── load_project ─────────────────────────────────────

	public function test_load_project_returns_data(): void {
		$GLOBALS['_wp_posts'][1] = (object) [
			'ID'           => 1,
			'post_title'   => 'Website Redesign',
			'post_content' => 'Complete overhaul',
			'post_status'  => 'publish',
			'post_type'    => 'cpm_project',
		];

		$data = $this->handler->load_project( 1 );

		$this->assertSame( 'Website Redesign', $data['title'] );
		$this->assertSame( 'Complete overhaul', $data['description'] );
		$this->assertSame( 'publish', $data['status'] );
	}

	public function test_load_project_empty_for_nonexistent(): void {
		$data = $this->handler->load_project( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_project_empty_for_wrong_cpt(): void {
		$GLOBALS['_wp_posts'][2] = (object) [
			'ID'           => 2,
			'post_title'   => 'Not a project',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'post',
		];

		$data = $this->handler->load_project( 2 );
		$this->assertEmpty( $data );
	}

	// ─── parse_project_from_odoo ──────────────────────────

	public function test_parse_project_from_odoo_maps_fields(): void {
		$data = $this->handler->parse_project_from_odoo( [
			'name'        => 'Odoo Project',
			'description' => '<p>HTML desc</p>',
		] );

		$this->assertSame( 'Odoo Project', $data['title'] );
		$this->assertSame( 'HTML desc', $data['description'] );
	}

	public function test_parse_project_from_odoo_handles_missing_fields(): void {
		$data = $this->handler->parse_project_from_odoo( [] );

		$this->assertSame( '', $data['title'] );
		$this->assertSame( '', $data['description'] );
	}

	// ─── save_project ─────────────────────────────────────

	public function test_save_project_creates_new(): void {
		$id = $this->handler->save_project( [
			'title'       => 'New Project',
			'description' => 'Desc',
		], 0 );

		$this->assertGreaterThan( 0, $id );
	}

	public function test_save_project_updates_existing(): void {
		$GLOBALS['_wp_posts'][5] = (object) [
			'ID'           => 5,
			'post_title'   => 'Old',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_type'    => 'cpm_project',
		];

		$id = $this->handler->save_project( [
			'title'       => 'Updated',
			'description' => 'New desc',
		], 5 );

		$this->assertSame( 5, $id );
	}

	// ─── load_task ────────────────────────────────────────

	public function test_load_task_returns_data(): void {
		$this->wpdb->get_row_return = [
			'id'          => '10',
			'title'       => 'Design mockup',
			'description' => 'Create wireframes',
			'estimation'  => '7200',
			'start_at'    => '2025-06-01',
			'due_date'    => '2025-06-15',
			'status'      => '0',
			'project_id'  => '1',
		];

		$data = $this->handler->load_task( 10 );

		$this->assertSame( 10, $data['task_id'] );
		$this->assertSame( 'Design mockup', $data['title'] );
		$this->assertSame( 1, $data['project_id'] );
		$this->assertSame( 7200.0, $data['estimation'] );
	}

	public function test_load_task_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_task( 999 );
		$this->assertEmpty( $data );
	}

	// ─── parse_task_from_odoo ─────────────────────────────

	public function test_parse_task_from_odoo_maps_fields(): void {
		$data = $this->handler->parse_task_from_odoo( [
			'name'          => 'Code review',
			'description'   => '<p>Review PR</p>',
			'date_deadline' => '2025-06-20',
		] );

		$this->assertSame( 'Code review', $data['title'] );
		$this->assertSame( 'Review PR', $data['description'] );
		$this->assertSame( '2025-06-20', $data['due_date'] );
	}

	// ─── save_task ────────────────────────────────────────

	public function test_save_task_creates_new(): void {
		$this->wpdb->insert_id = 20;

		$id = $this->handler->save_task( [
			'title'       => 'New Task',
			'description' => 'Do stuff',
			'project_id'  => 1,
		], 0 );

		$this->assertSame( 20, $id );
	}

	public function test_save_task_updates_existing(): void {
		$id = $this->handler->save_task( [
			'title'       => 'Updated Task',
			'description' => 'Updated desc',
		], 10 );

		$this->assertSame( 10, $id );
	}

	// ─── get_project_id_for_task ──────────────────────────

	public function test_get_project_id_for_task_found(): void {
		$this->wpdb->get_var_return = '1';

		$this->assertSame( 1, $this->handler->get_project_id_for_task( 10 ) );
	}

	public function test_get_project_id_for_task_not_found(): void {
		$this->wpdb->get_var_return = null;

		$this->assertSame( 0, $this->handler->get_project_id_for_task( 999 ) );
	}

	// ─── load_timesheet ───────────────────────────────────

	public function test_load_timesheet_returns_data(): void {
		$this->wpdb->get_row_return = [
			'id'      => '100',
			'task_id' => '10',
			'user_id' => '1',
			'start'   => '2025-06-15 09:00:00',
			'stop'    => '2025-06-15 11:30:00',
			'total'   => '9000',
		];

		$data = $this->handler->load_timesheet( 100 );

		$this->assertSame( 100, $data['entry_id'] );
		$this->assertSame( 10, $data['task_id'] );
		$this->assertSame( 1, $data['user_id'] );
		$this->assertSame( 9000.0, $data['total'] );
	}

	public function test_load_timesheet_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_timesheet( 999 );
		$this->assertEmpty( $data );
	}

	// ─── format_timesheet ─────────────────────────────────

	public function test_format_timesheet_outputs_analytic_line(): void {
		$data = $this->handler->format_timesheet(
			[
				'total'     => 2.5,
				'start'     => '2025-06-15 09:00:00',
				'task_name' => 'Design mockup',
			],
			5,
			1,
			3
		);

		$this->assertSame( 'Design mockup', $data['name'] );
		$this->assertSame( 1, $data['project_id'] );
		$this->assertSame( 5, $data['task_id'] );
		$this->assertSame( 3, $data['employee_id'] );
		$this->assertSame( 2.5, $data['unit_amount'] );
		$this->assertSame( '2025-06-15', $data['date'] );
	}

	public function test_format_timesheet_converts_seconds_to_hours(): void {
		$data = $this->handler->format_timesheet(
			[
				'total'     => 7200,
				'start'     => '2025-06-15 09:00:00',
				'task_name' => 'Coding',
			],
			5,
			1,
			3
		);

		$this->assertSame( 2.0, $data['unit_amount'] );
	}

	// ─── delete_task ──────────────────────────────────────

	public function test_delete_task_returns_true(): void {
		$result = $this->handler->delete_task( 10 );
		$this->assertTrue( $result );
	}

	// ─── delete_project ───────────────────────────────────

	public function test_delete_project_returns_true(): void {
		$GLOBALS['_wp_posts'][1] = (object) [
			'ID'          => 1,
			'post_title'  => 'To Delete',
			'post_status' => 'publish',
			'post_type'   => 'cpm_project',
		];

		$result = $this->handler->delete_project( 1 );
		$this->assertTrue( $result );
	}
}
