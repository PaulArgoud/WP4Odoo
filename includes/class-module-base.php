<?php
declare( strict_types=1 );

namespace WP4Odoo;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Field_Mapper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all synchronization modules.
 *
 * Provides shared infrastructure for pushing/pulling entities between
 * WordPress and Odoo, entity mapping, and sync hash management.
 *
 * Shared helpers (auto_post_invoice, ensure_entity_synced, synthetic IDs,
 * partner_service, check_dependency, etc.) are in the Module_Helpers trait.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
abstract class Module_Base {

	use Module_Helpers;

	/**
	 * Unique module identifier (e.g., 'crm', 'sales', 'woocommerce').
	 *
	 * @var string
	 */
	protected string $id;

	/**
	 * Human-readable module name.
	 *
	 * @var string
	 */
	protected string $name;

	/**
	 * Odoo models this module interacts with.
	 *
	 * Keys are entity types, values are Odoo model names.
	 * Example: ['contact' => 'res.partner', 'lead' => 'crm.lead']
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [];

	/**
	 * Default field mappings per entity type.
	 *
	 * Structure: [entity_type => [wp_field => odoo_field, ...]]
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [];

	/**
	 * Exclusive group name.
	 *
	 * Modules in the same group cannot be active simultaneously.
	 * Empty string means no exclusivity constraint.
	 * Subclasses override to declare their group (e.g. 'commerce', 'memberships').
	 *
	 * @var string
	 */
	protected string $exclusive_group = '';

	/**
	 * Priority within the exclusive group (higher = takes precedence).
	 *
	 * When multiple modules in the same group are enabled, only the one
	 * with the highest priority is booted.
	 *
	 * @var int
	 */
	protected int $exclusive_priority = 0;

	/**
	 * Minimum supported version of the third-party plugin.
	 *
	 * When non-empty and the detected plugin version is lower,
	 * the module is marked as unavailable (admin UI + boot prevented).
	 * Subclasses override with their own value.
	 *
	 * @var string
	 */
	protected const PLUGIN_MIN_VERSION = '';

	/**
	 * Last tested version of the third-party plugin.
	 *
	 * When non-empty and the detected plugin version is higher,
	 * the module still boots but an admin warning is displayed.
	 * Subclasses override with their own value.
	 *
	 * @var string
	 */
	protected const PLUGIN_TESTED_UP_TO = '';

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * In-memory cache for field mappings (avoids repeated get_option calls).
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $mapping_cache = [];

	/**
	 * Translation buffer for pull operations.
	 *
	 * Accumulates (odoo_id → wp_id) pairs during batch pull, keyed by
	 * Odoo model. Flushed at end of batch via flush_pull_translations().
	 *
	 * @var array<string, array<int, int>>
	 */
	protected array $translation_buffer = [];

	/**
	 * Per-module anti-loop flags: tracks which modules are currently importing.
	 *
	 * Unlike a single global bool, this allows module A to import without
	 * blocking hook callbacks on unrelated module B. Each module sets its
	 * own flag via mark_importing() / clear_importing().
	 *
	 * LIMITATION: This flag is process-local (PHP static). In concurrent
	 * PHP-FPM workers, process A pulling from Odoo cannot prevent process B
	 * from re-enqueuing via a WP hook. The queue's dedup mechanism
	 * (SELECT…FOR UPDATE in Sync_Queue_Repository::enqueue()) is the
	 * definitive safety net against duplicate jobs.
	 *
	 * @var array<string, bool>
	 */
	private static array $importing = [];

	/**
	 * Closure that returns the Odoo_Client instance (lazy, injected by Module_Registry).
	 *
	 * @var \Closure(): Odoo_Client
	 */
	private \Closure $client_provider;

	/**
	 * Entity map repository (injected by Module_Registry).
	 *
	 * Accessible to subclasses for cross-module lookups (e.g. WPAI routing,
	 * meta-modules). Direct entity mapping should use the public get_mapping()
	 * / save_mapping() / remove_mapping() methods instead.
	 *
	 * @var Entity_Map_Repository
	 */
	protected Entity_Map_Repository $entity_map;

	/**
	 * Settings repository (injected by Module_Registry).
	 *
	 * Accessible to subclasses for cross-module settings checks (e.g. WPAI
	 * verifying whether a target module is enabled).
	 *
	 * @var Settings_Repository
	 */
	protected Settings_Repository $settings_repo;

	/**
	 * Constructor.
	 *
	 * @param string                $id              Unique module identifier (e.g. 'crm').
	 * @param string                $name            Human-readable module name.
	 * @param \Closure              $client_provider Returns the shared Odoo_Client instance.
	 * @param Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( string $id, string $name, \Closure $client_provider, Entity_Map_Repository $entity_map, Settings_Repository $settings ) {
		$this->id              = $id;
		$this->name            = $name;
		$this->client_provider = $client_provider;
		$this->entity_map      = $entity_map;
		$this->settings_repo   = $settings;
		$this->logger          = new Logger( $this->id, $settings );
	}

	/**
	 * Boot the module: register WordPress hooks.
	 *
	 * Called only when the module is enabled.
	 *
	 * @return void
	 */
	abstract public function boot(): void;

	/**
	 * Get default settings for this module.
	 *
	 * @return array
	 */
	abstract public function get_default_settings(): array;

	// -------------------------------------------------------------------------
	// Push / Pull
	// -------------------------------------------------------------------------

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Called by the Sync_Engine when processing a wp_to_odoo job.
	 *
	 * @param string $entity_type The entity type (e.g., 'product').
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data from the queue.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		$model = $this->get_odoo_model( $entity_type );

		if ( 'delete' === $action ) {
			if ( $odoo_id > 0 ) {
				$this->client()->unlink( $model, [ $odoo_id ] );
				$this->remove_mapping( $entity_type, $wp_id );
				$this->logger->info( 'Deleted Odoo record.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
			}
			return Sync_Result::success( $odoo_id );
		}

		$wp_data                  = ! empty( $payload ) ? $payload : $this->load_wp_data( $entity_type, $wp_id );
		$wp_data['_wp_entity_id'] = $wp_id;
		$odoo_values              = $this->map_to_odoo( $entity_type, $wp_data );

		if ( empty( $odoo_values ) ) {
			$this->logger->warning( 'No data to push.', compact( 'entity_type', 'wp_id' ) );
			return Sync_Result::failure( 'No data to push.', Error_Type::Permanent );
		}

		$new_hash = $this->generate_sync_hash( $odoo_values );

		// Check for existing mapping (might have been created since enqueue).
		if ( 'create' === $action || 0 === $odoo_id ) {
			$existing_odoo_id = $this->get_mapping( $entity_type, $wp_id );
			if ( $existing_odoo_id ) {
				$odoo_id = $existing_odoo_id;
				$action  = 'update';
			}
		}

		if ( 'update' === $action && $odoo_id > 0 ) {
			$this->client()->write( $model, [ $odoo_id ], $odoo_values );
			if ( ! $this->save_mapping( $entity_type, $wp_id, $odoo_id, $new_hash ) ) {
				$this->logger->error( 'Mapping save failed after Odoo update.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
				return Sync_Result::failure( 'Mapping save failed after Odoo update.', Error_Type::Transient, $odoo_id );
			}
			$this->logger->info( 'Updated Odoo record.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
		} else {
			// Dedup: search Odoo for an existing record before creating.
			// Catches orphaned records from a previous attempt whose
			// save_mapping() failed after a successful create().
			$dedup_domain = $this->get_dedup_domain( $entity_type, $odoo_values );
			if ( ! empty( $dedup_domain ) ) {
				$existing = $this->client()->search( $model, $dedup_domain, 0, 1 );
				if ( ! empty( $existing ) ) {
					$odoo_id = $existing[0];
					$this->logger->info( 'Dedup: found existing Odoo record, switching to update.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
					$this->client()->write( $model, [ $odoo_id ], $odoo_values );
					$this->save_mapping( $entity_type, $wp_id, $odoo_id, $new_hash );
					return Sync_Result::success( $odoo_id );
				}
			}

			$odoo_id = $this->client()->create( $model, $odoo_values );
			$saved   = $this->save_mapping( $entity_type, $wp_id, $odoo_id, $new_hash );
			if ( ! $saved ) {
				$this->logger->error( 'Mapping save failed after Odoo create.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
				return Sync_Result::failure( 'Mapping save failed after Odoo create.', Error_Type::Transient, $odoo_id );
			}
			$this->logger->info( 'Created Odoo record.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
		}

		return Sync_Result::success( $odoo_id );
	}

	/**
	 * Batch-create multiple entities in a single Odoo API call.
	 *
	 * Loads data and maps values for each item, then calls create_batch()
	 * on the Odoo client. Falls back to individual push_to_odoo() calls
	 * if the batch call fails.
	 *
	 * @param string                                   $entity_type Entity type.
	 * @param array<int, array{wp_id: int, payload: array<string, mixed>}> $items Items to create.
	 * @return array<int, Sync_Result> Results indexed by wp_id.
	 */
	public function push_batch_creates( string $entity_type, array $items ): array {
		$model       = $this->get_odoo_model( $entity_type );
		$results     = [];
		$values_list = [];
		$item_map    = []; // Ordered list of items that passed validation.

		foreach ( $items as $item ) {
			$wp_id = $item['wp_id'];

			// Skip if already mapped (might have been created since enqueue).
			$existing = $this->get_mapping( $entity_type, $wp_id );
			if ( $existing ) {
				$results[ $wp_id ] = Sync_Result::success( $existing );
				continue;
			}

			$wp_data                  = ! empty( $item['payload'] ) ? $item['payload'] : $this->load_wp_data( $entity_type, $wp_id );
			$wp_data['_wp_entity_id'] = $wp_id;
			$odoo_values              = $this->map_to_odoo( $entity_type, $wp_data );

			if ( empty( $odoo_values ) ) {
				$results[ $wp_id ] = Sync_Result::failure( 'No data to push.', Error_Type::Permanent );
				continue;
			}

			$values_list[] = $odoo_values;
			$item_map[]    = [
				'wp_id' => $wp_id,
				'hash'  => $this->generate_sync_hash( $odoo_values ),
			];
		}

		if ( empty( $values_list ) ) {
			return $results;
		}

		try {
			$ids = $this->client()->create_batch( $model, $values_list );

			foreach ( $ids as $i => $odoo_id ) {
				$wp_id = $item_map[ $i ]['wp_id'];
				$hash  = $item_map[ $i ]['hash'];
				$this->save_mapping( $entity_type, $wp_id, $odoo_id, $hash );
				$results[ $wp_id ] = Sync_Result::success( $odoo_id );
			}

			$this->logger->info(
				'Batch created Odoo records.',
				[
					'entity_type' => $entity_type,
					'count'       => count( $ids ),
				]
			);
		} catch ( \Throwable $e ) {
			// Fallback: process individually.
			$this->logger->warning(
				'Batch create failed, falling back to individual creates.',
				[
					'entity_type' => $entity_type,
					'error'       => $e->getMessage(),
				]
			);

			foreach ( $item_map as $entry ) {
				if ( ! isset( $results[ $entry['wp_id'] ] ) ) {
					$results[ $entry['wp_id'] ] = $this->push_to_odoo( $entity_type, 'create', $entry['wp_id'] );
				}
			}
		}

		return $results;
	}

	/**
	 * Pull an Odoo entity into WordPress.
	 *
	 * Called by the Sync_Engine when processing an odoo_to_wp job.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo entity ID.
	 * @param int    $wp_id       WordPress ID (0 if creating).
	 * @param array  $payload     Additional data from the queue.
	 * @return Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): Sync_Result {
		$this->mark_importing();

		try {
			$model = $this->get_odoo_model( $entity_type );

			if ( 'delete' === $action ) {
				if ( 0 === $wp_id ) {
					$wp_id = $this->get_wp_mapping( $entity_type, $odoo_id ) ?? 0;
				}
				if ( $wp_id > 0 ) {
					$this->delete_wp_data( $entity_type, $wp_id );
					$this->remove_mapping( $entity_type, $wp_id );
					$this->logger->info( 'Deleted WP entity from Odoo signal.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
				}
				return Sync_Result::success( $wp_id );
			}

			// Fetch fresh data from Odoo.
			$records = $this->client()->read( $model, [ $odoo_id ] );

			if ( empty( $records ) ) {
				$this->logger->warning( 'Odoo record not found during pull.', compact( 'entity_type', 'odoo_id' ) );
				return Sync_Result::failure( 'Odoo record not found during pull.', Error_Type::Permanent );
			}

			$odoo_data = $records[0];
			$wp_data   = $this->map_from_odoo( $entity_type, $odoo_data );

			/**
			 * Filter WordPress data after mapping from Odoo.
			 *
			 * @since 1.0.0
			 *
			 * @param array  $wp_data     The mapped WordPress data.
			 * @param array  $odoo_data   The raw Odoo record data.
			 * @param string $entity_type The entity type.
			 */
			$wp_data = apply_filters( "wp4odoo_map_from_odoo_{$this->id}_{$entity_type}", $wp_data, $odoo_data, $entity_type );

			// Find existing WP entity.
			if ( 0 === $wp_id ) {
				$wp_id = $this->get_wp_mapping( $entity_type, $odoo_id ) ?? 0;
			}

			$wp_id = $this->save_wp_data( $entity_type, $wp_data, $wp_id );

			if ( $wp_id > 0 ) {
				/**
				 * Fires after a WordPress entity has been saved during an Odoo pull.
				 *
				 * Allows meta-modules (e.g. ACF) to write additional data
				 * that requires the WP entity ID to exist.
				 *
				 * @since 3.1.0
				 *
				 * @param int    $wp_id       The saved WordPress entity ID.
				 * @param array  $wp_data     The mapped WordPress data.
				 * @param string $entity_type The entity type.
				 * @param array  $odoo_data   The raw Odoo record data.
				 */
				do_action( "wp4odoo_after_save_{$this->id}_{$entity_type}", $wp_id, $wp_data, $entity_type, $odoo_data );

				$new_hash = $this->generate_sync_hash( $odoo_data );
				$this->save_mapping( $entity_type, $wp_id, $odoo_id, $new_hash );

				// Accumulate for batch translation flush if module provides translatable fields.
				if ( ! empty( $this->get_translatable_fields( $entity_type ) ) ) {
					$this->accumulate_pull_translation( $model, $odoo_id, $wp_id );
				}

				$this->logger->info( 'Pulled from Odoo.', compact( 'entity_type', 'wp_id', 'odoo_id' ) );
				return Sync_Result::success( $wp_id );
			}

			$this->logger->error( 'Failed to save WP data during pull.', compact( 'entity_type', 'odoo_id' ) );
			return Sync_Result::failure( 'Failed to save WP data during pull.', Error_Type::Permanent );
		} finally {
			$this->clear_importing();
		}
	}

	// -------------------------------------------------------------------------
	// Entity Mapping (entity_map table)
	// -------------------------------------------------------------------------

	/**
	 * Get the Odoo ID mapped to a WordPress entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return int|null The Odoo ID, or null if not mapped.
	 */
	public function get_mapping( string $entity_type, int $wp_id ): ?int {
		return $this->entity_map->get_odoo_id( $this->id, $entity_type, $wp_id );
	}

	/**
	 * Get the WordPress ID mapped to an Odoo entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $odoo_id     Odoo ID.
	 * @return int|null The WordPress ID, or null if not mapped.
	 */
	public function get_wp_mapping( string $entity_type, int $odoo_id ): ?int {
		return $this->entity_map->get_wp_id( $this->id, $entity_type, $odoo_id );
	}

	/**
	 * Save a mapping between a WordPress entity and an Odoo record.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @param int    $odoo_id     Odoo ID.
	 * @param string $sync_hash   SHA-256 hash of synced data.
	 * @return bool True on success.
	 */
	public function save_mapping( string $entity_type, int $wp_id, int $odoo_id, string $sync_hash = '' ): bool {
		$odoo_model = $this->get_odoo_model( $entity_type );
		return $this->entity_map->save( $this->id, $entity_type, $wp_id, $odoo_id, $odoo_model, $sync_hash );
	}

	/**
	 * Remove a mapping.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool True if a mapping was deleted.
	 */
	public function remove_mapping( string $entity_type, int $wp_id ): bool {
		return $this->entity_map->remove( $this->id, $entity_type, $wp_id );
	}

	// -------------------------------------------------------------------------
	// Data Transformation
	// -------------------------------------------------------------------------

	/**
	 * Transform WordPress data to Odoo field values.
	 *
	 * Applies field mappings. Subclasses should override for entity-specific logic.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data (post fields, meta, etc.).
	 * @return array Odoo-compatible field values.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		$mapping     = $this->get_field_mapping( $entity_type );
		$odoo_values = [];

		foreach ( $mapping as $wp_field => $odoo_field ) {
			if ( array_key_exists( $wp_field, $wp_data ) ) {
				$odoo_values[ $odoo_field ] = $wp_data[ $wp_field ];
			}
		}

		// Validate mapped Odoo fields exist in the model schema (warning only, non-blocking).
		if ( ! empty( $odoo_values ) && isset( $this->odoo_models[ $entity_type ] ) ) {
			$schema = Schema_Cache::get_fields( fn() => $this->client(), $this->odoo_models[ $entity_type ] );
			if ( ! empty( $schema ) ) {
				foreach ( $odoo_values as $field => $value ) {
					if ( ! isset( $schema[ $field ] ) ) {
						$this->logger->warning(
							"Mapped Odoo field '{$field}' not found in model schema.",
							[
								'entity_type' => $entity_type,
								'model'       => $this->odoo_models[ $entity_type ],
							]
						);
					}
				}
			}
		}

		/**
		 * Filter the mapped Odoo values before push.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $odoo_values Mapped values.
		 * @param array  $wp_data     Original WP data.
		 * @param string $entity_type Entity type.
		 */
		return apply_filters( "wp4odoo_map_to_odoo_{$this->id}_{$entity_type}", $odoo_values, $wp_data, $entity_type );
	}

	/**
	 * Transform Odoo data to WordPress-compatible format.
	 *
	 * Applies field mappings in reverse. Subclasses should override for entity-specific logic.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Odoo record data.
	 * @return array WordPress-compatible data.
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		$mapping = $this->get_field_mapping( $entity_type );
		$wp_data = [];

		foreach ( $mapping as $wp_field => $odoo_field ) {
			if ( array_key_exists( $odoo_field, $odoo_data ) ) {
				$wp_data[ $wp_field ] = $odoo_data[ $odoo_field ];
			}
		}

		return $wp_data;
	}

	/**
	 * Generate a SHA-256 hash of entity data for change detection.
	 *
	 * @param array $data The data to hash.
	 * @return string 64-character hex hash.
	 */
	public function generate_sync_hash( array $data ): string {
		ksort( $data );
		$json = wp_json_encode( $data );
		return hash( 'sha256', is_string( $json ) ? $json : serialize( $data ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fallback only when wp_json_encode fails; data is hashed, never unserialized.
	}

	// -------------------------------------------------------------------------
	// Protected hooks for subclasses
	// -------------------------------------------------------------------------

	/**
	 * Load WordPress data for an entity.
	 *
	 * Subclasses MUST override this to load actual data.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return [];
	}

	/**
	 * Get a deduplication domain for Odoo search-before-create.
	 *
	 * When push_to_odoo() is about to create a record, it first searches
	 * Odoo using this domain to detect orphaned records (created by a
	 * previous attempt whose save_mapping() failed). If a match is found,
	 * the create is converted to an update.
	 *
	 * Override in subclasses to provide module-specific dedup criteria.
	 * Return an empty array to skip dedup (default, fastest path).
	 *
	 * @param string               $entity_type Entity type.
	 * @param array<string, mixed> $odoo_values Mapped Odoo values about to be created.
	 * @return array<int, mixed> Odoo search domain (Polish notation), or empty to skip.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		return [];
	}

	/**
	 * Save data to WordPress.
	 *
	 * Subclasses MUST override this to create/update WP entities.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return 0;
	}

	/**
	 * Delete a WordPress entity.
	 *
	 * Subclasses should override this if they support delete syncs.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return false;
	}

	// -------------------------------------------------------------------------
	// Translation accumulator
	// -------------------------------------------------------------------------

	/**
	 * Get translatable fields for a given entity type.
	 *
	 * Override in subclasses to enable automatic pull translation support.
	 * Keys are Odoo field names, values are WordPress field names.
	 * Return empty array to skip translation (default).
	 *
	 * @param string $entity_type Entity type.
	 * @return array<string, string> Odoo field => WP field map.
	 */
	protected function get_translatable_fields( string $entity_type ): array {
		return [];
	}

	/**
	 * Accumulate a pulled record for batch translation at end of batch.
	 *
	 * @param string $odoo_model Odoo model name.
	 * @param int    $odoo_id    Odoo record ID.
	 * @param int    $wp_id      WordPress entity ID.
	 * @return void
	 */
	protected function accumulate_pull_translation( string $odoo_model, int $odoo_id, int $wp_id ): void {
		$this->translation_buffer[ $odoo_model ][ $odoo_id ] = $wp_id;
	}

	/**
	 * Flush accumulated pull translations.
	 *
	 * Called by Sync_Engine after each batch. For each Odoo model in the
	 * buffer, fetches translations for all accumulated records and applies
	 * them to the corresponding WordPress entities via Translation_Service.
	 *
	 * @return void
	 */
	public function flush_pull_translations(): void {
		if ( empty( $this->translation_buffer ) ) {
			return;
		}

		$ts = $this->translation_service();
		if ( ! $ts->is_available() ) {
			$this->translation_buffer = [];
			return;
		}

		// Resolve entity type from Odoo model (reverse lookup).
		$model_to_entity = array_flip( $this->odoo_models );

		foreach ( $this->translation_buffer as $odoo_model => $odoo_wp_map ) {
			$entity_type = $model_to_entity[ $odoo_model ] ?? '';
			if ( '' === $entity_type ) {
				continue;
			}

			$field_map = $this->get_translatable_fields( $entity_type );
			if ( empty( $field_map ) ) {
				continue;
			}

			$ts->pull_translations_batch(
				$odoo_model,
				$odoo_wp_map,
				array_keys( $field_map ),
				$field_map,
				$entity_type,
				fn( int $wp_id, array $data, string $lang ) => $this->apply_pull_translation( $wp_id, $data, $lang ),
				[]
			);

			$this->logger->info(
				'Flushed pull translations.',
				[
					'entity_type' => $entity_type,
					'count'       => count( $odoo_wp_map ),
				]
			);
		}

		$this->translation_buffer = [];
	}

	/**
	 * Apply a translated value to a WordPress entity.
	 *
	 * Default implementation updates post fields (post_title, post_content)
	 * via wp_update_post(). Override for non-post entities (taxonomy terms,
	 * custom tables, etc.).
	 *
	 * @param int                    $wp_id WordPress entity ID.
	 * @param array<string, string>  $data  WP field => translated value.
	 * @param string                 $lang  Language code.
	 * @return void
	 */
	protected function apply_pull_translation( int $wp_id, array $data, string $lang ): void {
		// Default: no-op. Modules override if they have a Translation_Adapter.
	}

	// -------------------------------------------------------------------------
	// Queue helpers
	// -------------------------------------------------------------------------

	/**
	 * Enqueue a push (create or update) for a WordPress entity.
	 *
	 * Shorthand for the 3-line pattern repeated in hooks traits:
	 *   $odoo_id = $this->get_mapping( $entity_type, $wp_id ) ?? 0;
	 *   $action  = $odoo_id ? 'update' : 'create';
	 *   Queue_Manager::push( $this->id, $entity_type, $action, $wp_id, $odoo_id );
	 *
	 * @param string $entity_type Entity type (e.g. 'product', 'order').
	 * @param int    $wp_id       WordPress entity ID.
	 * @return void
	 */
	protected function enqueue_push( string $entity_type, int $wp_id ): void {
		$odoo_id = $this->get_mapping( $entity_type, $wp_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';
		Queue_Manager::push( $this->id, $entity_type, $action, $wp_id, $odoo_id );
	}

	/**
	 * Handle a CPT save_post hook with standard guard clauses.
	 *
	 * Combines anti-loop, revision/autosave, post type, and settings
	 * checks before enqueuing a push. Replaces the 10-line boilerplate
	 * pattern repeated across hooks traits.
	 *
	 * @param int    $post_id     The saved post ID.
	 * @param string $post_type   Expected CPT slug.
	 * @param string $setting_key Settings array key to check.
	 * @param string $entity_type Entity type for the queue job.
	 * @return void
	 */
	protected function handle_cpt_save( int $post_id, string $post_type, string $setting_key, string $entity_type ): void {
		if ( ! $this->should_sync( $setting_key ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( get_post_type( $post_id ) !== $post_type ) {
			return;
		}

		$this->enqueue_push( $entity_type, $post_id );
	}

	/**
	 * Check if a sync operation should proceed.
	 *
	 * Combines the anti-loop guard with a settings check. Returns false
	 * if the module is importing or the given setting key is disabled.
	 *
	 * @param string $setting_key Settings array key to check (e.g. 'sync_products').
	 * @return bool True if the sync should proceed.
	 */
	protected function should_sync( string $setting_key ): bool {
		if ( $this->is_importing() ) {
			return false;
		}

		$settings = $this->get_settings();
		return ! empty( $settings[ $setting_key ] );
	}

	/**
	 * Detect changes in a set of entities via hash comparison and enqueue sync jobs.
	 *
	 * Compares current entity data against entity_map records using SHA-256
	 * hashes to detect creates, updates, and deletions. Used by WP-Cron
	 * polling modules (Bookly, Ecwid) that have no real-time hooks.
	 *
	 * @param string            $entity_type Entity type (e.g. 'service', 'product').
	 * @param array<int, array> $items       Current items from the data source.
	 * @param string            $id_field    Name of the ID field in each item.
	 * @return void
	 */
	protected function poll_entity_changes( string $entity_type, array $items, string $id_field = 'id' ): void {
		$existing = $this->entity_map()->get_module_entity_mappings( $this->id, $entity_type );
		$seen_ids = [];

		foreach ( $items as $item ) {
			$wp_id      = (int) ( $item[ $id_field ] ?? 0 );
			$seen_ids[] = $wp_id;

			$hash_data = $item;
			unset( $hash_data[ $id_field ] );
			$hash = $this->generate_sync_hash( $hash_data );

			if ( ! isset( $existing[ $wp_id ] ) ) {
				Queue_Manager::push( $this->id, $entity_type, 'create', $wp_id );
			} elseif ( $existing[ $wp_id ]['sync_hash'] !== $hash ) {
				Queue_Manager::push( $this->id, $entity_type, 'update', $wp_id, $existing[ $wp_id ]['odoo_id'] );
			}
		}

		$seen_lookup = array_flip( $seen_ids );
		foreach ( $existing as $wp_id => $map ) {
			if ( ! isset( $seen_lookup[ $wp_id ] ) ) {
				Queue_Manager::push( $this->id, $entity_type, 'delete', $wp_id, $map['odoo_id'] );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Graceful degradation
	// -------------------------------------------------------------------------

	/**
	 * Wrap a hook callback in a try/catch for graceful degradation.
	 *
	 * Returns a Closure that forwards all arguments to the original callable.
	 * If the callback throws any \Throwable (fatal error, TypeError, etc.),
	 * the exception is caught and logged instead of crashing the WordPress
	 * request. This protects against third-party plugin API changes.
	 *
	 * Usage: `add_action( 'third_party_hook', $this->safe_callback( [ $this, 'on_event' ] ) );`
	 *
	 * @param callable $callback The original callback to wrap.
	 * @return \Closure Wrapped callback that never throws.
	 */
	protected function safe_callback( callable $callback ): \Closure {
		return function () use ( $callback ): void {
			try {
				$callback( ...func_get_args() );
			} catch ( \Throwable $e ) {
				$this->logger->critical(
					'Hook callback crashed (graceful degradation).',
					[
						'module'    => $this->id,
						'exception' => get_class( $e ),
						'message'   => $e->getMessage(),
						'file'      => $e->getFile(),
						'line'      => $e->getLine(),
					]
				);
			}
		};
	}

	// -------------------------------------------------------------------------
	// Anti-loop
	// -------------------------------------------------------------------------

	/**
	 * Check if this module is currently importing from Odoo.
	 *
	 * Modules should call this at the top of every WP hook callback
	 * to prevent re-enqueuing during a pull operation (anti-loop).
	 *
	 * NOTE: This is process-local only. In multi-process environments
	 * (PHP-FPM), the queue dedup mechanism provides the definitive
	 * safety net. See $importing property docblock for details.
	 *
	 * @return bool True if this module has a pull/import in progress.
	 */
	protected function is_importing(): bool {
		return ! empty( self::$importing[ $this->id ] );
	}

	/**
	 * Set the anti-loop flag for this module to prevent hook re-entry during import.
	 *
	 * @return void
	 */
	protected function mark_importing(): void {
		self::$importing[ $this->id ] = true;
	}

	/**
	 * Clear the anti-loop flag for this module after import completes.
	 *
	 * @return void
	 */
	protected function clear_importing(): void {
		unset( self::$importing[ $this->id ] );
	}

	// -------------------------------------------------------------------------
	// Infrastructure accessors
	// -------------------------------------------------------------------------

	/**
	 * Get the Odoo client instance (lazy, via injected closure).
	 *
	 * @return Odoo_Client
	 */
	protected function client(): Odoo_Client {
		return ( $this->client_provider )();
	}

	/**
	 * Get the entity map repository.
	 *
	 * Subclasses use this to inject the repository into helper classes
	 * (Partner_Service, Variant_Handler, etc.).
	 *
	 * @return Entity_Map_Repository
	 */
	protected function entity_map(): Entity_Map_Repository {
		return $this->entity_map;
	}

	/**
	 * Get the Odoo model name for an entity type.
	 *
	 * @param string $entity_type Entity type.
	 * @return string Odoo model name.
	 * @throws \InvalidArgumentException If entity type is not registered.
	 */
	protected function get_odoo_model( string $entity_type ): string {
		if ( ! isset( $this->odoo_models[ $entity_type ] ) ) {

			throw new \InvalidArgumentException(
				sprintf(
					/* translators: 1: entity type, 2: module identifier */
					__( 'Entity type "%1$s" is not registered in module "%2$s".', 'wp4odoo' ),
					$entity_type,
					$this->id
				)
			);
		}

		return $this->odoo_models[ $entity_type ];
	}

	/**
	 * Get the active field mapping for an entity type.
	 *
	 * Returns custom mapping from wp_options if set, otherwise default_mappings.
	 *
	 * @param string $entity_type Entity type.
	 * @return array<string, string> wp_field => odoo_field.
	 */
	protected function get_field_mapping( string $entity_type ): array {
		if ( isset( $this->mapping_cache[ $entity_type ] ) ) {
			return $this->mapping_cache[ $entity_type ];
		}

		$custom = $this->settings_repo->get_module_mappings( $this->id );

		if ( ! empty( $custom[ $entity_type ] ) && is_array( $custom[ $entity_type ] ) ) {
			$this->mapping_cache[ $entity_type ] = $custom[ $entity_type ];
			return $this->mapping_cache[ $entity_type ];
		}

		$this->mapping_cache[ $entity_type ] = $this->default_mappings[ $entity_type ] ?? [];
		return $this->mapping_cache[ $entity_type ];
	}

	/**
	 * Flush the in-memory field mapping cache.
	 *
	 * Useful after bulk operations or when mappings are changed at runtime.
	 *
	 * @return void
	 */
	public function flush_mapping_cache(): void {
		$this->mapping_cache = [];
	}

	/**
	 * Get module settings from wp_options.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return array_merge(
			$this->get_default_settings(),
			$this->settings_repo->get_module_settings( $this->id )
		);
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * Subclasses should override this to expose configurable settings.
	 * Each field is an array with keys: label, type, description, options (for select).
	 *
	 * @return array<string, array> Field key => definition.
	 */
	public function get_settings_fields(): array {
		return [];
	}

	/**
	 * Get the dependency status for this module.
	 *
	 * Returns whether the module's external dependencies are met and
	 * any notices to display in the admin UI. Subclasses should override
	 * this to declare their plugin dependencies.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return [
			'available' => true,
			'notices'   => [],
		];
	}

	/**
	 * Get the detected version of the third-party plugin.
	 *
	 * Subclasses override to return the plugin's version constant/property.
	 * Returns empty string when unavailable or not applicable.
	 *
	 * @return string
	 */
	protected function get_plugin_version(): string {
		return '';
	}

	/**
	 * Get the sync direction supported by this module.
	 *
	 * Returns one of: 'bidirectional', 'wp_to_odoo', 'odoo_to_wp'.
	 * Subclasses should override this to declare their actual sync capability.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Get the module ID.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get the module name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get the exclusive group name.
	 *
	 * @return string Group name, or empty string if no exclusivity.
	 */
	public function get_exclusive_group(): string {
		return $this->exclusive_group;
	}

	/**
	 * Get the priority within the exclusive group.
	 *
	 * @return int Priority (higher = takes precedence).
	 */
	public function get_exclusive_priority(): int {
		return $this->exclusive_priority;
	}

	/**
	 * Get the registered Odoo models.
	 *
	 * @return array<string, string>
	 */
	public function get_odoo_models(): array {
		return $this->odoo_models;
	}

	/**
	 * Get the Odoo client instance (public accessor for CLI/tools).
	 *
	 * @return API\Odoo_Client
	 */
	public function get_client(): API\Odoo_Client {
		return $this->client();
	}
}
