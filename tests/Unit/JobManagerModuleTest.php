<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\Modules\Job_Manager_Module;

/**
 * @covers \WP4Odoo\Modules\Job_Manager_Module
 */
class JobManagerModuleTest extends TestCase {

	private Job_Manager_Module $module;

	protected function setUp(): void {
		$this->module = new Job_Manager_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Identity ───────────────────────────────────────────

	public function test_module_id_is_job_manager(): void {
		$ref = new \ReflectionProperty( $this->module, 'id' );
		$this->assertSame( 'job_manager', $ref->getValue( $this->module ) );
	}

	public function test_module_name_is_wp_job_manager(): void {
		$ref = new \ReflectionProperty( $this->module, 'name' );
		$this->assertSame( 'WP Job Manager', $ref->getValue( $this->module ) );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_exclusive_priority_is_zero(): void {
		$this->assertSame( 0, $this->module->get_exclusive_priority() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ────────────────────────────────────────

	public function test_declares_job_model(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertSame( 'hr.job', $ref->getValue( $this->module )['job'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$ref = new \ReflectionProperty( $this->module, 'odoo_models' );
		$this->assertCount( 1, $ref->getValue( $this->module ) );
	}

	// ─── Default Settings ───────────────────────────────────

	public function test_sync_jobs_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['sync_jobs'] );
	}

	public function test_pull_jobs_enabled_by_default(): void {
		$this->assertTrue( $this->module->get_default_settings()['pull_jobs'] );
	}

	public function test_default_settings_count(): void {
		$this->assertCount( 2, $this->module->get_default_settings() );
	}

	// ─── Settings Fields ────────────────────────────────────

	public function test_settings_fields_count(): void {
		$this->assertCount( 2, $this->module->get_settings_fields() );
	}

	public function test_settings_fields_sync_jobs_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['sync_jobs']['type'] );
	}

	public function test_settings_fields_pull_jobs_is_checkbox(): void {
		$this->assertSame( 'checkbox', $this->module->get_settings_fields()['pull_jobs']['type'] );
	}

	public function test_settings_fields_have_labels(): void {
		$fields = $this->module->get_settings_fields();
		foreach ( $fields as $field ) {
			$this->assertNotEmpty( $field['label'] );
		}
	}

	// ─── Field Mappings ─────────────────────────────────────

	public function test_job_mapping_includes_name(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'name', $ref->getValue( $this->module )['job']['name'] );
	}

	public function test_job_mapping_includes_description(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'description', $ref->getValue( $this->module )['job']['description'] );
	}

	public function test_job_mapping_includes_state(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'state', $ref->getValue( $this->module )['job']['state'] );
	}

	public function test_job_mapping_includes_no_of_recruitment(): void {
		$ref = new \ReflectionProperty( $this->module, 'default_mappings' );
		$this->assertSame( 'no_of_recruitment', $ref->getValue( $this->module )['job']['no_of_recruitment'] );
	}

	// ─── Dependency Status ──────────────────────────────────

	public function test_dependency_available_with_job_manager(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_empty_notices_when_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── map_to_odoo passthrough ────────────────────────────

	public function test_map_to_odoo_job_is_passthrough(): void {
		$data = [
			'name'              => 'PHP Developer',
			'description'       => 'A great job.',
			'state'             => 'recruit',
			'no_of_recruitment' => 1,
		];

		$mapped = $this->module->map_to_odoo( 'job', $data );

		$this->assertSame( $data, $mapped );
	}

	// ─── map_from_odoo ──────────────────────────────────────

	public function test_map_from_odoo_job_parses_name(): void {
		$data = $this->module->map_from_odoo( 'job', [
			'name'  => 'Frontend Developer',
			'state' => 'recruit',
		] );

		$this->assertSame( 'Frontend Developer', $data['name'] );
	}

	public function test_map_from_odoo_job_parses_state_recruit(): void {
		$data = $this->module->map_from_odoo( 'job', [ 'state' => 'recruit' ] );

		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_map_from_odoo_job_parses_state_open(): void {
		$data = $this->module->map_from_odoo( 'job', [ 'state' => 'open' ] );

		$this->assertSame( 'expired', $data['post_status'] );
	}

	public function test_map_from_odoo_job_parses_department_name(): void {
		$data = $this->module->map_from_odoo( 'job', [
			'department_id' => [ 3, 'Sales' ],
		] );

		$this->assertSame( 'Sales', $data['department_name'] );
	}

	// ─── Pull: delete_wp_data ───────────────────────────────

	public function test_pull_job_delete_removes_post(): void {
		// Create a job_listing post in the global store.
		$post              = new \stdClass();
		$post->ID          = 42;
		$post->post_title  = 'To Delete';
		$post->post_type   = 'job_listing';
		$post->post_status = 'publish';
		$post->post_date   = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][42] = $post;

		$ref    = new \ReflectionMethod( $this->module, 'delete_wp_data' );
		$result = $ref->invoke( $this->module, 'job', 42 );

		$this->assertTrue( $result );
	}

	// ─── Boot Guard ─────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Translatable Fields ──────────────────────────────

	public function test_translatable_fields_for_job(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$fields = $method->invoke( $this->module, 'job' );

		$this->assertSame(
			[ 'name' => 'post_title', 'description' => 'post_content' ],
			$fields
		);
	}

	public function test_translatable_fields_empty_for_unknown_type(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'unknown' ) );
	}
}
