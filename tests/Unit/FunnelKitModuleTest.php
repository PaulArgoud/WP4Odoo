<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\FunnelKit_Module;
use WP4Odoo\Modules\FunnelKit_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FunnelKit_Module, FunnelKit_Handler, and FunnelKit_Hooks.
 *
 * Tests module configuration, handler data loading/saving, parse methods,
 * format methods, and hook guard logic.
 */
class FunnelKitModuleTest extends TestCase {

	private FunnelKit_Module $module;
	private FunnelKit_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new FunnelKit_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new FunnelKit_Handler( new Logger( 'funnelkit', wp4odoo_test_settings() ) );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_funnelkit(): void {
		$this->assertSame( 'funnelkit', $this->module->get_id() );
	}

	public function test_module_name_is_funnelkit(): void {
		$this->assertSame( 'FunnelKit', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_contact_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'crm.lead', $models['contact'] );
	}

	public function test_declares_step_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'crm.stage', $models['step'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 2, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_contacts(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_contacts'] );
	}

	public function test_default_settings_has_sync_steps(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_steps'] );
	}

	public function test_default_settings_has_pull_contacts(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_contacts'] );
	}

	public function test_default_settings_has_odoo_pipeline_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_pipeline_id'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_contacts(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_contacts', $fields );
		$this->assertSame( 'checkbox', $fields['sync_contacts']['type'] );
	}

	public function test_settings_fields_exposes_sync_steps(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_steps', $fields );
		$this->assertSame( 'checkbox', $fields['sync_steps']['type'] );
	}

	public function test_settings_fields_exposes_pull_contacts(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_contacts', $fields );
		$this->assertSame( 'checkbox', $fields['pull_contacts']['type'] );
	}

	public function test_settings_fields_exposes_odoo_pipeline_id(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'odoo_pipeline_id', $fields );
		$this->assertSame( 'number', $fields['odoo_pipeline_id']['type'] );
	}

	public function test_settings_fields_has_exactly_four_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 4, $fields );
	}

	// ─── Field Mappings: Contact ──────────────────────────

	public function test_contact_mapping_includes_email(): void {
		$odoo = $this->module->map_to_odoo( 'contact', [ 'email' => 'test@example.com' ] );
		$this->assertSame( 'test@example.com', $odoo['email_from'] );
	}

	public function test_contact_mapping_includes_contact_name(): void {
		$odoo = $this->module->map_to_odoo( 'contact', [ 'first_name' => 'John', 'last_name' => 'Doe' ] );
		$this->assertSame( 'John Doe', $odoo['contact_name'] );
	}

	public function test_contact_mapping_includes_phone(): void {
		$odoo = $this->module->map_to_odoo( 'contact', [ 'phone' => '+33612345678' ] );
		$this->assertSame( '+33612345678', $odoo['phone'] );
	}

	public function test_contact_mapping_includes_source(): void {
		$odoo = $this->module->map_to_odoo( 'contact', [ 'email' => 'test@example.com' ] );
		$this->assertSame( 'funnelkit', $odoo['x_wp_source'] );
	}

	public function test_contact_mapping_includes_funnel_id(): void {
		$odoo = $this->module->map_to_odoo( 'contact', [ 'email' => 'test@example.com', 'funnel_id' => 42 ] );
		$this->assertSame( 42, $odoo['x_wp_funnel_id'] );
	}

	// ─── Field Mappings: Step ─────────────────────────────

	public function test_step_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'step', [ 'title' => 'Opt-in' ] );
		$this->assertSame( 'Opt-in', $odoo['name'] );
	}

	public function test_step_mapping_includes_sequence(): void {
		$odoo = $this->module->map_to_odoo( 'step', [ 'title' => 'Opt-in', 'sequence' => 5 ] );
		$this->assertSame( 5, $odoo['sequence'] );
	}

	public function test_step_mapping_includes_team_id_when_set(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'odoo_pipeline_id' => 7 ];

		$odoo = $this->module->map_to_odoo( 'step', [ 'title' => 'Opt-in', 'sequence' => 1 ] );
		$this->assertSame( 7, $odoo['team_id'] );
	}

	public function test_step_mapping_no_team_id_when_zero(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'odoo_pipeline_id' => 0 ];

		$odoo = $this->module->map_to_odoo( 'step', [ 'title' => 'Opt-in', 'sequence' => 1 ] );
		$this->assertArrayNotHasKey( 'team_id', $odoo );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_available_with_wffn_version(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_no_warnings_for_compatible_version(): void {
		// WFFN_VERSION is 3.3.0, TESTED_UP_TO is 3.5 — within range, no warning.
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Handler: load_contact ──────────────────────────────

	public function test_load_contact_returns_data_for_valid_contact(): void {
		$this->wpdb->get_row_return = [
			'id'         => 1,
			'email'      => 'test@example.com',
			'f_name'     => 'John',
			'l_name'     => 'Doe',
			'contact_no' => '+33612345678',
		];
		$this->wpdb->get_results_return = [
			[ 'meta_key' => 'current_step_id', 'meta_value' => '10' ],
			[ 'meta_key' => 'funnel_id', 'meta_value' => '5' ],
		];

		$data = $this->handler->load_contact( 1 );

		$this->assertSame( 1, $data['id'] );
		$this->assertSame( 'test@example.com', $data['email'] );
		$this->assertSame( 'John', $data['first_name'] );
		$this->assertSame( 'Doe', $data['last_name'] );
		$this->assertSame( '+33612345678', $data['phone'] );
		$this->assertSame( 10, $data['current_step_id'] );
		$this->assertSame( 5, $data['funnel_id'] );
	}

	public function test_load_contact_returns_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_contact( 999 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: load_step ────────────────────────────────

	public function test_load_step_returns_data_for_valid_step(): void {
		$post              = new \stdClass();
		$post->ID          = 100;
		$post->post_title  = 'Opt-in Page';
		$post->post_type   = 'wffn_step';
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][100]                      = $post;
		$GLOBALS['_wp_post_meta'][100]['_step_sequence'] = 3;
		$GLOBALS['_wp_post_meta'][100]['_funnel_id']     = 7;
		$GLOBALS['_wp_post_meta'][100]['_step_type']     = 'optin';

		$data = $this->handler->load_step( 100 );

		$this->assertSame( 100, $data['id'] );
		$this->assertSame( 'Opt-in Page', $data['title'] );
		$this->assertSame( 'optin', $data['type'] );
		$this->assertSame( 3, $data['sequence'] );
		$this->assertSame( 7, $data['funnel_id'] );
	}

	public function test_load_step_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_step( 999 );
		$this->assertSame( [], $data );
	}

	public function test_load_step_returns_empty_for_wrong_post_type(): void {
		$post              = new \stdClass();
		$post->ID          = 101;
		$post->post_title  = 'Regular Post';
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		$GLOBALS['_wp_posts'][101] = $post;

		$data = $this->handler->load_step( 101 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: format_lead ──────────────────────────────

	public function test_format_lead_structure(): void {
		$contact = [
			'email'      => 'test@example.com',
			'first_name' => 'Jane',
			'last_name'  => 'Smith',
			'phone'      => '+33600000000',
			'funnel_id'  => 3,
		];

		$lead = $this->handler->format_lead( $contact );

		$this->assertArrayHasKey( 'email_from', $lead );
		$this->assertArrayHasKey( 'contact_name', $lead );
		$this->assertArrayHasKey( 'phone', $lead );
		$this->assertArrayHasKey( 'x_wp_source', $lead );
		$this->assertArrayHasKey( 'x_wp_funnel_id', $lead );
	}

	public function test_format_lead_email_field(): void {
		$lead = $this->handler->format_lead( [ 'email' => 'hello@example.com' ] );
		$this->assertSame( 'hello@example.com', $lead['email_from'] );
	}

	public function test_format_lead_name_composition(): void {
		$lead = $this->handler->format_lead( [ 'first_name' => 'Alice', 'last_name' => 'Wonder' ] );
		$this->assertSame( 'Alice Wonder', $lead['contact_name'] );
	}

	public function test_format_lead_first_name_only(): void {
		$lead = $this->handler->format_lead( [ 'first_name' => 'Alice', 'last_name' => '' ] );
		$this->assertSame( 'Alice', $lead['contact_name'] );
	}

	public function test_format_lead_source_is_funnelkit(): void {
		$lead = $this->handler->format_lead( [ 'email' => 'a@b.com' ] );
		$this->assertSame( 'funnelkit', $lead['x_wp_source'] );
	}

	// ─── Handler: format_stage ─────────────────────────────

	public function test_format_stage_structure(): void {
		$step = [
			'title'    => 'Landing Page',
			'sequence' => 2,
		];

		$stage = $this->handler->format_stage( $step );

		$this->assertArrayHasKey( 'name', $stage );
		$this->assertArrayHasKey( 'sequence', $stage );
	}

	public function test_format_stage_name_field(): void {
		$stage = $this->handler->format_stage( [ 'title' => 'Checkout' ] );
		$this->assertSame( 'Checkout', $stage['name'] );
	}

	public function test_format_stage_with_team_id(): void {
		$stage = $this->handler->format_stage( [ 'title' => 'Checkout', 'sequence' => 1 ], 5 );
		$this->assertSame( 5, $stage['team_id'] );
	}

	public function test_format_stage_without_team_id(): void {
		$stage = $this->handler->format_stage( [ 'title' => 'Checkout', 'sequence' => 1 ], 0 );
		$this->assertArrayNotHasKey( 'team_id', $stage );
	}

	// ─── Handler: save_contact ──────────────────────────────

	public function test_save_contact_inserts_new(): void {
		$this->wpdb->get_var_return = null;
		$this->wpdb->insert_id     = 42;

		$id = $this->handler->save_contact( [
			'email'      => 'new@example.com',
			'first_name' => 'New',
			'last_name'  => 'User',
			'phone'      => '+33600000000',
		] );

		$this->assertSame( 42, $id );

		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => 'insert' === $c['method'] );
		$this->assertNotEmpty( $inserts );
	}

	public function test_save_contact_updates_existing(): void {
		$this->wpdb->get_var_return = '10';

		$id = $this->handler->save_contact( [
			'email'      => 'existing@example.com',
			'first_name' => 'Existing',
			'last_name'  => 'User',
			'phone'      => '+33611111111',
		] );

		$this->assertSame( 10, $id );

		$updates = array_filter( $this->wpdb->calls, fn( $c ) => 'update' === $c['method'] );
		$this->assertNotEmpty( $updates );
	}

	// ─── Handler: parse_contact_from_odoo ───────────────────

	public function test_parse_contact_from_odoo_splits_name(): void {
		$odoo_data = [
			'contact_name' => 'Jane Smith',
			'email_from'   => 'jane@example.com',
			'phone'        => '+33600000000',
		];

		$data = $this->handler->parse_contact_from_odoo( $odoo_data );

		$this->assertSame( 'Jane', $data['first_name'] );
		$this->assertSame( 'Smith', $data['last_name'] );
		$this->assertSame( 'jane@example.com', $data['email'] );
		$this->assertSame( '+33600000000', $data['phone'] );
	}

	public function test_parse_contact_from_odoo_single_name(): void {
		$odoo_data = [
			'contact_name' => 'Madonna',
			'email_from'   => 'madonna@example.com',
		];

		$data = $this->handler->parse_contact_from_odoo( $odoo_data );

		$this->assertSame( 'Madonna', $data['first_name'] );
		$this->assertSame( '', $data['last_name'] );
	}

	public function test_parse_contact_from_odoo_extracts_email(): void {
		$odoo_data = [
			'contact_name' => 'Test',
			'email_from'   => 'test@example.com',
		];

		$data = $this->handler->parse_contact_from_odoo( $odoo_data );
		$this->assertSame( 'test@example.com', $data['email'] );
	}

	public function test_parse_contact_from_odoo_extracts_stage_name(): void {
		$odoo_data = [
			'contact_name' => 'Test',
			'email_from'   => 'test@example.com',
			'stage_id'     => [ 5, 'Qualified' ],
		];

		$data = $this->handler->parse_contact_from_odoo( $odoo_data );
		$this->assertSame( 'Qualified', $data['_stage_name'] );
	}

	public function test_parse_contact_from_odoo_no_stage_when_absent(): void {
		$odoo_data = [
			'contact_name' => 'Test',
			'email_from'   => 'test@example.com',
		];

		$data = $this->handler->parse_contact_from_odoo( $odoo_data );
		$this->assertArrayNotHasKey( '_stage_name', $data );
	}

	// ─── Handler: resolve_step_from_stage ───────────────────

	public function test_resolve_step_from_stage_matches_by_name(): void {
		$step_map = [
			10 => 'Opt-in',
			20 => 'Checkout',
			30 => 'Thank You',
		];

		$result = $this->handler->resolve_step_from_stage( 'Checkout', $step_map );
		$this->assertSame( 20, $result );
	}

	public function test_resolve_step_from_stage_case_insensitive(): void {
		$step_map = [
			10 => 'Opt-in',
			20 => 'Checkout',
		];

		$result = $this->handler->resolve_step_from_stage( 'checkout', $step_map );
		$this->assertSame( 20, $result );
	}

	public function test_resolve_step_from_stage_returns_null_no_match(): void {
		$step_map = [
			10 => 'Opt-in',
		];

		$result = $this->handler->resolve_step_from_stage( 'Unknown', $step_map );
		$this->assertNull( $result );
	}

	public function test_resolve_step_from_stage_returns_null_empty_name(): void {
		$result = $this->handler->resolve_step_from_stage( '', [ 10 => 'Opt-in' ] );
		$this->assertNull( $result );
	}

	public function test_resolve_step_from_stage_returns_null_empty_map(): void {
		$result = $this->handler->resolve_step_from_stage( 'Checkout', [] );
		$this->assertNull( $result );
	}

	// ─── Hooks: on_contact_created ──────────────────────────

	public function test_on_contact_created_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_contacts' => true ];

		$this->module->on_contact_created( 1 );

		$this->assertQueueContains( 'funnelkit', 'contact', 'create', 1 );
	}

	public function test_on_contact_created_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_contacts' => false ];

		$this->module->on_contact_created( 1 );

		$this->assertQueueEmpty();
	}

	public function test_on_contact_created_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_contacts' => true ];

		$this->module->on_contact_created( 0 );

		$this->assertQueueEmpty();
	}

	public function test_on_contact_created_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_contacts' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing' );
		$prop->setValue( null, [ 'funnelkit' => true ] );

		$this->module->on_contact_created( 1 );

		$this->assertQueueEmpty();

		// Clean up.
		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_contact_updated ──────────────────────────

	public function test_on_contact_updated_enqueues_update(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_contacts' => true ];

		$this->module->on_contact_updated( 5 );

		$this->assertQueueContains( 'funnelkit', 'contact', 'update', 5 );
	}

	public function test_on_contact_updated_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_contacts' => false ];

		$this->module->on_contact_updated( 5 );

		$this->assertQueueEmpty();
	}

	public function test_on_contact_updated_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_contacts' => true ];

		$this->module->on_contact_updated( 0 );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_step_saved ──────────────────────────────

	public function test_on_step_saved_enqueues_push(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_steps' => true ];

		$this->module->on_step_saved( 100 );

		$this->assertQueueContains( 'funnelkit', 'step', 'create', 100 );
	}

	public function test_on_step_saved_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_steps' => false ];

		$this->module->on_step_saved( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_step_saved_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'sync_steps' => true ];

		$this->module->on_step_saved( 0 );

		$this->assertQueueEmpty();
	}

	// ─── Pull: step skipped ─────────────────────────────────

	public function test_pull_step_skipped(): void {
		$result = $this->module->pull_from_odoo( 'step', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: contact ──────────────────────────────────────

	public function test_pull_contact_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_funnelkit_settings'] = [ 'pull_contacts' => false ];

		$result = $this->module->pull_from_odoo( 'contact', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Dedup Domains ─────────────────────────────────────

	public function test_dedup_contact_by_email(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'contact', [ 'email_from' => 'test@example.com' ] );

		$this->assertSame( [ [ 'email_from', '=', 'test@example.com' ] ], $domain );
	}

	public function test_dedup_step_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'step', [ 'name' => 'Opt-in' ] );

		$this->assertSame( [ [ 'name', '=', 'Opt-in' ] ], $domain );
	}

	public function test_dedup_empty_when_no_key(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'contact', [] );

		$this->assertSame( [], $domain );
	}

	// ─── map_from_odoo ─────────────────────────────────────

	public function test_map_from_odoo_contact(): void {
		$odoo_data = [
			'contact_name' => 'John Doe',
			'email_from'   => 'john@example.com',
			'phone'        => '+33600000000',
		];

		$wp_data = $this->module->map_from_odoo( 'contact', $odoo_data );

		$this->assertSame( 'John', $wp_data['first_name'] );
		$this->assertSame( 'Doe', $wp_data['last_name'] );
		$this->assertSame( 'john@example.com', $wp_data['email'] );
		$this->assertSame( '+33600000000', $wp_data['phone'] );
	}

	// ─── Contact name construction ──────────────────────────

	public function test_contact_mapping_combines_first_last_name(): void {
		$odoo = $this->module->map_to_odoo( 'contact', [
			'first_name' => 'Alice',
			'last_name'  => 'Wonder',
		] );

		$this->assertSame( 'Alice Wonder', $odoo['contact_name'] );
	}

	public function test_contact_mapping_first_name_only(): void {
		$odoo = $this->module->map_to_odoo( 'contact', [
			'first_name' => 'Alice',
			'last_name'  => '',
		] );

		$this->assertSame( 'Alice', $odoo['contact_name'] );
	}

	// ─── Helpers ───────────────────────────────────────────

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
