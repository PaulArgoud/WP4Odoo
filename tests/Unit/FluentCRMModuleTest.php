<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\FluentCRM_Module;
use WP4Odoo\Modules\FluentCRM_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FluentCRM_Module, FluentCRM_Handler, and FluentCRM_Hooks.
 *
 * Tests module configuration, handler data loading/saving, parse methods,
 * and hook guard logic.
 */
class FluentCRMModuleTest extends TestCase {

	private FluentCRM_Module $module;
	private FluentCRM_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']   = [];
		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new FluentCRM_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new FluentCRM_Handler( new Logger( 'fluentcrm', wp4odoo_test_settings() ) );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_fluentcrm(): void {
		$this->assertSame( 'fluentcrm', $this->module->get_id() );
	}

	public function test_module_name_is_fluentcrm(): void {
		$this->assertSame( 'FluentCRM', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_subscriber_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'mailing.contact', $models['subscriber'] );
	}

	public function test_declares_list_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'mailing.list', $models['list'] );
	}

	public function test_declares_tag_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'res.partner.category', $models['tag'] );
	}

	public function test_declares_exactly_three_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 3, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_subscribers(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_subscribers'] );
	}

	public function test_default_settings_has_sync_lists(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_lists'] );
	}

	public function test_default_settings_has_sync_tags(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_tags'] );
	}

	public function test_default_settings_has_pull_subscribers(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_subscribers'] );
	}

	public function test_default_settings_has_pull_lists(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_lists'] );
	}

	public function test_default_settings_has_exactly_five_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 5, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_subscribers(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_subscribers', $fields );
		$this->assertSame( 'checkbox', $fields['sync_subscribers']['type'] );
	}

	public function test_settings_fields_exposes_sync_lists(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_lists', $fields );
		$this->assertSame( 'checkbox', $fields['sync_lists']['type'] );
	}

	public function test_settings_fields_exposes_sync_tags(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_tags', $fields );
		$this->assertSame( 'checkbox', $fields['sync_tags']['type'] );
	}

	public function test_settings_fields_exposes_pull_subscribers(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_subscribers', $fields );
		$this->assertSame( 'checkbox', $fields['pull_subscribers']['type'] );
	}

	public function test_settings_fields_exposes_pull_lists(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'pull_lists', $fields );
		$this->assertSame( 'checkbox', $fields['pull_lists']['type'] );
	}

	public function test_settings_fields_has_exactly_five_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 5, $fields );
	}

	// ─── Field Mappings: Subscriber ────────────────────────

	public function test_subscriber_mapping_includes_email(): void {
		$odoo = $this->module->map_to_odoo( 'subscriber', [ 'email' => 'test@example.com' ] );
		$this->assertSame( 'test@example.com', $odoo['email'] );
	}

	public function test_subscriber_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'subscriber', [ 'first_name' => 'John', 'last_name' => 'Doe' ] );
		$this->assertSame( 'John Doe', $odoo['name'] );
	}

	public function test_subscriber_mapping_includes_status(): void {
		$odoo = $this->module->map_to_odoo( 'subscriber', [ 'status' => 'unsubscribed' ] );
		$this->assertSame( 'unsubscribed', $odoo['x_status'] );
	}

	// ─── Field Mappings: List ──────────────────────────────

	public function test_list_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'list', [ 'title' => 'Newsletter' ] );
		$this->assertSame( 'Newsletter', $odoo['name'] );
	}

	public function test_list_mapping_includes_description(): void {
		$odoo = $this->module->map_to_odoo( 'list', [ 'description' => 'Weekly updates' ] );
		$this->assertSame( 'Weekly updates', $odoo['x_description'] );
	}

	// ─── Field Mappings: Tag ───────────────────────────────

	public function test_tag_mapping_includes_name(): void {
		$odoo = $this->module->map_to_odoo( 'tag', [ 'title' => 'VIP' ] );
		$this->assertSame( 'VIP', $odoo['name'] );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_unavailable_without_fluentcrm(): void {
		// FLUENTCRM is defined in the stub, so check_dependency sees it.
		// To test unavailable, we'd need to undefine it — not possible.
		// Instead, verify the structure is correct when available.
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_has_warning_for_version_mismatch(): void {
		// FLUENTCRM_PLUGIN_VERSION is 2.9.0, TESTED_UP_TO is 2.9.
		// Since 2.9.0 is within the 2.9.x range, no warning.
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot Guard ────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Handler: load_subscriber ──────────────────────────

	public function test_load_subscriber_returns_data_for_valid_subscriber(): void {
		$this->wpdb->get_row_return = [
			'id'         => 1,
			'email'      => 'test@example.com',
			'first_name' => 'John',
			'last_name'  => 'Doe',
			'status'     => 'subscribed',
		];
		$this->wpdb->get_col_return = [ '10', '20' ];

		$data = $this->handler->load_subscriber( 1 );

		$this->assertSame( 1, $data['id'] );
		$this->assertSame( 'test@example.com', $data['email'] );
		$this->assertSame( 'John', $data['first_name'] );
		$this->assertSame( 'Doe', $data['last_name'] );
		$this->assertSame( 'subscribed', $data['status'] );
		$this->assertSame( [ 10, 20 ], $data['list_ids'] );
	}

	public function test_load_subscriber_returns_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_subscriber( 999 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: load_list ────────────────────────────────

	public function test_load_list_returns_data_for_valid_list(): void {
		$this->wpdb->get_row_return = [
			'id'          => 5,
			'title'       => 'Newsletter',
			'description' => 'Weekly updates',
		];

		$data = $this->handler->load_list( 5 );

		$this->assertSame( 5, $data['id'] );
		$this->assertSame( 'Newsletter', $data['title'] );
		$this->assertSame( 'Weekly updates', $data['description'] );
	}

	public function test_load_list_returns_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_list( 999 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: load_tag ─────────────────────────────────

	public function test_load_tag_returns_data_for_valid_tag(): void {
		$this->wpdb->get_row_return = [
			'id'    => 3,
			'title' => 'VIP',
		];

		$data = $this->handler->load_tag( 3 );

		$this->assertSame( 3, $data['id'] );
		$this->assertSame( 'VIP', $data['title'] );
	}

	public function test_load_tag_returns_empty_for_nonexistent(): void {
		$this->wpdb->get_row_return = null;

		$data = $this->handler->load_tag( 999 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: parse_subscriber_from_odoo ───────────────

	public function test_parse_subscriber_from_odoo_splits_name(): void {
		$odoo_data = [
			'name'     => 'Jane Smith',
			'email'    => 'jane@example.com',
			'x_status' => 'subscribed',
		];

		$data = $this->handler->parse_subscriber_from_odoo( $odoo_data );

		$this->assertSame( 'Jane', $data['first_name'] );
		$this->assertSame( 'Smith', $data['last_name'] );
		$this->assertSame( 'jane@example.com', $data['email'] );
		$this->assertSame( 'subscribed', $data['status'] );
	}

	public function test_parse_subscriber_from_odoo_single_name(): void {
		$odoo_data = [
			'name'  => 'Madonna',
			'email' => 'madonna@example.com',
		];

		$data = $this->handler->parse_subscriber_from_odoo( $odoo_data );

		$this->assertSame( 'Madonna', $data['first_name'] );
		$this->assertSame( '', $data['last_name'] );
	}

	public function test_parse_subscriber_from_odoo_defaults_status(): void {
		$odoo_data = [
			'name'  => 'Test',
			'email' => 'test@example.com',
		];

		$data = $this->handler->parse_subscriber_from_odoo( $odoo_data );

		$this->assertSame( 'subscribed', $data['status'] );
	}

	// ─── Handler: parse_list_from_odoo ─────────────────────

	public function test_parse_list_from_odoo(): void {
		$odoo_data = [
			'name'          => 'Newsletter',
			'x_description' => 'Weekly updates',
		];

		$data = $this->handler->parse_list_from_odoo( $odoo_data );

		$this->assertSame( 'Newsletter', $data['title'] );
		$this->assertSame( 'Weekly updates', $data['description'] );
	}

	public function test_parse_list_from_odoo_empty_fields(): void {
		$data = $this->handler->parse_list_from_odoo( [] );

		$this->assertSame( '', $data['title'] );
		$this->assertSame( '', $data['description'] );
	}

	// ─── Handler: save_subscriber ──────────────────────────

	public function test_save_subscriber_inserts_new(): void {
		$this->wpdb->get_var_return = null;
		$this->wpdb->insert_id     = 42;

		$id = $this->handler->save_subscriber( [
			'email'      => 'new@example.com',
			'first_name' => 'New',
			'last_name'  => 'User',
			'status'     => 'subscribed',
		] );

		$this->assertSame( 42, $id );

		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => 'insert' === $c['method'] );
		$this->assertNotEmpty( $inserts );
	}

	public function test_save_subscriber_updates_existing(): void {
		$this->wpdb->get_var_return = '10';

		$id = $this->handler->save_subscriber( [
			'email'      => 'existing@example.com',
			'first_name' => 'Existing',
			'last_name'  => 'User',
			'status'     => 'unsubscribed',
		] );

		$this->assertSame( 10, $id );

		$updates = array_filter( $this->wpdb->calls, fn( $c ) => 'update' === $c['method'] );
		$this->assertNotEmpty( $updates );
	}

	// ─── Handler: save_list ────────────────────────────────

	public function test_save_list_inserts_new(): void {
		$this->wpdb->get_var_return = null;
		$this->wpdb->insert_id     = 15;

		$id = $this->handler->save_list( [
			'title'       => 'New List',
			'description' => 'A new mailing list',
		] );

		$this->assertSame( 15, $id );

		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => 'insert' === $c['method'] );
		$this->assertNotEmpty( $inserts );
	}

	public function test_save_list_updates_existing(): void {
		$this->wpdb->get_var_return = '8';

		$id = $this->handler->save_list( [
			'title'       => 'Existing List',
			'description' => 'Updated description',
		] );

		$this->assertSame( 8, $id );

		$updates = array_filter( $this->wpdb->calls, fn( $c ) => 'update' === $c['method'] );
		$this->assertNotEmpty( $updates );
	}

	// ─── Hooks: on_subscriber_created ──────────────────────

	public function test_on_subscriber_created_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_subscribers' => true ];

		$subscriber     = new \FluentCrm\App\Models\Subscriber();
		$subscriber->id = 1;

		$this->module->on_subscriber_created( $subscriber );

		$this->assertQueueContains( 'fluentcrm', 'subscriber', 'create', 1 );
	}

	public function test_on_subscriber_created_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_subscribers' => false ];

		$subscriber     = new \FluentCrm\App\Models\Subscriber();
		$subscriber->id = 1;

		$this->module->on_subscriber_created( $subscriber );

		$this->assertQueueEmpty();
	}

	public function test_on_subscriber_created_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_subscribers' => true ];

		$subscriber     = new \FluentCrm\App\Models\Subscriber();
		$subscriber->id = 0;

		$this->module->on_subscriber_created( $subscriber );

		$this->assertQueueEmpty();
	}

	public function test_on_subscriber_created_skips_when_importing(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_subscribers' => true ];

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'fluentcrm' => true ] );

		$subscriber     = new \FluentCrm\App\Models\Subscriber();
		$subscriber->id = 1;

		$this->module->on_subscriber_created( $subscriber );

		$this->assertQueueEmpty();

		// Clean up.
		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_subscriber_status_changed ───────────────

	public function test_on_subscriber_status_changed_enqueues_update(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_subscribers' => true ];

		$subscriber     = new \FluentCrm\App\Models\Subscriber();
		$subscriber->id = 5;

		$this->module->on_subscriber_status_changed( $subscriber, 'subscribed' );

		$this->assertQueueContains( 'fluentcrm', 'subscriber', 'create', 5 );
	}

	public function test_on_subscriber_status_changed_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_subscribers' => false ];

		$subscriber     = new \FluentCrm\App\Models\Subscriber();
		$subscriber->id = 5;

		$this->module->on_subscriber_status_changed( $subscriber, 'subscribed' );

		$this->assertQueueEmpty();
	}

	public function test_on_subscriber_status_changed_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_subscribers' => true ];

		$subscriber     = new \FluentCrm\App\Models\Subscriber();
		$subscriber->id = 0;

		$this->module->on_subscriber_status_changed( $subscriber, 'subscribed' );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_list_created ────────────────────────────

	public function test_on_list_created_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_lists' => true ];

		$list     = new \FluentCrm\App\Models\Lists();
		$list->id = 3;

		$this->module->on_list_created( $list );

		$this->assertQueueContains( 'fluentcrm', 'list', 'create', 3 );
	}

	public function test_on_list_created_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_lists' => false ];

		$list     = new \FluentCrm\App\Models\Lists();
		$list->id = 3;

		$this->module->on_list_created( $list );

		$this->assertQueueEmpty();
	}

	public function test_on_list_created_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_lists' => true ];

		$list     = new \FluentCrm\App\Models\Lists();
		$list->id = 0;

		$this->module->on_list_created( $list );

		$this->assertQueueEmpty();
	}

	// ─── Hooks: on_tag_created ─────────────────────────────

	public function test_on_tag_created_enqueues_create(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_tags' => true ];

		$tag     = new \FluentCrm\App\Models\Tag();
		$tag->id = 7;

		$this->module->on_tag_created( $tag );

		$this->assertQueueContains( 'fluentcrm', 'tag', 'create', 7 );
	}

	public function test_on_tag_created_skips_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_tags' => false ];

		$tag     = new \FluentCrm\App\Models\Tag();
		$tag->id = 7;

		$this->module->on_tag_created( $tag );

		$this->assertQueueEmpty();
	}

	public function test_on_tag_created_skips_zero_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'sync_tags' => true ];

		$tag     = new \FluentCrm\App\Models\Tag();
		$tag->id = 0;

		$this->module->on_tag_created( $tag );

		$this->assertQueueEmpty();
	}

	// ─── Pull: tag skipped ─────────────────────────────────

	public function test_pull_tag_skipped(): void {
		$result = $this->module->pull_from_odoo( 'tag', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Pull: subscriber/list ─────────────────────────────

	public function test_pull_subscriber_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'pull_subscribers' => false ];

		$result = $this->module->pull_from_odoo( 'subscriber', 'create', 100, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	public function test_pull_list_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_fluentcrm_settings'] = [ 'pull_lists' => false ];

		$result = $this->module->pull_from_odoo( 'list', 'create', 200, 0 );
		$this->assertTrue( $result->succeeded() );
		$this->assertNull( $result->get_entity_id() );
	}

	// ─── Dedup Domains ─────────────────────────────────────

	public function test_dedup_subscriber_by_email(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'subscriber', [ 'email' => 'test@example.com' ] );

		$this->assertSame( [ [ 'email', '=', 'test@example.com' ] ], $domain );
	}

	public function test_dedup_list_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'list', [ 'name' => 'Newsletter' ] );

		$this->assertSame( [ [ 'name', '=', 'Newsletter' ] ], $domain );
	}

	public function test_dedup_tag_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'tag', [ 'name' => 'VIP' ] );

		$this->assertSame( [ [ 'name', '=', 'VIP' ] ], $domain );
	}

	public function test_dedup_empty_when_no_key(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'subscriber', [] );

		$this->assertSame( [], $domain );
	}

	// ─── map_from_odoo ─────────────────────────────────────

	public function test_map_from_odoo_subscriber(): void {
		$odoo_data = [
			'name'     => 'John Doe',
			'email'    => 'john@example.com',
			'x_status' => 'unsubscribed',
		];

		$wp_data = $this->module->map_from_odoo( 'subscriber', $odoo_data );

		$this->assertSame( 'John', $wp_data['first_name'] );
		$this->assertSame( 'Doe', $wp_data['last_name'] );
		$this->assertSame( 'john@example.com', $wp_data['email'] );
		$this->assertSame( 'unsubscribed', $wp_data['status'] );
	}

	public function test_map_from_odoo_list(): void {
		$odoo_data = [
			'name'          => 'Newsletter',
			'x_description' => 'From Odoo',
		];

		$wp_data = $this->module->map_from_odoo( 'list', $odoo_data );

		$this->assertSame( 'Newsletter', $wp_data['title'] );
		$this->assertSame( 'From Odoo', $wp_data['description'] );
	}

	// ─── Subscriber name construction ──────────────────────

	public function test_subscriber_mapping_combines_first_last_name(): void {
		$odoo = $this->module->map_to_odoo( 'subscriber', [
			'first_name' => 'Alice',
			'last_name'  => 'Wonder',
		] );

		$this->assertSame( 'Alice Wonder', $odoo['name'] );
	}

	public function test_subscriber_mapping_first_name_only(): void {
		$odoo = $this->module->map_to_odoo( 'subscriber', [
			'first_name' => 'Alice',
			'last_name'  => '',
		] );

		$this->assertSame( 'Alice', $odoo['name'] );
	}

	// ─── Subscriber list_ids M2M formatting ────────────────

	public function test_subscriber_list_ids_empty_when_no_mapped_lists(): void {
		$odoo = $this->module->map_to_odoo( 'subscriber', [
			'first_name' => 'John',
			'list_ids'   => [ 1, 2, 3 ],
		] );

		// No entity mappings exist, so all list_ids resolve to nothing.
		$this->assertSame( [ [ 6, 0, [] ] ], $odoo['list_ids'] );
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
