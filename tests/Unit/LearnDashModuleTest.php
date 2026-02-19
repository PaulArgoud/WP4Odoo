<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\LearnDash_Module;
use WP4Odoo\Modules\LearnDash_Handler;
use WP4Odoo\Logger;

class LearnDashModuleTest extends LMSModuleTestBase {

	private LearnDash_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_learndash_prices'] = [];
		$GLOBALS['_wp_posts']         = [];
		$GLOBALS['_wp_post_meta']     = [];
		$GLOBALS['_wp_users']         = [];
		$GLOBALS['_wp_user_meta']     = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new LearnDash_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new LearnDash_Handler( new Logger( 'learndash', wp4odoo_test_settings() ) );
	}

	protected function get_module_id(): string {
		return 'learndash';
	}

	protected function get_module_name(): string {
		return 'LearnDash';
	}

	protected function get_order_entity(): string {
		return 'transaction';
	}

	protected function get_sync_order_key(): string {
		return 'sync_transactions';
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_group_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['group'] );
	}

	public function test_declares_exactly_four_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 4, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_groups(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_groups'] );
	}

	public function test_default_settings_has_exactly_seven_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 7, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_groups(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_groups', $fields );
		$this->assertSame( 'checkbox', $fields['sync_groups']['type'] );
	}

	// ─── Field Mappings: Group ─────────────────────────────

	public function test_group_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'group', [ 'title' => 'Premium Bundle' ] );
		$this->assertSame( 'Premium Bundle', $odoo['name'] );
	}

	public function test_group_mapping_includes_list_price(): void {
		$odoo = $this->module->map_to_odoo( 'group', [ 'list_price' => 199.0 ] );
		$this->assertSame( 199.0, $odoo['list_price'] );
	}

	// ─── Dependency Status ────────────────────────────────

	public function test_dependency_unavailable_without_learndash(): void {
		$status = $this->module->get_dependency_status();
		$this->assertFalse( $status['available'] );
	}

	public function test_dependency_has_warning_without_learndash(): void {
		$status = $this->module->get_dependency_status();
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
	}

	// ─── Handler: load_course ─────────────────────────────

	public function test_load_course_returns_data_for_valid_course(): void {
		$this->create_post( 100, 'sfwd-courses', 'PHP Basics', 'Learn PHP from scratch' );
		$GLOBALS['_learndash_prices'][100] = [ 'type' => 'paynow', 'price' => '49.99' ];

		$data = $this->handler->load_course( 100 );

		$this->assertSame( 'PHP Basics', $data['title'] );
		$this->assertSame( 49.99, $data['list_price'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_course_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_course( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_course_returns_empty_for_wrong_post_type(): void {
		$this->create_post( 100, 'post', 'Not a course' );

		$data = $this->handler->load_course( 100 );
		$this->assertSame( [], $data );
	}

	public function test_load_course_strips_html_from_description(): void {
		$this->create_post( 100, 'sfwd-courses', 'Test', '<p>Hello <strong>world</strong></p>' );

		$data = $this->handler->load_course( 100 );
		$this->assertSame( 'Hello world', $data['description'] );
	}

	// ─── Handler: load_group ──────────────────────────────

	public function test_load_group_returns_data_for_valid_group(): void {
		$this->create_post( 200, 'groups', 'Premium Bundle', 'All courses included' );
		$GLOBALS['_learndash_prices'][200] = [ 'type' => 'paynow', 'price' => '199.00' ];

		$data = $this->handler->load_group( 200 );

		$this->assertSame( 'Premium Bundle', $data['title'] );
		$this->assertSame( 199.0, $data['list_price'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_group_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_group( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_group_returns_empty_for_wrong_post_type(): void {
		$this->create_post( 200, 'sfwd-courses', 'Not a group' );

		$data = $this->handler->load_group( 200 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: load_transaction ────────────────────────

	public function test_load_transaction_returns_data_for_valid_transaction(): void {
		$this->create_post( 300, 'sfwd-transactions', 'Transaction', '', 1, '2026-01-15 10:00:00' );
		$GLOBALS['_wp_post_meta'][300] = [
			'course_id'            => [ '100' ],
			'user_id'              => [ '5' ],
			'mc_gross'             => [ '49.99' ],
			'ld_payment_processor' => [ 'stripe' ],
			'mc_currency'          => [ 'EUR' ],
		];

		$data = $this->handler->load_transaction( 300 );

		$this->assertSame( 100, $data['course_id'] );
		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 49.99, $data['amount'] );
		$this->assertSame( 'stripe', $data['gateway'] );
		$this->assertSame( 'EUR', $data['currency'] );
	}

	public function test_load_transaction_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_transaction( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_transaction_returns_empty_for_wrong_post_type(): void {
		$this->create_post( 300, 'post', 'Not a transaction' );

		$data = $this->handler->load_transaction( 300 );
		$this->assertSame( [], $data );
	}

	public function test_load_transaction_falls_back_to_stripe_price(): void {
		$this->create_post( 300, 'sfwd-transactions', 'Transaction' );
		$GLOBALS['_wp_post_meta'][300] = [
			'course_id'      => [ '100' ],
			'user_id'        => [ '5' ],
			'stripe_price'   => [ '29.99' ],
			'stripe_currency' => [ 'usd' ],
		];

		$data = $this->handler->load_transaction( 300 );

		$this->assertSame( 29.99, $data['amount'] );
		$this->assertSame( 'USD', $data['currency'] );
	}

	// ─── Handler: load_enrollment ─────────────────────────

	public function test_load_enrollment_returns_data_for_valid_enrollment(): void {
		$this->create_user( 5, 'student@example.com', 'John Student' );
		$this->create_post( 100, 'sfwd-courses', 'PHP Basics' );
		$GLOBALS['_wp_user_meta'][5] = [ 'ld_course_100_enrolled' => [ '1706745600' ] ];

		$data = $this->handler->load_enrollment( 5, 100 );

		$this->assertSame( 5, $data['user_id'] );
		$this->assertSame( 100, $data['course_id'] );
		$this->assertSame( 'student@example.com', $data['user_email'] );
		$this->assertSame( 'John Student', $data['user_name'] );
	}

	public function test_load_enrollment_returns_empty_for_missing_user(): void {
		$this->create_post( 100, 'sfwd-courses', 'PHP Basics' );

		$data = $this->handler->load_enrollment( 999, 100 );
		$this->assertSame( [], $data );
	}

	public function test_load_enrollment_returns_empty_for_missing_course(): void {
		$this->create_user( 5, 'student@example.com', 'John Student' );

		$data = $this->handler->load_enrollment( 5, 999 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: format_invoice ──────────────────────────

	public function test_format_invoice_returns_correct_structure(): void {
		$data = [
			'course_id'      => 100,
			'amount'         => 49.99,
			'created_at'     => '2026-01-15 10:00:00',
			'transaction_id' => 300,
		];

		$this->create_post( 100, 'sfwd-courses', 'PHP Basics' );

		$invoice = $this->handler->format_invoice( $data, 42, 10, false );

		$this->assertSame( 'out_invoice', $invoice['move_type'] );
		$this->assertSame( 10, $invoice['partner_id'] );
		$this->assertSame( '2026-01-15', $invoice['invoice_date'] );
		$this->assertSame( 'LD-TXN-300', $invoice['ref'] );
		$this->assertArrayNotHasKey( '_auto_validate', $invoice );
	}

	public function test_format_invoice_includes_line_items(): void {
		$data = [
			'course_id'      => 100,
			'amount'         => 49.99,
			'created_at'     => '2026-01-15 10:00:00',
			'transaction_id' => 300,
		];

		$this->create_post( 100, 'sfwd-courses', 'PHP Basics' );

		$invoice = $this->handler->format_invoice( $data, 42, 10, false );
		$line    = $invoice['invoice_line_ids'][0];

		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 42, $line[2]['product_id'] );
		$this->assertSame( 1, $line[2]['quantity'] );
		$this->assertSame( 49.99, $line[2]['price_unit'] );
		$this->assertSame( 'PHP Basics', $line[2]['name'] );
	}

	public function test_format_invoice_auto_post_flag(): void {
		$data = [
			'course_id'      => 100,
			'amount'         => 49.99,
			'created_at'     => '2026-01-15 10:00:00',
			'transaction_id' => 300,
		];

		$invoice = $this->handler->format_invoice( $data, 42, 10, true );
		$this->assertTrue( $invoice['_auto_validate'] );
	}

	// ─── Handler: format_sale_order ───────────────────────

	public function test_format_sale_order_returns_correct_structure(): void {
		$order = $this->handler->format_sale_order( 42, 10, '2026-01-15', 'PHP Basics' );

		$this->assertSame( 10, $order['partner_id'] );
		$this->assertSame( '2026-01-15', $order['date_order'] );
		$this->assertSame( 'sale', $order['state'] );
	}

	public function test_format_sale_order_includes_order_line(): void {
		$order = $this->handler->format_sale_order( 42, 10, '2026-01-15', 'PHP Basics' );
		$line  = $order['order_line'][0];

		$this->assertSame( 0, $line[0] );
		$this->assertSame( 0, $line[1] );
		$this->assertSame( 42, $line[2]['product_id'] );
		$this->assertSame( 1, $line[2]['quantity'] );
		$this->assertSame( 'PHP Basics', $line[2]['name'] );
	}

	public function test_format_sale_order_fallback_name(): void {
		$order = $this->handler->format_sale_order( 42, 10, '2026-01-15' );
		$line  = $order['order_line'][0];

		$this->assertSame( 'LearnDash enrollment', $line[2]['name'] );
	}

	// ─── Hooks: on_course_save ────────────────────────────

	public function test_on_course_save_enqueues_create(): void {
		$this->create_post( 100, 'sfwd-courses', 'PHP Basics' );
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_courses' => true ];

		$this->module->on_course_save( 100 );

		$this->assertQueueContains( 'learndash', 'course', 'create', 100 );
	}

	public function test_on_course_save_skips_when_importing(): void {
		$this->create_post( 100, 'sfwd-courses', 'PHP Basics' );
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_courses' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'learndash' => true ] );

		$this->module->on_course_save( 100 );

		$this->assertQueueEmpty();

		$prop->setValue( null, [] );
	}

	public function test_on_course_save_skips_wrong_post_type(): void {
		$this->create_post( 100, 'post', 'Not a course' );
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_courses' => true ];

		$this->module->on_course_save( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_course_save_skips_when_sync_disabled(): void {
		$this->create_post( 100, 'sfwd-courses', 'PHP Basics' );
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_courses' => false ];

		$this->module->on_course_save( 100 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_group_save ─────────────────────────────

	public function test_on_group_save_enqueues_create(): void {
		$this->create_post( 200, 'groups', 'Premium Bundle' );
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_groups' => true ];

		$this->module->on_group_save( 200 );

		$this->assertQueueContains( 'learndash', 'group', 'create', 200 );
	}

	public function test_on_group_save_skips_wrong_post_type(): void {
		$this->create_post( 200, 'post', 'Not a group' );
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_groups' => true ];

		$this->module->on_group_save( 200 );

		$this->assertQueueEmpty();
	}

	public function test_on_group_save_skips_when_sync_disabled(): void {
		$this->create_post( 200, 'groups', 'Premium Bundle' );
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_groups' => false ];

		$this->module->on_group_save( 200 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_transaction_created ────────────────────

	public function test_on_transaction_created_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_transactions' => true ];

		$this->module->on_transaction_created( 300 );

		$this->assertQueueContains( 'learndash', 'transaction', 'create', 300 );
	}

	public function test_on_transaction_created_skips_when_sync_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_transactions' => false ];

		$this->module->on_transaction_created( 300 );

		$this->assertQueueEmpty();
	}

	public function test_on_transaction_created_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_transactions' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'learndash' => true ] );

		$this->module->on_transaction_created( 300 );

		$this->assertQueueEmpty();

		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_enrollment_change ──────────────────────

	public function test_on_enrollment_change_enqueues_create_on_grant(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_enrollments' => true ];

		$this->module->on_enrollment_change( 5, 100, [ 100 ], false );

		$expected_id = 5 * 1_000_000 + 100;
		$this->assertQueueContains( 'learndash', 'enrollment', 'create', $expected_id );
	}

	public function test_on_enrollment_change_enqueues_delete_on_revoke(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_enrollments' => true ];

		$this->module->on_enrollment_change( 5, 100, [], true );

		$expected_id = 5 * 1_000_000 + 100;
		$this->assertQueueContains( 'learndash', 'enrollment', 'delete', $expected_id );
	}

	public function test_on_enrollment_change_skips_when_sync_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_enrollments' => false ];

		$this->module->on_enrollment_change( 5, 100, [ 100 ], false );

		$this->assertQueueEmpty();
	}

	public function test_on_enrollment_change_synthetic_id_is_correct(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_enrollments' => true ];

		$this->module->on_enrollment_change( 42, 350, [ 350 ], false );

		$expected_id = 42 * 1_000_000 + 350;
		$this->assertQueueContains( 'learndash', 'enrollment', 'create', $expected_id );
	}

	// ─── Pull settings ──────────────────────────────────

	public function test_default_settings_has_pull_courses(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_courses'] );
	}

	public function test_default_settings_has_pull_groups(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_groups'] );
	}

	public function test_settings_fields_exposes_pull_courses(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_courses', $fields );
		$this->assertSame( 'checkbox', $fields['pull_courses']['type'] );
	}

	public function test_settings_fields_exposes_pull_groups(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_groups', $fields );
		$this->assertSame( 'checkbox', $fields['pull_groups']['type'] );
	}

	// ─── Pull: delete ───────────────────────────────────

	public function test_pull_course_delete_removes_post(): void {
		$this->create_post( 50, 'sfwd-courses', 'Course to delete' );

		$result = $this->module->pull_from_odoo( 'course', 'delete', 100, 50 );
		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_group_delete_removes_post(): void {
		$this->create_post( 60, 'groups', 'Group to delete' );

		$result = $this->module->pull_from_odoo( 'group', 'delete', 200, 60 );
		$this->assertTrue( $result->succeeded() );
	}

	// ─── map_from_odoo ──────────────────────────────────

	public function test_map_from_odoo_group(): void {
		$odoo_data = [
			'name'             => 'Pulled Group',
			'description_sale' => 'Group from Odoo',
			'list_price'       => 199.0,
		];

		$wp_data = $this->module->map_from_odoo( 'group', $odoo_data );

		$this->assertSame( 'Pulled Group', $wp_data['title'] );
		$this->assertSame( 'Group from Odoo', $wp_data['description'] );
		$this->assertSame( 199.0, $wp_data['list_price'] );
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

	public function test_translatable_fields_for_group(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$fields = $method->invoke( $this->module, 'group' );

		$this->assertSame(
			[ 'name' => 'post_title', 'description_sale' => 'post_content' ],
			$fields
		);
	}

	public function test_translatable_fields_empty_for_transaction(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'transaction' ) );
	}

	public function test_translatable_fields_empty_for_enrollment(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'enrollment' ) );
	}
}
