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
	use Error_Classification;
	use Push_Lock;
	use Poll_Support;
	use Sync_Orchestrator;
	use Hook_Lifecycle;
	use Translation_Accumulator;

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
	private static array $importing_request_local = [];

	/**
	 * Injectable Queue_Manager instance.
	 *
	 * When null, queue() lazily creates a default instance backed by
	 * the static Queue_Manager singleton. Can be replaced via
	 * set_queue_manager() for testing.
	 *
	 * @var Queue_Manager|null
	 */
	protected ?Queue_Manager $queue_manager = null;

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
		$this->logger          = Logger::for_channel( $this->id, $settings );
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
	// Queue helpers
	// -------------------------------------------------------------------------

	/**
	 * Enqueue a push (create or update) for a WordPress entity.
	 *
	 * Shorthand for the 3-line pattern repeated in hooks traits:
	 *   $odoo_id = $this->get_mapping( $entity_type, $wp_id ) ?? 0;
	 *   $action  = $odoo_id ? 'update' : 'create';
	 *   $this->queue()->enqueue_push( $this->id, $entity_type, $action, $wp_id, $odoo_id );
	 *
	 * @param string $entity_type Entity type (e.g. 'product', 'order').
	 * @param int    $wp_id       WordPress entity ID.
	 * @return void
	 */
	protected function enqueue_push( string $entity_type, int $wp_id ): void {
		$odoo_id = $this->get_mapping( $entity_type, $wp_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';
		$this->queue()->enqueue_push( $this->id, $entity_type, $action, $wp_id, $odoo_id );
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
		return ! empty( self::$importing_request_local[ $this->id ] );
	}

	/**
	 * Set the anti-loop flag for this module to prevent hook re-entry during import.
	 *
	 * @return void
	 */
	protected function mark_importing(): void {
		self::$importing_request_local[ $this->id ] = true;
	}

	/**
	 * Clear the anti-loop flag for this module after import completes.
	 *
	 * @return void
	 */
	protected function clear_importing(): void {
		unset( self::$importing_request_local[ $this->id ] );
	}

	// -------------------------------------------------------------------------
	// Infrastructure accessors
	// -------------------------------------------------------------------------

	/**
	 * Get the Queue_Manager instance.
	 *
	 * Returns the injected instance if set via set_queue_manager(),
	 * otherwise lazily creates a default instance backed by the
	 * static Queue_Manager singleton repository.
	 *
	 * @return Queue_Manager
	 */
	protected function queue(): Queue_Manager {
		if ( null === $this->queue_manager ) {
			$this->queue_manager = new Queue_Manager();
		}
		return $this->queue_manager;
	}

	/**
	 * Inject a Queue_Manager instance (for testing or custom wiring).
	 *
	 * @param Queue_Manager $queue_manager Queue_Manager instance to use.
	 * @return void
	 */
	public function set_queue_manager( Queue_Manager $queue_manager ): void {
		$this->queue_manager = $queue_manager;
	}

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
	 * Get modules that must be booted before this one can boot.
	 *
	 * Override in subclasses to declare inter-module dependencies.
	 * For example, WC Subscriptions requires the WooCommerce module.
	 *
	 * @since 3.3.0
	 *
	 * @return string[] Array of module IDs (e.g. ['woocommerce']).
	 */
	public function get_required_modules(): array {
		return [];
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
