<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documents Module — bidirectional WordPress ↔ Odoo Documents sync.
 *
 * Syncs documents between WordPress (WP Document Revisions or WP Download
 * Manager) and Odoo Documents (Enterprise). Supports file content via base64
 * encoding, folder hierarchy, and change detection via SHA-256 hashes.
 *
 * Entity types:
 * - folder   → documents.folder (bidirectional)
 * - document → documents.document (bidirectional with file content)
 *
 * Odoo Documents module (Enterprise) must be installed. Folder hierarchy
 * is preserved via parent_folder_id mapping.
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class Documents_Module extends Module_Base {

	use Documents_Hooks;

	protected const PLUGIN_MIN_VERSION  = '3.0';
	protected const PLUGIN_TESTED_UP_TO = '3.6';

	/**
	 * Sync direction: bidirectional.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'folder'   => 'documents.folder',
		'document' => 'documents.document',
	];

	/**
	 * Default field mappings.
	 *
	 * Both folders and documents are pre-formatted by the handler,
	 * so the mapping is identity.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'folder'   => [
			'name'             => 'name',
			'parent_folder_id' => 'parent_folder_id',
		],
		'document' => [
			'name'      => 'name',
			'datas'     => 'datas',
			'mimetype'  => 'mimetype',
			'folder_id' => 'folder_id',
		],
	];

	/**
	 * Documents data handler.
	 *
	 * @var Documents_Handler
	 */
	private Documents_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'documents', 'Documents', $client_provider, $entity_map, $settings );
		$this->handler = new Documents_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WordPress hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'Document_Revisions' ) && ! class_exists( 'WP_Document_Revisions' ) && ! defined( 'WPDM_VERSION' ) ) {
			$this->logger->warning( __( 'Documents module enabled but neither WP Document Revisions nor WP Download Manager is active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_documents'] ) ) {
			// WP Document Revisions uses 'document' CPT.
			add_action( 'save_post_document', $this->safe_callback( [ $this, 'on_document_save' ] ), 10, 1 );
			// WP Download Manager uses 'wpdmpro' CPT.
			add_action( 'save_post_wpdmpro', $this->safe_callback( [ $this, 'on_document_save' ] ), 10, 1 );
			add_action( 'before_delete_post', $this->safe_callback( [ $this, 'on_document_delete' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_folders'] ) ) {
			add_action( 'created_document_category', $this->safe_callback( [ $this, 'on_folder_saved' ] ), 10, 1 );
			add_action( 'edited_document_category', $this->safe_callback( [ $this, 'on_folder_saved' ] ), 10, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_documents' => true,
			'pull_documents' => true,
			'sync_folders'   => true,
			'pull_folders'   => true,
			'odoo_folder_id' => 0,
			'max_file_size'  => 10,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_documents' => [
				'label'       => __( 'Sync documents', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WordPress documents to Odoo Documents.', 'wp4odoo' ),
			],
			'pull_documents' => [
				'label'       => __( 'Pull documents from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo Documents back to WordPress.', 'wp4odoo' ),
			],
			'sync_folders'   => [
				'label'       => __( 'Sync folders', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push document categories as Odoo document folders.', 'wp4odoo' ),
			],
			'pull_folders'   => [
				'label'       => __( 'Pull folders from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull Odoo document folders back as WordPress categories.', 'wp4odoo' ),
			],
			'odoo_folder_id' => [
				'label'       => __( 'Odoo root folder ID', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'The ID of the root Odoo document folder. Leave 0 to use the root.', 'wp4odoo' ),
			],
			'max_file_size'  => [
				'label'       => __( 'Max file size (MB)', 'wp4odoo' ),
				'type'        => 'number',
				'description' => __( 'Maximum file size in MB for document sync. Default: 10 MB.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency(
			class_exists( 'Document_Revisions' ) || class_exists( 'WP_Document_Revisions' ) || defined( 'WPDM_VERSION' ),
			'WP Document Revisions / WP Download Manager'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		if ( defined( 'WPDM_VERSION' ) ) {
			return (string) WPDM_VERSION;
		}

		return '';
	}

	// ─── Model detection ──────────────────────────────────

	/**
	 * Check whether Odoo has the documents.document model (Enterprise).
	 *
	 * @return bool
	 */
	private function has_documents_model(): bool {
		return $this->has_odoo_model( Odoo_Model::DocumentsDocument, 'wp4odoo_has_documents_document' );
	}

	/**
	 * Get the configured root Odoo folder ID.
	 *
	 * @return int Folder ID (0 if not configured).
	 */
	private function get_root_folder_id(): int {
		$settings = $this->get_settings();

		return (int) ( $settings['odoo_folder_id'] ?? 0 );
	}

	/**
	 * Detect which document source plugin is active.
	 *
	 * @return string 'wp_document_revisions', 'wpdm', or ''.
	 */
	private function detect_source(): string {
		if ( class_exists( 'Document_Revisions' ) || class_exists( 'WP_Document_Revisions' ) ) {
			return 'wp_document_revisions';
		}
		if ( defined( 'WPDM_VERSION' ) ) {
			return 'wpdm';
		}

		return '';
	}

	// ─── Push override ────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Guards with has_documents_model() — only pushes if Odoo has the
	 * documents module (Enterprise). Ensures parent folder is synced
	 * before pushing documents.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo record ID (0 if creating).
	 * @param array  $payload     Additional data from the queue.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'delete' !== $action && ! $this->has_documents_model() ) {
			$this->logger->info( 'documents.document not available — skipping push.', [ 'wp_id' => $wp_id ] );
			return \WP4Odoo\Sync_Result::success();
		}

		// Ensure parent folder is synced first for documents.
		if ( 'delete' !== $action && 'document' === $entity_type ) {
			$folder_term_id = $this->handler->get_document_folder_term_id( $wp_id );
			if ( $folder_term_id > 0 ) {
				$this->ensure_entity_synced( 'folder', $folder_term_id );
			}
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	// ─── Pull override ────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Respects pull_documents / pull_folders settings.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$settings = $this->get_settings();

		if ( 'document' === $entity_type && empty( $settings['pull_documents'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		if ( 'folder' === $entity_type && empty( $settings['pull_folders'] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	// ─── Data access ──────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		if ( 'document' === $entity_type ) {
			$source = $this->detect_source();
			$data   = $this->handler->load_document( $wp_id, $source );

			if ( empty( $data ) ) {
				return [];
			}

			// Resolve folder term → Odoo folder_id.
			$folder_term_id = $this->handler->get_document_folder_term_id( $wp_id );
			$folder_odoo_id = 0;
			if ( $folder_term_id > 0 ) {
				$folder_odoo_id = $this->get_mapping( 'folder', $folder_term_id ) ?? 0;
			}
			if ( $folder_odoo_id <= 0 ) {
				$folder_odoo_id = $this->get_root_folder_id();
			}

			return $this->handler->format_document( $data, $folder_odoo_id );
		}

		if ( 'folder' === $entity_type ) {
			$data = $this->handler->load_folder( $wp_id );

			if ( empty( $data ) ) {
				return [];
			}

			// Resolve parent term → Odoo parent_folder_id.
			$parent_term_id = (int) ( $data['parent_term_id'] ?? 0 );
			$parent_odoo_id = 0;
			if ( $parent_term_id > 0 ) {
				$parent_odoo_id = $this->get_mapping( 'folder', $parent_term_id ) ?? 0;
			}
			if ( $parent_odoo_id <= 0 ) {
				$parent_odoo_id = $this->get_root_folder_id();
			}

			return $this->handler->format_folder( $data, $parent_odoo_id );
		}

		return [];
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		if ( 'document' === $entity_type ) {
			return $this->handler->parse_document_from_odoo( $odoo_data );
		}

		if ( 'folder' === $entity_type ) {
			return $this->handler->parse_folder_from_odoo( $odoo_data );
		}

		return parent::map_from_odoo( $entity_type, $odoo_data );
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		if ( 'document' === $entity_type ) {
			// Resolve folder_odoo_id → WP term ID.
			if ( isset( $data['folder_odoo_id'] ) ) {
				$folder_wp_id = $this->get_wp_mapping( 'folder', (int) $data['folder_odoo_id'] );
				if ( $folder_wp_id ) {
					$data['folder_term_id'] = $folder_wp_id;
				}
				unset( $data['folder_odoo_id'] );
			}

			$source = $this->detect_source();

			return $this->handler->save_document( $data, $wp_id, $source );
		}

		if ( 'folder' === $entity_type ) {
			// Resolve parent_odoo_id → WP term ID.
			if ( isset( $data['parent_odoo_id'] ) ) {
				$parent_wp_id = $this->get_wp_mapping( 'folder', (int) $data['parent_odoo_id'] );
				if ( $parent_wp_id ) {
					$data['parent_term_id'] = $parent_wp_id;
				}
				unset( $data['parent_odoo_id'] );
			}

			return $this->handler->save_folder( $data, $wp_id );
		}

		return 0;
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'document' === $entity_type ) {
			return $this->delete_wp_post( $wp_id );
		}

		if ( 'folder' === $entity_type ) {
			$result = wp_delete_term( $wp_id, 'document_category' );

			return true === $result;
		}

		return false;
	}

	// ─── Deduplication ─────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'folder' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			$domain = [ [ 'name', '=', $odoo_values['name'] ] ];
			if ( ! empty( $odoo_values['parent_folder_id'] ) ) {
				$domain[] = [ 'parent_folder_id', '=', $odoo_values['parent_folder_id'] ];
			}

			return $domain;
		}

		if ( 'document' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			$domain = [ [ 'name', '=', $odoo_values['name'] ] ];
			if ( ! empty( $odoo_values['folder_id'] ) ) {
				$domain[] = [ 'folder_id', '=', $odoo_values['folder_id'] ];
			}

			return $domain;
		}

		return [];
	}
}
