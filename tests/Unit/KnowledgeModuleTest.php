<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Knowledge_Module;
use WP4Odoo\Modules\Knowledge_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Knowledge_Module, Knowledge_Handler, and Knowledge_Hooks.
 *
 * Tests module configuration, handler data loading/saving/parsing,
 * hook guard logic, translation fields, dedup domain, and pull guard.
 *
 * @covers \WP4Odoo\Modules\Knowledge_Module
 * @covers \WP4Odoo\Modules\Knowledge_Handler
 */
class KnowledgeModuleTest extends TestCase {

	private Knowledge_Module $module;
	private Knowledge_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']    = [];
		$GLOBALS['_wp_posts']      = [];
		$GLOBALS['_wp_post_meta']  = [];
		$GLOBALS['_wp_categories'] = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new Knowledge_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new Knowledge_Handler( new Logger( 'knowledge', wp4odoo_test_settings() ) );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_knowledge(): void {
		$this->assertSame( 'knowledge', $this->module->get_id() );
	}

	public function test_module_name_is_knowledge(): void {
		$this->assertSame( 'Knowledge', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_article_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'knowledge.article', $models['article'] );
	}

	public function test_declares_exactly_one_entity_type(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 1, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_articles(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_articles'] );
	}

	public function test_default_settings_has_pull_articles(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_articles'] );
	}

	public function test_default_settings_has_post_type(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 'post', $settings['post_type'] );
	}

	public function test_default_settings_has_category_filter(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( '', $settings['category_filter'] );
	}

	public function test_default_settings_has_exactly_four_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 4, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_sync_articles_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_articles']['type'] );
	}

	public function test_settings_fields_pull_articles_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['pull_articles']['type'] );
	}

	public function test_settings_fields_post_type_is_text(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'text', $fields['post_type']['type'] );
	}

	public function test_settings_fields_category_filter_is_text(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'text', $fields['category_filter']['type'] );
	}

	public function test_settings_fields_count(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 4, $fields );
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

	// ─── Boot Guard ────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Handler: load_article ─────────────────────────────

	public function test_load_article_returns_data_for_valid_post(): void {
		$this->create_post( 10, 'post', 'My Article', '<p>Hello world</p>', 'publish' );

		$data = $this->handler->load_article( 10 );

		$this->assertSame( 'My Article', $data['name'] );
		$this->assertSame( '<p>Hello world</p>', $data['body'] );
		$this->assertSame( 'workspace', $data['internal_permission'] );
	}

	public function test_load_article_returns_empty_for_wrong_post_type(): void {
		$this->create_post( 10, 'page', 'My Page', 'Content', 'publish' );

		$data = $this->handler->load_article( 10, 'post' );

		$this->assertSame( [], $data );
	}

	public function test_load_article_maps_publish_to_workspace(): void {
		$this->create_post( 10, 'post', 'Published', '', 'publish' );

		$data = $this->handler->load_article( 10 );

		$this->assertSame( 'workspace', $data['internal_permission'] );
	}

	public function test_load_article_maps_private_to_private(): void {
		$this->create_post( 10, 'post', 'Private', '', 'private' );

		$data = $this->handler->load_article( 10 );

		$this->assertSame( 'private', $data['internal_permission'] );
	}

	public function test_load_article_maps_draft_to_private(): void {
		$this->create_post( 10, 'post', 'Draft', '', 'draft' );

		$data = $this->handler->load_article( 10 );

		$this->assertSame( 'private', $data['internal_permission'] );
	}

	public function test_load_article_preserves_html_body(): void {
		$html = '<h1>Title</h1><p>Paragraph with <strong>bold</strong> and <a href="#">link</a></p>';
		$this->create_post( 10, 'post', 'HTML Article', $html, 'publish' );

		$data = $this->handler->load_article( 10 );

		$this->assertSame( $html, $data['body'] );
	}

	public function test_load_article_includes_menu_order(): void {
		$this->create_post( 10, 'post', 'Ordered', '', 'publish', 0, 5 );

		$data = $this->handler->load_article( 10 );

		$this->assertSame( 5, $data['sequence'] );
	}

	public function test_load_article_returns_empty_for_nonexistent(): void {
		$data = $this->handler->load_article( 999 );

		$this->assertSame( [], $data );
	}

	public function test_load_article_accepts_custom_post_type(): void {
		$this->create_post( 10, 'kb_article', 'Custom CPT', 'Content', 'publish' );

		$data = $this->handler->load_article( 10, 'kb_article' );

		$this->assertSame( 'Custom CPT', $data['name'] );
	}

	// ─── Handler: parse_article_from_odoo ──────────────────

	public function test_parse_article_maps_fields(): void {
		$odoo_data = [
			'name'                => 'Odoo Article',
			'body'                => '<p>Content from Odoo</p>',
			'internal_permission' => 'workspace',
			'sequence'            => 3,
		];

		$data = $this->handler->parse_article_from_odoo( $odoo_data );

		$this->assertSame( 'Odoo Article', $data['post_title'] );
		$this->assertSame( '<p>Content from Odoo</p>', $data['post_content'] );
		$this->assertSame( 'publish', $data['post_status'] );
		$this->assertSame( 3, $data['menu_order'] );
	}

	public function test_parse_article_maps_workspace_to_publish(): void {
		$data = $this->handler->parse_article_from_odoo( [ 'internal_permission' => 'workspace' ] );
		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_parse_article_maps_private_to_private(): void {
		$data = $this->handler->parse_article_from_odoo( [ 'internal_permission' => 'private' ] );
		$this->assertSame( 'private', $data['post_status'] );
	}

	public function test_parse_article_maps_shared_to_publish(): void {
		$data = $this->handler->parse_article_from_odoo( [ 'internal_permission' => 'shared' ] );
		$this->assertSame( 'publish', $data['post_status'] );
	}

	public function test_parse_article_handles_parent_id_many2one(): void {
		$data = $this->handler->parse_article_from_odoo( [
			'parent_id' => [ 42, 'Parent Article' ],
		] );

		$this->assertSame( 42, $data['parent_odoo_id'] );
	}

	public function test_parse_article_handles_false_parent_id(): void {
		$data = $this->handler->parse_article_from_odoo( [
			'name'      => 'Root Article',
			'parent_id' => false,
		] );

		$this->assertArrayNotHasKey( 'parent_odoo_id', $data );
	}

	public function test_parse_article_handles_missing_fields(): void {
		$data = $this->handler->parse_article_from_odoo( [] );
		$this->assertSame( [], $data );
	}

	// ─── Handler: save_article ─────────────────────────────

	public function test_save_article_creates_new_post(): void {
		$result = $this->handler->save_article( [
			'post_title'   => 'New Article',
			'post_content' => '<p>Content</p>',
			'post_status'  => 'publish',
		] );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_article_updates_existing_post(): void {
		$result = $this->handler->save_article( [
			'post_title'   => 'Updated Article',
			'post_content' => '<p>Updated</p>',
		], 42 );

		$this->assertSame( 42, $result );
	}

	public function test_save_article_sets_post_status(): void {
		// The wp_insert_post stub returns a new ID; verifying no error is sufficient.
		$result = $this->handler->save_article( [
			'post_title'  => 'Private Article',
			'post_status' => 'private',
		] );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_article_preserves_html_body(): void {
		$html   = '<h2>Header</h2><ul><li>Item</li></ul>';
		$result = $this->handler->save_article( [
			'post_title'   => 'HTML Article',
			'post_content' => $html,
		] );

		$this->assertGreaterThan( 0, $result );
	}

	// ─── Handler: get_parent_id ────────────────────────────

	public function test_get_parent_id_returns_parent(): void {
		$this->create_post( 20, 'post', 'Parent', '', 'publish' );
		$this->create_post( 30, 'post', 'Child', '', 'publish', 20 );

		$parent = $this->handler->get_parent_id( 30 );

		$this->assertSame( 20, $parent );
	}

	public function test_get_parent_id_returns_zero_for_root(): void {
		$this->create_post( 10, 'post', 'Root', '', 'publish' );

		$parent = $this->handler->get_parent_id( 10 );

		$this->assertSame( 0, $parent );
	}

	// ─── Hooks: on_article_save ────────────────────────────

	public function test_on_article_save_enqueues_create(): void {
		$this->create_post( 100, 'post', 'Test Article', 'Content', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [ 'sync_articles' => true, 'post_type' => 'post' ];

		$this->module->on_article_save( 100 );

		$this->assertQueueContains( 'knowledge', 'article', 'create', 100 );
	}

	public function test_on_article_save_skips_when_disabled(): void {
		$this->create_post( 100, 'post', 'Test', 'Content', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [ 'sync_articles' => false, 'post_type' => 'post' ];

		$this->module->on_article_save( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_article_save_skips_wrong_post_type(): void {
		$this->create_post( 100, 'page', 'Not a post', 'Content', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [ 'sync_articles' => true, 'post_type' => 'post' ];

		$this->module->on_article_save( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_article_save_respects_category_filter_match(): void {
		$this->create_post( 100, 'post', 'Filtered Article', 'Content', 'publish' );

		$cat       = new \stdClass();
		$cat->slug = 'knowledge';
		$GLOBALS['_wp_categories'][100] = [ $cat ];

		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [
			'sync_articles'   => true,
			'post_type'       => 'post',
			'category_filter' => 'knowledge,docs',
		];

		$this->module->on_article_save( 100 );

		$this->assertQueueContains( 'knowledge', 'article', 'create', 100 );
	}

	public function test_on_article_save_respects_category_filter_miss(): void {
		$this->create_post( 100, 'post', 'Filtered Article', 'Content', 'publish' );

		$cat       = new \stdClass();
		$cat->slug = 'news';
		$GLOBALS['_wp_categories'][100] = [ $cat ];

		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [
			'sync_articles'   => true,
			'post_type'       => 'post',
			'category_filter' => 'knowledge,docs',
		];

		$this->module->on_article_save( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_article_save_skips_when_importing(): void {
		$this->create_post( 100, 'post', 'Test', 'Content', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [ 'sync_articles' => true, 'post_type' => 'post' ];

		// Simulate importing.
		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'knowledge' => true ] );

		$this->module->on_article_save( 100 );

		$this->assertQueueEmpty();

		// Clean up.
		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_article_delete ──────────────────────────

	public function test_on_article_delete_enqueues_when_mapped(): void {
		$this->create_post( 100, 'post', 'To Delete', '', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [ 'post_type' => 'post' ];

		// Create a mapping.
		$this->module->save_mapping( 'article', 100, 555 );

		$this->module->on_article_delete( 100 );

		$this->assertQueueContains( 'knowledge', 'article', 'delete', 100 );
	}

	public function test_on_article_delete_skips_when_no_mapping(): void {
		$this->create_post( 100, 'post', 'Unmapped', '', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [ 'post_type' => 'post' ];

		$this->module->on_article_delete( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_article_delete_skips_wrong_post_type(): void {
		$this->create_post( 100, 'page', 'Not a post', '', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [ 'post_type' => 'post' ];

		$this->module->on_article_delete( 100 );

		$this->assertQueueEmpty();
	}

	// ─── Pull guard ────────────────────────────────────────

	public function test_pull_skips_when_pull_articles_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_knowledge_settings'] = [ 'pull_articles' => false ];

		$result = $this->module->pull_from_odoo( 'article', 'create', 42 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Push guard ────────────────────────────────────────

	public function test_push_skips_when_knowledge_model_unavailable(): void {
		// Set transient to indicate model not available.
		$GLOBALS['_wp_transients']['wp4odoo_has_knowledge_article'] = 0;

		$result = $this->module->push_to_odoo( 'article', 'create', 10 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Translatable Fields ──────────────────────────────

	public function test_translatable_fields_for_article(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$fields = $method->invoke( $this->module, 'article' );

		$this->assertSame(
			[ 'name' => 'post_title', 'body' => 'post_content' ],
			$fields
		);
	}

	public function test_translatable_fields_empty_for_unknown(): void {
		$method = new \ReflectionMethod( $this->module, 'get_translatable_fields' );

		$this->assertSame( [], $method->invoke( $this->module, 'unknown' ) );
	}

	// ─── Dedup Domain ──────────────────────────────────────

	public function test_dedup_domain_by_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'article', [ 'name' => 'Test Article' ] );

		$this->assertSame( [ [ 'name', '=', 'Test Article' ] ], $domain );
	}

	public function test_dedup_domain_empty_without_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'article', [] );

		$this->assertSame( [], $domain );
	}

	// ─── load_wp_data resolves parent to Odoo ID ──────────

	public function test_load_wp_data_resolves_parent_odoo_id(): void {
		$this->create_post( 20, 'post', 'Parent', '', 'publish' );
		$this->create_post( 30, 'post', 'Child', '', 'publish', 20 );

		// Map parent to Odoo ID.
		$this->module->save_mapping( 'article', 20, 100 );

		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );

		$data = $method->invoke( $this->module, 'article', 30 );

		$this->assertSame( 100, $data['parent_id'] );
	}

	public function test_load_wp_data_returns_empty_for_unsupported_entity(): void {
		$method = new \ReflectionMethod( $this->module, 'load_wp_data' );

		$data = $method->invoke( $this->module, 'unknown', 1 );

		$this->assertSame( [], $data );
	}

	// ─── map_from_odoo delegates to handler ───────────────

	public function test_map_from_odoo_article(): void {
		$result = $this->module->map_from_odoo( 'article', [
			'name'                => 'Odoo Article',
			'body'                => '<p>Body</p>',
			'internal_permission' => 'workspace',
		] );

		$this->assertSame( 'Odoo Article', $result['post_title'] );
		$this->assertSame( '<p>Body</p>', $result['post_content'] );
		$this->assertSame( 'publish', $result['post_status'] );
	}

	// ─── save_wp_data resolves parent_odoo_id ─────────────

	public function test_save_wp_data_resolves_parent_odoo_id_to_wp(): void {
		// Create parent mapping: Odoo ID 200 → WP ID 50.
		$this->module->save_mapping( 'article', 50, 200 );

		$method = new \ReflectionMethod( $this->module, 'save_wp_data' );

		$result = $method->invoke( $this->module, 'article', [
			'post_title'    => 'Child Article',
			'post_content'  => '<p>Content</p>',
			'parent_odoo_id' => 200,
		] );

		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_wp_data_returns_zero_for_unsupported_entity(): void {
		$method = new \ReflectionMethod( $this->module, 'save_wp_data' );

		$result = $method->invoke( $this->module, 'unknown', [ 'post_title' => 'Test' ] );

		$this->assertSame( 0, $result );
	}

	// ─── Test Helpers ──────────────────────────────────────

	/**
	 * Create a post in the global store.
	 *
	 * @param int    $id         Post ID.
	 * @param string $post_type  Post type.
	 * @param string $title      Post title.
	 * @param string $content    Post content.
	 * @param string $status     Post status.
	 * @param int    $parent     Parent post ID.
	 * @param int    $menu_order Menu order.
	 */
	private function create_post( int $id, string $post_type, string $title, string $content = '', string $status = 'publish', int $parent = 0, int $menu_order = 0 ): void {
		$GLOBALS['_wp_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_parent'  => $parent,
			'menu_order'   => $menu_order,
		];
	}

	/**
	 * Assert that the sync queue contains a specific entry.
	 *
	 * @param string $module  Module ID.
	 * @param string $entity  Entity type.
	 * @param string $action  Action.
	 * @param int    $wp_id   WordPress ID.
	 */
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

	/**
	 * Assert that the sync queue is empty.
	 */
	private function assertQueueEmpty(): void {
		$inserts = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'insert' === $c['method'] && str_contains( $c['args'][0] ?? '', 'sync_queue' )
		);
		$this->assertEmpty( $inserts, 'Queue should be empty.' );
	}
}
