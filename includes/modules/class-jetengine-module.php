<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetEngine Module — generic CPT → Odoo push mapping.
 *
 * Maps any WordPress Custom Post Type to any Odoo model via configurable
 * settings. Each mapping defines a CPT slug, an Odoo model name, and a
 * list of field pairs (WP field source → Odoo field name + type).
 *
 * Unlike other modules, entity types and Odoo models are defined
 * dynamically from settings — not hardcoded.
 *
 * Push-only (WP → Odoo). No mutual exclusivity.
 *
 * Requires JetEngine by Crocoblock to be active.
 *
 * WP field sources:
 * - Standard post fields: post_title, post_content, post_excerpt, etc.
 * - Post meta: meta:my_meta_key
 * - JetEngine meta: jet:my_jet_field (stored as post_meta)
 * - Taxonomy terms: tax:my_taxonomy (concatenated names)
 *
 * PHP filter hooks for advanced customization:
 * - wp4odoo_jetengine_map_to_odoo_{cpt_slug}: transform Odoo values before push
 * - wp4odoo_jetengine_field_value: transform individual field values
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class JetEngine_Module extends Module_Base {

	use JetEngine_Hooks;

	protected const PLUGIN_MIN_VERSION  = '3.0';
	protected const PLUGIN_TESTED_UP_TO = '3.6';

	/**
	 * Odoo models by entity type — populated dynamically from settings.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [];

	/**
	 * Default field mappings — empty (defined in settings).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [];

	/**
	 * JetEngine data handler.
	 *
	 * @var JetEngine_Handler
	 */
	private JetEngine_Handler $handler;

	/**
	 * Cached validated CPT mappings.
	 *
	 * @var array<int, array{cpt_slug: string, entity_type: string, odoo_model: string, dedup_field: string, fields: array}>|null
	 */
	private ?array $cached_mappings = null;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'jetengine', 'JetEngine', $client_provider, $entity_map, $settings );
		$this->handler = new JetEngine_Handler();
		$this->build_dynamic_models();
	}

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	/**
	 * Boot the module: register dynamic hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( empty( $this->odoo_models ) ) {
			$this->logger->info( 'JetEngine module enabled but no CPT mappings configured.' );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'cpt_mappings' => [],
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'cpt_mappings' => [
				'label'       => __( 'CPT Mappings', 'wp4odoo' ),
				'type'        => 'cpt_mappings',
				'description' => __( 'Map JetEngine CPTs to Odoo models. Each mapping defines a CPT, target Odoo model, and field pairs.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return defined( 'JET_ENGINE_VERSION' ) || class_exists( 'Jet_Engine' );
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency(
			static::detect(),
			'JetEngine'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'JET_ENGINE_VERSION' ) ? (string) JET_ENGINE_VERSION : '';
	}

	// ─── Data access ──────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type (from mapping).
	 * @param int    $wp_id       WordPress post ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		$mapping = $this->find_mapping_for_entity( $entity_type );
		if ( ! $mapping ) {
			return [];
		}

		return $this->handler->load_cpt_data( $wp_id, $mapping['fields'] );
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Data is pre-formatted by handler — applies filter hooks for customization.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array<string, mixed>
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		$mapping = $this->find_mapping_for_entity( $entity_type );
		if ( ! $mapping ) {
			return $wp_data;
		}

		$post_id = $wp_data['_wp_entity_id'] ?? 0;
		unset( $wp_data['_wp_entity_id'] );

		// Apply per-field filter.
		foreach ( $wp_data as $odoo_field => $value ) {
			/**
			 * Filter an individual JetEngine field value before push.
			 *
			 * @param mixed  $value      The converted field value.
			 * @param string $wp_field   WP field source (not available here; use odoo_field).
			 * @param string $odoo_field Odoo field name.
			 * @param int    $post_id    WordPress post ID.
			 */
			$wp_data[ $odoo_field ] = apply_filters( 'wp4odoo_jetengine_field_value', $value, '', $odoo_field, $post_id );
		}

		/**
		 * Filter the complete Odoo values for a JetEngine CPT push.
		 *
		 * @param array<string, mixed> $odoo_values Odoo-ready values.
		 * @param array<string, mixed> $wp_data     Original WP data.
		 * @param int                  $post_id     WordPress post ID.
		 */
		return apply_filters( "wp4odoo_jetengine_map_to_odoo_{$mapping['cpt_slug']}", $wp_data, $wp_data, $post_id );
	}

	// ─── Deduplication ────────────────────────────────────

	/**
	 * Deduplication domain — uses the configured dedup_field.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		$mapping = $this->find_mapping_for_entity( $entity_type );

		if ( $mapping && ! empty( $mapping['dedup_field'] ) ) {
			$field = $mapping['dedup_field'];
			if ( ! empty( $odoo_values[ $field ] ) ) {
				return [ [ $field, '=', $odoo_values[ $field ] ] ];
			}
		}

		return [];
	}

	/**
	 * Override should_sync for dynamic entity types.
	 *
	 * JetEngine entity types don't have fixed setting keys. All configured
	 * mappings are implicitly enabled. The standard should_sync() checks
	 * for 'sync_{entity_type}' which won't exist in settings, so we
	 * override to just check importing status.
	 *
	 * @param string $setting_key The setting key.
	 * @return bool
	 */
	protected function should_sync( string $setting_key ): bool {
		// Dynamic entity types are always enabled if the mapping exists.
		// Still check anti-loop guard.
		return ! $this->is_importing();
	}

	// ─── Internal helpers ─────────────────────────────────

	/**
	 * Populate odoo_models from settings.
	 *
	 * @return void
	 */
	private function build_dynamic_models(): void {
		$mappings = $this->get_cpt_mappings();

		foreach ( $mappings as $mapping ) {
			$this->odoo_models[ $mapping['entity_type'] ] = $mapping['odoo_model'];
		}
	}

	/**
	 * Get validated CPT mappings from settings.
	 *
	 * @return array<int, array{cpt_slug: string, entity_type: string, odoo_model: string, dedup_field: string, fields: array}>
	 */
	public function get_cpt_mappings(): array {
		if ( null !== $this->cached_mappings ) {
			return $this->cached_mappings;
		}

		$settings = $this->get_settings();
		$raw      = $settings['cpt_mappings'] ?? [];

		if ( ! is_array( $raw ) ) {
			$this->cached_mappings = [];
			return [];
		}

		$validated = [];

		foreach ( $raw as $mapping ) {
			if ( ! is_array( $mapping ) ) {
				continue;
			}

			$clean = JetEngine_Handler::validate_mapping( $mapping );
			if ( null !== $clean ) {
				$validated[] = $clean;
			}
		}

		$this->cached_mappings = $validated;
		return $validated;
	}

	/**
	 * Find a CPT mapping by entity type.
	 *
	 * @param string $entity_type Entity type to find.
	 * @return array{cpt_slug: string, entity_type: string, odoo_model: string, dedup_field: string, fields: array}|null
	 */
	private function find_mapping_for_entity( string $entity_type ): ?array {
		foreach ( $this->get_cpt_mappings() as $mapping ) {
			if ( $mapping['entity_type'] === $entity_type ) {
				return $mapping;
			}
		}

		return null;
	}
}
