<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Field_Service_Module;
use WP4Odoo\Modules\Field_Service_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Field_Service_Module, Field_Service_Handler, and Field_Service_Hooks.
 */
class FieldServiceModuleTest extends TestCase {

	private Field_Service_Module $module;
	private Field_Service_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new Field_Service_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new Field_Service_Handler( new Logger( 'field_service', wp4odoo_test_settings() ) );
	}

	protected function tearDown(): void {
		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [] );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id(): void {
		$this->assertSame( 'field_service', $this->module->get_id() );
	}

	public function test_module_name(): void {
		$this->assertSame( 'Field Service', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_task_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'field_service.task', $models['task'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 1, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_tasks(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_tasks'] );
	}

	public function test_default_settings_has_pull_tasks(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_tasks'] );
	}

	public function test_default_settings_has_exactly_two_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 2, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_sync_tasks_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_tasks']['type'] );
	}

	public function test_settings_fields_pull_tasks_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['pull_tasks']['type'] );
	}

	public function test_settings_fields_count(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 2, $fields );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_always_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_no_notices(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot ──────────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── CPT Constant ──────────────────────────────────────

	public function test_cpt_constant_value(): void {
		$this->assertSame( 'wp4odoo_fs_task', Field_Service_Module::CPT );
	}

	// ─── Handler: load_task ────────────────────────────────

	public function test_load_task_returns_data_for_valid_post(): void {
		$this->create_task( 10, 'Fix boiler', '<p>Replace valve</p>', 'publish' );

		$data = $this->handler->load_task( 10 );

		$this->assertSame( 'Fix boiler', $data['name'] );
		$this->assertSame( '<p>Replace valve</p>', $data['description'] );
	}

	public function test_load_task_returns_empty_for_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][10] = (object) [
			'ID'           => 10,
			'post_type'    => 'post',
			'post_title'   => 'Not a task',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_parent'  => 0,
			'menu_order'   => 0,
		];

		$data = $this->handler->load_task( 10 );

		$this->assertSame( [], $data );
	}

	public function test_load_task_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_task( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_task_maps_publish_to_in_progress(): void {
		$this->create_task( 10, 'Task', '', 'publish' );

		$data = $this->handler->load_task( 10 );

		$this->assertSame( 'In Progress', $data['stage_name'] );
	}

	public function test_load_task_maps_draft_to_new(): void {
		$this->create_task( 10, 'Task', '', 'draft' );

		$data = $this->handler->load_task( 10 );

		$this->assertSame( 'New', $data['stage_name'] );
	}

	public function test_load_task_maps_private_to_done(): void {
		$this->create_task( 10, 'Task', '', 'private' );

		$data = $this->handler->load_task( 10 );

		$this->assertSame( 'Done', $data['stage_name'] );
	}

	public function test_load_task_includes_planned_date(): void {
		$this->create_task( 10, 'Task', '', 'draft' );
		$GLOBALS['_wp_post_meta'][10]['_fs_planned_date'] = '2026-03-01 09:00:00';

		$data = $this->handler->load_task( 10 );

		$this->assertSame( '2026-03-01 09:00:00', $data['planned_date_begin'] );
	}

	public function test_load_task_includes_deadline(): void {
		$this->create_task( 10, 'Task', '', 'draft' );
		$GLOBALS['_wp_post_meta'][10]['_fs_date_deadline'] = '2026-03-15';

		$data = $this->handler->load_task( 10 );

		$this->assertSame( '2026-03-15', $data['date_deadline'] );
	}

	public function test_load_task_includes_priority(): void {
		$this->create_task( 10, 'Task', '', 'draft' );
		$GLOBALS['_wp_post_meta'][10]['_fs_priority'] = '1';

		$data = $this->handler->load_task( 10 );

		$this->assertSame( '1', $data['priority'] );
	}

	public function test_load_task_default_priority_is_zero(): void {
		$this->create_task( 10, 'Task', '', 'draft' );

		$data = $this->handler->load_task( 10 );

		$this->assertSame( '0', $data['priority'] );
	}

	// ─── Handler: parse_task_from_odoo ─────────────────────

	public function test_parse_task_maps_name(): void {
		$data = $this->handler->parse_task_from_odoo( [ 'name' => 'Repair AC' ] );
		$this->assertSame( 'Repair AC', $data['post_title'] );
	}

	public function test_parse_task_maps_description(): void {
		$data = $this->handler->parse_task_from_odoo( [ 'description' => '<p>Details</p>' ] );
		$this->assertSame( '<p>Details</p>', $data['post_content'] );
	}

	public function test_parse_task_maps_planned_date(): void {
		$data = $this->handler->parse_task_from_odoo( [ 'planned_date_begin' => '2026-04-01 08:00:00' ] );
		$this->assertSame( '2026-04-01 08:00:00', $data['planned_date'] );
	}

	public function test_parse_task_maps_deadline(): void {
		$data = $this->handler->parse_task_from_odoo( [ 'date_deadline' => '2026-04-10' ] );
		$this->assertSame( '2026-04-10', $data['date_deadline'] );
	}

	public function test_parse_task_maps_priority(): void {
		$data = $this->handler->parse_task_from_odoo( [ 'priority' => '2' ] );
		$this->assertSame( '2', $data['priority'] );
	}

	public function test_parse_task_maps_stage_in_progress_to_publish(): void {
		$data = $this->handler->parse_task_from_odoo( [
			'stage_id' => [ 1, 'In Progress' ],
		] );
		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_parse_task_maps_stage_new_to_draft(): void {
		$data = $this->handler->parse_task_from_odoo( [
			'stage_id' => [ 2, 'New' ],
		] );
		$this->assertSame( 'draft', $data['post_status'] );
	}

	public function test_parse_task_maps_stage_done_to_private(): void {
		$data = $this->handler->parse_task_from_odoo( [
			'stage_id' => [ 3, 'Done' ],
		] );
		$this->assertSame( 'private', $data['post_status'] );
	}

	public function test_parse_task_maps_stage_cancelled_to_trash(): void {
		$data = $this->handler->parse_task_from_odoo( [
			'stage_id' => [ 4, 'Cancelled' ],
		] );
		$this->assertSame( 'trash', $data['post_status'] );
	}

	public function test_parse_task_handles_partner_many2one(): void {
		$data = $this->handler->parse_task_from_odoo( [
			'partner_id' => [ 42, 'John Doe' ],
		] );
		$this->assertSame( 42, $data['partner_odoo_id'] );
	}

	public function test_parse_task_handles_false_partner(): void {
		$data = $this->handler->parse_task_from_odoo( [
			'name'       => 'Unassigned',
			'partner_id' => false,
		] );
		$this->assertArrayNotHasKey( 'partner_odoo_id', $data );
	}

	public function test_parse_task_handles_empty_data(): void {
		$data = $this->handler->parse_task_from_odoo( [] );
		$this->assertSame( [], $data );
	}

	// ─── Handler: save_task ───────────────────────────────

	public function test_save_task_creates_new_post(): void {
		$result = $this->handler->save_task( [
			'post_title'   => 'New Task',
			'post_content' => '<p>Description</p>',
			'post_status'  => 'draft',
		] );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_task_updates_existing_post(): void {
		$result = $this->handler->save_task( [
			'post_title' => 'Updated Task',
		], 42 );

		$this->assertSame( 42, $result );
	}

	public function test_save_task_stores_planned_date_meta(): void {
		$result = $this->handler->save_task( [
			'post_title'   => 'Dated Task',
			'planned_date' => '2026-05-01 10:00:00',
		] );

		$this->assertGreaterThan( 0, $result );
		$this->assertSame( '2026-05-01 10:00:00', get_post_meta( $result, '_fs_planned_date', true ) );
	}

	public function test_save_task_stores_deadline_meta(): void {
		$result = $this->handler->save_task( [
			'post_title'    => 'Deadline Task',
			'date_deadline' => '2026-05-15',
		] );

		$this->assertGreaterThan( 0, $result );
		$this->assertSame( '2026-05-15', get_post_meta( $result, '_fs_date_deadline', true ) );
	}

	public function test_save_task_stores_priority_meta(): void {
		$result = $this->handler->save_task( [
			'post_title' => 'Priority Task',
			'priority'   => '1',
		] );

		$this->assertGreaterThan( 0, $result );
		$this->assertSame( '1', get_post_meta( $result, '_fs_priority', true ) );
	}

	public function test_save_task_defaults_title_when_missing(): void {
		$result = $this->handler->save_task( [
			'post_status' => 'draft',
		] );

		$this->assertGreaterThan( 0, $result );
	}

	// ─── Hooks: on_task_save ──────────────────────────────

	public function test_on_task_save_enqueues_create(): void {
		$this->create_task( 100, 'Install sensor', 'Details', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_field_service_settings'] = [ 'sync_tasks' => true ];

		$this->module->on_task_save( 100 );

		$this->assertQueueContains( 'field_service', 'task', 'create', 100 );
	}

	public function test_on_task_save_skips_when_disabled(): void {
		$this->create_task( 100, 'Task', '', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_field_service_settings'] = [ 'sync_tasks' => false ];

		$this->module->on_task_save( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_task_save_skips_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'           => 100,
			'post_type'    => 'post',
			'post_title'   => 'Not a task',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_parent'  => 0,
			'menu_order'   => 0,
		];
		$GLOBALS['_wp_options']['wp4odoo_module_field_service_settings'] = [ 'sync_tasks' => true ];

		$this->module->on_task_save( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_task_save_skips_when_importing(): void {
		$this->create_task( 100, 'Task', '', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_field_service_settings'] = [ 'sync_tasks' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'field_service' => true ] );

		$this->module->on_task_save( 100 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_task_delete ─────────────────────────────

	public function test_on_task_delete_enqueues_when_mapped(): void {
		$this->create_task( 100, 'To Delete', '', 'publish' );

		$this->module->save_mapping( 'task', 100, 555 );

		$this->module->on_task_delete( 100 );

		$this->assertQueueContains( 'field_service', 'task', 'delete', 100 );
	}

	public function test_on_task_delete_skips_when_no_mapping(): void {
		$this->create_task( 100, 'Unmapped', '', 'publish' );

		$this->module->on_task_delete( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_task_delete_skips_wrong_post_type(): void {
		$GLOBALS['_wp_posts'][100] = (object) [
			'ID'           => 100,
			'post_type'    => 'post',
			'post_title'   => 'Not a task',
			'post_content' => '',
			'post_status'  => 'publish',
			'post_parent'  => 0,
			'menu_order'   => 0,
		];

		$this->module->on_task_delete( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_task_delete_skips_when_importing(): void {
		$this->create_task( 100, 'Task', '', 'publish' );
		$this->module->save_mapping( 'task', 100, 555 );

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'field_service' => true ] );

		$this->module->on_task_delete( 100 );

		$this->assertQueueEmpty();
	}

	// ─── Pull guard ────────────────────────────────────────

	public function test_pull_skips_when_pull_tasks_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_field_service_settings'] = [ 'pull_tasks' => false ];

		$result = $this->module->pull_from_odoo( 'task', 'create', 42 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Push guard ────────────────────────────────────────

	public function test_push_skips_when_model_unavailable(): void {
		$GLOBALS['_wp_transients']['wp4odoo_has_field_service_task'] = 0;

		$result = $this->module->push_to_odoo( 'task', 'create', 10 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Dedup Domain ──────────────────────────────────────

	public function test_dedup_domain_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'task', [ 'name' => 'Install Pump' ] );

		$this->assertSame( [ [ 'name', '=', 'Install Pump' ] ], $domain );
	}

	public function test_dedup_domain_empty_without_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'task', [] );

		$this->assertSame( [], $domain );
	}

	// ─── map_to_odoo identity ──────────────────────────────

	public function test_map_to_odoo_returns_input(): void {
		$data = [
			'name'       => 'Test Task',
			'partner_id' => 42,
			'priority'   => '1',
		];

		$result = $this->module->map_to_odoo( 'task', $data );
		$this->assertSame( $data, $result );
	}

	// ─── map_from_odoo delegates to handler ───────────────

	public function test_map_from_odoo_task(): void {
		$result = $this->module->map_from_odoo( 'task', [
			'name'        => 'Odoo Task',
			'description' => '<p>Details</p>',
			'stage_id'    => [ 1, 'In Progress' ],
		] );

		$this->assertSame( 'Odoo Task', $result['post_title'] );
		$this->assertSame( '<p>Details</p>', $result['post_content'] );
		$this->assertSame( 'publish', $result['post_status'] );
	}

	// ─── save_wp_data ──────────────────────────────────────

	public function test_save_wp_data_creates_task(): void {
		$method = new \ReflectionMethod( $this->module, 'save_wp_data' );

		$result = $method->invoke( $this->module, 'task', [
			'post_title'  => 'New Task',
			'post_status' => 'draft',
		] );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_wp_data_returns_zero_for_unsupported_entity(): void {
		$method = new \ReflectionMethod( $this->module, 'save_wp_data' );

		$result = $method->invoke( $this->module, 'unknown', [ 'post_title' => 'Test' ] );

		$this->assertSame( 0, $result );
	}

	// ─── delete_wp_data ────────────────────────────────────

	public function test_delete_wp_data_returns_false_for_unsupported_entity(): void {
		$method = new \ReflectionMethod( $this->module, 'delete_wp_data' );

		$result = $method->invoke( $this->module, 'unknown', 1 );

		$this->assertFalse( $result );
	}

	// ─── load_wp_data ──────────────────────────────────────

	public function test_load_wp_data_returns_empty_for_unsupported_entity(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );

		$data = $method->invoke( $this->module, 'unknown', 1 );

		$this->assertSame( [], $data );
	}

	public function test_load_wp_data_returns_task_data(): void {
		$this->create_task( 10, 'Fix heater', '<p>Replace element</p>', 'draft' );

		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );

		$data = $method->invoke( $this->module, 'task', 10 );

		$this->assertSame( 'Fix heater', $data['name'] );
		$this->assertSame( '<p>Replace element</p>', $data['description'] );
	}

	// ─── Helpers ───────────────────────────────────────────

	private function create_task( int $id, string $title, string $content = '', string $status = 'draft' ): void {
		$GLOBALS['_wp_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => 'wp4odoo_fs_task',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_parent'  => 0,
			'menu_order'   => 0,
		];
	}

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
