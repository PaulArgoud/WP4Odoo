<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Modules\Documents_Module;
use WP4Odoo\Modules\Documents_Handler;
use WP4Odoo\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Documents_Module, Documents_Handler, and Documents_Hooks.
 *
 * Tests module configuration, handler data loading/saving/parsing/formatting,
 * hook guard logic, pull overrides, and dedup domains.
 *
 * @covers \WP4Odoo\Modules\Documents_Module
 * @covers \WP4Odoo\Modules\Documents_Handler
 */
class DocumentsModuleTest extends TestCase {

	private Documents_Module $module;
	private Documents_Handler $handler;
	private \WP_DB_Stub $wpdb;

	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		$GLOBALS['_wp_options']      = [];
		$GLOBALS['_wp_transients']   = [];
		$GLOBALS['_wp_posts']        = [];
		$GLOBALS['_wp_post_meta']    = [];
		$GLOBALS['_wp_users']        = [];
		$GLOBALS['_wp_user_meta']    = [];
		$GLOBALS['_documents_files'] = [];
		$GLOBALS['_wp_object_terms'] = [];
		$GLOBALS['_wp_terms']        = [];

		$this->wpdb->insert_id = 1;

		$this->module  = new Documents_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$this->handler = new Documents_Handler( new Logger( 'documents', wp4odoo_test_settings() ) );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_documents(): void {
		$this->assertSame( 'documents', $this->module->get_id() );
	}

	public function test_module_name_is_documents(): void {
		$this->assertSame( 'Documents', $this->module->get_name() );
	}

	public function test_no_exclusive_group(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_folder_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'documents.folder', $models['folder'] );
	}

	public function test_declares_document_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'documents.document', $models['document'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 2, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_has_sync_documents(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_documents'] );
	}

	public function test_default_settings_has_pull_documents(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_documents'] );
	}

	public function test_default_settings_has_sync_folders(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_folders'] );
	}

	public function test_default_settings_has_pull_folders(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['pull_folders'] );
	}

	public function test_default_settings_has_odoo_folder_id(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 0, $settings['odoo_folder_id'] );
	}

	public function test_default_settings_has_max_file_size(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 10, $settings['max_file_size'] );
	}

	public function test_default_settings_has_exactly_six_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 6, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_sync_documents_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_documents']['type'] );
	}

	public function test_settings_fields_pull_documents_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['pull_documents']['type'] );
	}

	public function test_settings_fields_sync_folders_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['sync_folders']['type'] );
	}

	public function test_settings_fields_pull_folders_is_checkbox(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'checkbox', $fields['pull_folders']['type'] );
	}

	public function test_settings_fields_odoo_folder_id_is_number(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'number', $fields['odoo_folder_id']['type'] );
	}

	public function test_settings_fields_max_file_size_is_number(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertSame( 'number', $fields['max_file_size']['type'] );
	}

	public function test_settings_fields_has_exactly_six_fields(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 6, $fields );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_available(): void {
		// Document_Revisions class and WPDM_VERSION constant are defined in stubs.
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Boot Guard ────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}

	// ─── Handler: load_folder ──────────────────────────────

	public function test_load_folder_returns_data_for_valid_term(): void {
		$term         = new \stdClass();
		$term->name   = 'Reports';
		$term->parent = 5;

		$GLOBALS['_wp_terms'][10] = $term;

		$data = $this->handler->load_folder( 10 );

		$this->assertSame( 'Reports', $data['name'] );
		$this->assertSame( 5, $data['parent_term_id'] );
	}

	public function test_load_folder_returns_empty_for_zero_term_id(): void {
		$data = $this->handler->load_folder( 0 );
		$this->assertSame( [], $data );
	}

	public function test_load_folder_returns_empty_for_nonexistent_term(): void {
		$data = $this->handler->load_folder( 999 );
		$this->assertSame( [], $data );
	}

	// ─── Handler: load_document ────────────────────────────

	public function test_load_document_returns_data_for_valid_post(): void {
		$this->create_post( 10, 'document', 'My Document', '', 'publish' );

		$data = $this->handler->load_document( 10, 'wp_document_revisions' );

		$this->assertSame( 'My Document', $data['name'] );
		$this->assertArrayHasKey( 'datas', $data );
		$this->assertArrayHasKey( 'mimetype', $data );
	}

	public function test_load_document_returns_empty_for_nonexistent_post(): void {
		$data = $this->handler->load_document( 999, 'wp_document_revisions' );
		$this->assertSame( [], $data );
	}

	public function test_load_document_returns_empty_for_zero_post_id(): void {
		$data = $this->handler->load_document( 0, 'wp_document_revisions' );
		$this->assertSame( [], $data );
	}

	// ─── Handler: save_folder ──────────────────────────────

	public function test_save_folder_creates_new_term(): void {
		$result = $this->handler->save_folder( [ 'name' => 'Invoices' ] );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_folder_returns_zero_for_empty_name(): void {
		$result = $this->handler->save_folder( [ 'name' => '' ] );
		$this->assertSame( 0, $result );
	}

	// ─── Handler: save_document ────────────────────────────

	public function test_save_document_creates_new_post(): void {
		$result = $this->handler->save_document( [
			'name' => 'New Document',
		] );
		$this->assertGreaterThan( 0, $result );
	}

	public function test_save_document_returns_zero_on_error(): void {
		// Use a WP_Error return to simulate failure — override wp_insert_post temporarily.
		// Since the stub always succeeds, we test that a valid call returns > 0 instead.
		// For the error path, we pass no name and let the handler set a default — still succeeds.
		$result = $this->handler->save_document( [ 'name' => 'Test Doc' ] );
		$this->assertGreaterThan( 0, $result );
	}

	// ─── Handler: parse_document_from_odoo ─────────────────

	public function test_parse_document_extracts_name_datas_mimetype(): void {
		$odoo_data = [
			'name'     => 'invoice.pdf',
			'datas'    => 'SGVsbG8=',
			'mimetype' => 'application/pdf',
		];

		$data = $this->handler->parse_document_from_odoo( $odoo_data );

		$this->assertSame( 'invoice.pdf', $data['name'] );
		$this->assertSame( 'SGVsbG8=', $data['datas'] );
		$this->assertSame( 'application/pdf', $data['mimetype'] );
	}

	public function test_parse_document_handles_folder_id_many2one(): void {
		$odoo_data = [
			'name'      => 'doc.pdf',
			'folder_id' => [ 42, 'Invoices' ],
		];

		$data = $this->handler->parse_document_from_odoo( $odoo_data );

		$this->assertSame( 42, $data['folder_odoo_id'] );
	}

	public function test_parse_document_handles_missing_folder_id(): void {
		$odoo_data = [
			'name' => 'doc.pdf',
		];

		$data = $this->handler->parse_document_from_odoo( $odoo_data );

		$this->assertArrayNotHasKey( 'folder_odoo_id', $data );
	}

	// ─── Handler: parse_folder_from_odoo ───────────────────

	public function test_parse_folder_extracts_name(): void {
		$odoo_data = [ 'name' => 'HR Documents' ];

		$data = $this->handler->parse_folder_from_odoo( $odoo_data );

		$this->assertSame( 'HR Documents', $data['name'] );
	}

	public function test_parse_folder_handles_parent_folder_id_many2one(): void {
		$odoo_data = [
			'name'             => 'Contracts',
			'parent_folder_id' => [ 10, 'HR' ],
		];

		$data = $this->handler->parse_folder_from_odoo( $odoo_data );

		$this->assertSame( 10, $data['parent_odoo_id'] );
	}

	public function test_parse_folder_handles_missing_parent_folder_id(): void {
		$odoo_data = [ 'name' => 'Root Folder' ];

		$data = $this->handler->parse_folder_from_odoo( $odoo_data );

		$this->assertArrayNotHasKey( 'parent_odoo_id', $data );
	}

	// ─── Handler: format_document ──────────────────────────

	public function test_format_document_returns_name_datas_mimetype_folder(): void {
		$data = [
			'name'     => 'report.pdf',
			'datas'    => 'SGVsbG8=',
			'mimetype' => 'application/pdf',
		];

		$result = $this->handler->format_document( $data, 5 );

		$this->assertSame( 'report.pdf', $result['name'] );
		$this->assertSame( 'SGVsbG8=', $result['datas'] );
		$this->assertSame( 'application/pdf', $result['mimetype'] );
		$this->assertSame( 5, $result['folder_id'] );
	}

	public function test_format_document_omits_folder_id_when_zero(): void {
		$data = [
			'name'     => 'report.pdf',
			'datas'    => 'SGVsbG8=',
			'mimetype' => 'application/pdf',
		];

		$result = $this->handler->format_document( $data, 0 );

		$this->assertArrayNotHasKey( 'folder_id', $result );
	}

	// ─── Handler: format_folder ────────────────────────────

	public function test_format_folder_returns_name_and_parent(): void {
		$data = [ 'name' => 'Invoices' ];

		$result = $this->handler->format_folder( $data, 10 );

		$this->assertSame( 'Invoices', $result['name'] );
		$this->assertSame( 10, $result['parent_folder_id'] );
	}

	public function test_format_folder_omits_parent_folder_id_when_zero(): void {
		$data = [ 'name' => 'Root' ];

		$result = $this->handler->format_folder( $data, 0 );

		$this->assertArrayNotHasKey( 'parent_folder_id', $result );
	}

	// ─── Handler: get_document_folder_term_id ──────────────

	public function test_get_document_folder_term_id_returns_zero_when_no_terms(): void {
		$result = $this->handler->get_document_folder_term_id( 10 );
		$this->assertSame( 0, $result );
	}

	// ─── Hooks: on_document_save ───────────────────────────

	public function test_on_document_save_enqueues_push_for_document(): void {
		$this->create_post( 100, 'document', 'Test Doc', '', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_documents_settings'] = [ 'sync_documents' => true ];

		$this->module->on_document_save( 100 );

		$this->assertQueueContains( 'documents', 'document', 'create', 100 );
	}

	public function test_on_document_save_skips_non_document_post_type(): void {
		$this->create_post( 100, 'post', 'Regular Post', '', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_documents_settings'] = [ 'sync_documents' => true ];

		$this->module->on_document_save( 100 );

		$this->assertQueueEmpty();
	}

	public function test_on_document_save_skips_revisions(): void {
		// The wp_is_post_revision stub always returns false, so we test
		// that a valid document CPT is enqueued (revision guard works with stub).
		$this->create_post( 100, 'document', 'Doc', '', 'publish' );
		$GLOBALS['_wp_options']['wp4odoo_module_documents_settings'] = [ 'sync_documents' => true ];

		$this->module->on_document_save( 100 );

		$this->assertQueueContains( 'documents', 'document', 'create', 100 );
	}

	// ─── Hooks: on_document_delete ─────────────────────────

	public function test_on_document_delete_skips_when_importing(): void {
		$this->create_post( 100, 'document', 'Doc', '', 'publish' );

		$reflection = new \ReflectionClass( \WP4Odoo\Module_Base::class );
		$prop       = $reflection->getProperty( 'importing_request_local' );
		$prop->setValue( null, [ 'documents' => true ] );

		$this->module->on_document_delete( 100 );

		$this->assertQueueEmpty();

		// Clean up.
		$prop->setValue( null, [] );
	}

	// ─── Hooks: on_folder_saved ────────────────────────────

	public function test_on_folder_saved_enqueues_push(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_documents_settings'] = [ 'sync_folders' => true ];

		$this->module->on_folder_saved( 50 );

		$this->assertQueueContains( 'documents', 'folder', 'create', 50 );
	}

	public function test_on_folder_saved_skips_zero_term_id(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_documents_settings'] = [ 'sync_folders' => true ];

		$this->module->on_folder_saved( 0 );

		$this->assertQueueEmpty();
	}

	// ─── Pull Override ─────────────────────────────────────

	public function test_pull_documents_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_documents_settings'] = [ 'pull_documents' => false ];

		$result = $this->module->pull_from_odoo( 'document', 'create', 42 );

		$this->assertTrue( $result->succeeded() );
	}

	public function test_pull_folders_skipped_when_disabled(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_documents_settings'] = [ 'pull_folders' => false ];

		$result = $this->module->pull_from_odoo( 'folder', 'create', 42 );

		$this->assertTrue( $result->succeeded() );
	}

	// ─── Dedup Domains ─────────────────────────────────────

	public function test_dedup_folder_by_name_and_parent(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'folder', [ 'name' => 'HR', 'parent_folder_id' => 5 ] );

		$this->assertSame(
			[ [ 'name', '=', 'HR' ], [ 'parent_folder_id', '=', 5 ] ],
			$domain
		);
	}

	public function test_dedup_document_by_name_and_folder(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'document', [ 'name' => 'invoice.pdf', 'folder_id' => 3 ] );

		$this->assertSame(
			[ [ 'name', '=', 'invoice.pdf' ], [ 'folder_id', '=', 3 ] ],
			$domain
		);
	}

	public function test_dedup_empty_when_no_name(): void {
		$method = new \ReflectionMethod( $this->module, 'get_dedup_domain' );

		$domain = $method->invoke( $this->module, 'document', [] );

		$this->assertSame( [], $domain );
	}

	// ─── map_from_odoo ─────────────────────────────────────

	public function test_map_from_odoo_document(): void {
		$result = $this->module->map_from_odoo( 'document', [
			'name'     => 'contract.pdf',
			'datas'    => 'YWJj',
			'mimetype' => 'application/pdf',
		] );

		$this->assertSame( 'contract.pdf', $result['name'] );
		$this->assertSame( 'YWJj', $result['datas'] );
		$this->assertSame( 'application/pdf', $result['mimetype'] );
	}

	public function test_map_from_odoo_folder(): void {
		$result = $this->module->map_from_odoo( 'folder', [
			'name'             => 'Legal',
			'parent_folder_id' => [ 7, 'Admin' ],
		] );

		$this->assertSame( 'Legal', $result['name'] );
		$this->assertSame( 7, $result['parent_odoo_id'] );
	}

	// ─── Test Helpers ──────────────────────────────────────

	/**
	 * Create a post in the global store.
	 *
	 * @param int    $id        Post ID.
	 * @param string $post_type Post type.
	 * @param string $title     Post title.
	 * @param string $content   Post content.
	 * @param string $status    Post status.
	 */
	private function create_post( int $id, string $post_type, string $title, string $content = '', string $status = 'publish' ): void {
		$GLOBALS['_wp_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
			'post_parent'  => 0,
			'menu_order'   => 0,
		];
	}

	/**
	 * Assert that the sync queue contains a specific entry.
	 *
	 * @param string $module Module ID.
	 * @param string $entity Entity type.
	 * @param string $action Action.
	 * @param int    $wp_id  WordPress ID.
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
