<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\LearnPress_Module;
use WP4Odoo\Modules\LearnPress_Handler;
use WP4Odoo\Logger;

class LearnPressModuleTest extends LMSModuleTestBase {

	private LearnPress_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_users']     = [];
		$GLOBALS['_wp_user_meta'] = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new LearnPress_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new LearnPress_Handler( new Logger( 'learnpress', wp4odoo_test_settings() ) );
	}

	protected function get_module_id(): string {
		return 'learnpress';
	}

	protected function get_module_name(): string {
		return 'LearnPress';
	}

	protected function get_order_entity(): string {
		return 'order';
	}

	protected function get_sync_order_key(): string {
		return 'sync_orders';
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_pull_courses(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_courses'] );
	}

	public function test_default_settings_has_exactly_five_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 5, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_pull_courses(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_courses', $fields );
		$this->assertSame( 'checkbox', $fields['pull_courses']['type'] );
	}

	public function test_settings_fields_count(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 5, $fields );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_available_with_learnpress(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Plugin Version ───────────────────────────────────

	public function test_plugin_version_returns_learnpress_version(): void {
		$method = new \ReflectionMethod( $this->module, 'get_plugin_version' );

		$version = $method->invoke( $this->module );
		$this->assertSame( LEARNPRESS_VERSION, $version );
	}

	// ─── Dedup: Course ────────────────────────────────────

	public function test_dedup_course_with_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'course', [ 'name' => 'PHP Basics' ] );
		$this->assertSame( [ [ 'name', '=', 'PHP Basics' ] ], $domain );
	}

	public function test_dedup_course_empty_without_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'course', [] );
		$this->assertSame( [], $domain );
	}

	// ─── Dedup: Order ─────────────────────────────────────

	public function test_dedup_order_with_ref(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'order', [ 'ref' => 'LP-ORDER-300' ] );
		$this->assertSame( [ [ 'ref', '=', 'LP-ORDER-300' ] ], $domain );
	}

	public function test_dedup_order_empty_without_ref(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'order', [] );
		$this->assertSame( [], $domain );
	}

	// ─── Dedup: Enrollment ────────────────────────────────

	public function test_dedup_enrollment_returns_empty(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'enrollment', [ 'partner_id' => 10 ] );
		$this->assertSame( [], $domain );
	}

	// ─── Pull: Course Respects Settings ──────────────────

	public function test_pull_course_skipped_when_pull_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'pull_courses' => false ];

		$result = $this->module->pull_from_odoo( 'course', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: delete ───────────────────────────────────

	public function test_pull_course_delete_removes_post(): void {
		$this->create_post( 50, 'lp_course', 'Course to delete' );

		$result = $this->module->pull_from_odoo( 'course', 'delete', 100, 50 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── map_from_odoo: Course ──────────────────────────

	public function test_map_from_odoo_course_delegates_to_handler(): void {
		$odoo_data = [
			'name'             => 'Pulled Course',
			'description_sale' => 'From Odoo',
			'list_price'       => 79.99,
		];

		$wp_data = $this->module->map_from_odoo( 'course', $odoo_data );

		$this->assertSame( 'Pulled Course', $wp_data['title'] );
		$this->assertSame( 'From Odoo', $wp_data['description'] );
		$this->assertSame( 79.99, $wp_data['list_price'] );
	}

	// ─── Translatable Fields ──────────────────────────────

	public function test_translatable_fields_for_course(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$fields = $method->invoke( $this->module, 'course' );

		$this->assertSame(
			[ 'name' => 'post_title', 'description_sale' => 'post_content' ],
			$fields
		);
	}

	public function test_translatable_fields_empty_for_order(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'order' ) );
	}

	public function test_translatable_fields_empty_for_enrollment(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'enrollment' ) );
	}

	// ─── Hooks: on_course_save ────────────────────────────

	public function test_on_course_save_enqueues_create(): void {
		$this->create_post( 100, 'lp_course', 'PHP Basics' );
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_courses' => true ];

		$this->module->on_course_save( 100 );

		$this->assertQueueContains( 'learnpress', 'course', 'create', 100 );
	}

	public function test_on_course_save_skips_when_importing(): void {
		$this->create_post( 100, 'lp_course', 'PHP Basics' );
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_courses' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'learnpress' => true ] );

		$this->module->on_course_save( 100 );

		$this->assertQueueEmpty();

		$prop->setValue( null, [] );
	}

	public function test_on_course_save_skips_wrong_post_type(): void {
		$this->create_post( 100, 'post', 'Not a course' );
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_courses' => true ];

		$this->module->on_course_save( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_course_save_skips_when_sync_disabled(): void {
		$this->create_post( 100, 'lp_course', 'PHP Basics' );
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_courses' => false ];

		$this->module->on_course_save( 100 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_order_completed ──────────────────────

	public function test_on_order_completed_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_orders' => true ];

		$this->module->on_order_completed( 300 );

		$this->assertQueueContains( 'learnpress', 'order', 'create', 300 );
	}

	public function test_on_order_completed_skips_when_sync_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_orders' => false ];

		$this->module->on_order_completed( 300 );

		$this->assertQueueEmpty();
	}

	public function test_on_order_completed_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_orders' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'learnpress' => true ] );

		$this->module->on_order_completed( 300 );

		$this->assertQueueEmpty();

		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_enrollment ────────────────────────────

	public function test_on_enrollment_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_enrollments' => true ];

		$this->module->on_enrollment( 400, 100, 5 );

		$expected_id = 5 * 1_000_000 + 100;
		$this->assertQueueContains( 'learnpress', 'enrollment', 'create', $expected_id );
	}

	public function test_on_enrollment_skips_when_sync_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_enrollments' => false ];

		$this->module->on_enrollment( 400, 100, 5 );

		$this->assertQueueEmpty();
	}

	public function test_on_enrollment_synthetic_id_is_correct(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_enrollments' => true ];

		$this->module->on_enrollment( 400, 350, 42 );

		$expected_id = 42 * 1_000_000 + 350;
		$this->assertQueueContains( 'learnpress', 'enrollment', 'create', $expected_id );
	}

	public function test_on_enrollment_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learnpress_settings'] = [ 'sync_enrollments' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'learnpress' => true ] );

		$this->module->on_enrollment( 400, 100, 5 );

		$this->assertQueueEmpty();

		$prop->setValue( null, [] );
	}
}
