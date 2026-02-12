<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\LearnDash_Module;
use WP4Odoo\Modules\LearnDash_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LearnDash_Module, LearnDash_Handler, and LearnDash_Hooks.
 *
 * Tests module configuration, handler data loading, invoice/order formatting,
 * and hook guard logic.
 */
class LearnDashModuleTest extends TestCase {

	private LearnDash_Module $module;
	private LearnDash_Handler $handler;
	private \WP_DB_Stub $wpdb;

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

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_learndash(): void {
		$this->assertSame( 'learndash', $this->module->get_id() );
	}

	public function test_module_name_is_learndash(): void {
		$this->assertSame( 'LearnDash', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_course_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['course'] );
	}

	public function test_declares_group_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'product.product', $models['group'] );
	}

	public function test_declares_transaction_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'account.move', $models['transaction'] );
	}

	public function test_declares_enrollment_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'sale.order', $models['enrollment'] );
	}

	public function test_declares_exactly_four_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 4, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_courses(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_courses'] );
	}

	public function test_default_settings_has_sync_groups(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_groups'] );
	}

	public function test_default_settings_has_sync_transactions(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_transactions'] );
	}

	public function test_default_settings_has_sync_enrollments(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_enrollments'] );
	}

	public function test_default_settings_has_auto_post_invoices(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['auto_post_invoices'] );
	}

	public function test_default_settings_has_exactly_seven_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 7, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_courses(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_courses', $fields );
		$this->assertSame( 'checkbox', $fields['sync_courses']['type'] );
	}

	public function test_settings_fields_exposes_sync_groups(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_groups', $fields );
		$this->assertSame( 'checkbox', $fields['sync_groups']['type'] );
	}

	public function test_settings_fields_exposes_sync_transactions(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_transactions', $fields );
		$this->assertSame( 'checkbox', $fields['sync_transactions']['type'] );
	}

	public function test_settings_fields_exposes_sync_enrollments(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_enrollments', $fields );
		$this->assertSame( 'checkbox', $fields['sync_enrollments']['type'] );
	}

	public function test_settings_fields_exposes_auto_post_invoices(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'auto_post_invoices', $fields );
		$this->assertSame( 'checkbox', $fields['auto_post_invoices']['type'] );
	}

	// ─── Field Mappings: Course ────────────────────────────

	public function test_course_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'course', [ 'title' => 'PHP Basics' ] );
		$this->assertSame( 'PHP Basics', $odoo['name'] );
	}

	public function test_course_mapping_includes_list_price(): void {
		$odoo = $this->module->map_to_odoo( 'course', [ 'list_price' => 49.99 ] );
		$this->assertSame( 49.99, $odoo['list_price'] );
	}

	public function test_course_mapping_includes_type(): void {
		$odoo = $this->module->map_to_odoo( 'course', [ 'type' => 'service' ] );
		$this->assertSame( 'service', $odoo['type'] );
	}

	public function test_course_mapping_includes_description(): void {
		$odoo = $this->module->map_to_odoo( 'course', [ 'description' => 'Learn PHP' ] );
		$this->assertSame( 'Learn PHP', $odoo['description_sale'] );
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

	// ─── Field Mappings: Transaction ───────────────────────

	public function test_transaction_mapping_includes_move_type(): void {
		$odoo = $this->module->map_to_odoo( 'transaction', [ 'move_type' => 'out_invoice' ] );
		$this->assertSame( 'out_invoice', $odoo['move_type'] );
	}

	public function test_transaction_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'transaction', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_transaction_mapping_includes_invoice_line_ids(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 5, 'quantity' => 1, 'price_unit' => 49.99 ] ] ];
		$odoo  = $this->module->map_to_odoo( 'transaction', [ 'invoice_line_ids' => $lines ] );
		$this->assertSame( $lines, $odoo['invoice_line_ids'] );
	}

	// ─── Field Mappings: Enrollment ────────────────────────

	public function test_enrollment_mapping_includes_partner_id(): void {
		$odoo = $this->module->map_to_odoo( 'enrollment', [ 'partner_id' => 42 ] );
		$this->assertSame( 42, $odoo['partner_id'] );
	}

	public function test_enrollment_mapping_includes_order_line(): void {
		$lines = [ [ 0, 0, [ 'product_id' => 5, 'quantity' => 1 ] ] ];
		$odoo  = $this->module->map_to_odoo( 'enrollment', [ 'order_line' => $lines ] );
		$this->assertSame( $lines, $odoo['order_line'] );
	}

	public function test_enrollment_mapping_includes_state(): void {
		$odoo = $this->module->map_to_odoo( 'enrollment', [ 'state' => 'sale' ] );
		$this->assertSame( 'sale', $odoo['state'] );
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

	// ─── Boot Guard ───────────────────────────────────────

	public function test_boot_does_not_crash_without_learndash(): void {
		$this->module->boot();
		$this->assertTrue( true );
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

		// Simulate importing.
		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing' );
		$prop->setValue( null, [ 'learndash' => true ] );

		$this->module->on_course_save( 100 );

		$this->assertQueueEmpty();

		// Clean up.
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
		$prop       = $reflection->getProperty( 'importing' );
		$prop->setValue( null, [ 'learndash' => true ] );

		$this->module->on_transaction_created( 300 );

		$this->assertQueueEmpty();

		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_enrollment_change ──────────────────────

	public function test_on_enrollment_change_enqueues_create_on_grant(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_learndash_settings'] = [ 'sync_enrollments' => true ];

		$this->module->on_enrollment_change( 5, 100, [ 100 ], false );

		$expected_id = 5 * 1_000_000 + 100; // 5000100
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

		$expected_id = 42 * 1_000_000 + 350; // 42000350
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

	// ─── Pull: transaction/enrollment skipped ───────────

	public function test_pull_transaction_skipped(): void {
		$result = $this->module->pull_from_odoo( 'transaction', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 0, $result->get_entity_id() );
	}

	public function test_pull_enrollment_skipped(): void {
		$result = $this->module->pull_from_odoo( 'enrollment', 'create', 200, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertSame( 0, $result->get_entity_id() );
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

	public function test_map_from_odoo_course(): void {
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

	// ─── Helpers ───────────────────────────────────────────

	private function create_post( int $id, string $post_type, string $title, string $content = '', int $author = 0, string $date_gmt = '' ): void {
		$GLOBALS['_wp_posts'][ $id ] = (object) [
			'ID'            => $id,
			'post_type'     => $post_type,
			'post_title'    => $title,
			'post_content'  => $content,
			'post_status'   => 'publish',
			'post_author'   => $author,
			'post_date_gmt' => $date_gmt ?: '2026-01-01 00:00:00',
		];
	}

	private function create_user( int $id, string $email, string $display_name ): void {
		$user                = new \stdClass();
		$user->ID            = $id;
		$user->user_email    = $email;
		$user->display_name  = $display_name;
		$user->first_name    = explode( ' ', $display_name )[0] ?? '';
		$user->last_name     = explode( ' ', $display_name )[1] ?? '';

		$GLOBALS['_wp_users'][ $id ] = $user;
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
