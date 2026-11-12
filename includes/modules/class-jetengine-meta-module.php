<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetEngine Meta-Module — maps JetEngine custom meta fields to Odoo x_ fields.
 *
 * This is not a classic sync module — it never pushes or pulls on its own.
 * Instead, it hooks into other modules' filter/action pipelines to enrich
 * their data with JetEngine meta field values (push) and write Odoo values
 * back to post meta (pull).
 *
 * Follows the same pattern as ACF_Module. Both meta-modules can be active
 * simultaneously (they use different settings keys and _acf_ / _jet_ prefixes).
 *
 * Coexists with the existing `jetengine` module (which synchronizes JetEngine
 * CPTs → Odoo). This module enriches other modules (e.g., WooCommerce) with
 * JetEngine meta fields.
 *
 * No mutual exclusivity with other modules.
 *
 * Requires JetEngine by Crocoblock to be active.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class JetEngine_Meta_Module extends Module_Base {

	protected const PLUGIN_MIN_VERSION  = '3.0';
	protected const PLUGIN_TESTED_UP_TO = '3.5';

	/**
	 * Sync direction: bidirectional (enriches both push and pull).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models: empty — meta-module does not own any entity types.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [];

	/**
	 * Default field mappings: empty — uses its own mapping configuration.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [];

	/**
	 * JetEngine meta data handler.
	 *
	 * @var JetEngine_Meta_Handler
	 */
	private JetEngine_Meta_Handler $handler;

	/**
	 * Mapping rules grouped by module/entity: ["{module}_{entity}" => [rules...]].
	 *
	 * @var array<string, array<int, array{jet_field: string, odoo_field: string, type: string}>>
	 */
	private array $grouped_rules = [];

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'jetengine_meta', 'JetEngine Meta', $client_provider, $entity_map, $settings );
		$this->handler = new JetEngine_Meta_Handler( $this->logger );
	}

	/**
	 * Boot the module: register enrichment filters/actions on target modules.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'JET_ENGINE_VERSION' ) && ! class_exists( 'Jet_Engine' ) ) {
			$this->logger->warning( __( 'JetEngine Meta module enabled but JetEngine is not active.', 'wp4odoo' ) );
			return;
		}

		$mappings = $this->get_jetengine_mappings();

		if ( empty( $mappings ) ) {
			return;
		}

		// Group rules by target_module + entity_type.
		$this->grouped_rules = [];

		foreach ( $mappings as $rule ) {
			$key = $rule['target_module'] . '_' . $rule['entity_type'];

			$this->grouped_rules[ $key ][] = $rule;
		}

		// Register filters/actions for each unique module/entity pair.
		foreach ( array_keys( $this->grouped_rules ) as $key ) {
			$parts       = explode( '_', $key, 2 );
			$module_id   = $parts[0];
			$entity_type = $parts[1] ?? '';

			if ( '' === $entity_type ) {
				continue;
			}

			// Push enrichment: inject JetEngine meta values into Odoo data.
			add_filter(
				"wp4odoo_map_to_odoo_{$module_id}_{$entity_type}",
				function ( array $odoo_values, array $wp_data ) use ( $key ): array {
					return $this->handler->enrich_push( $odoo_values, $wp_data, $this->grouped_rules[ $key ] );
				},
				20,
				2
			);

			// Pull enrichment: extract Odoo x_ values into _jet_ keys.
			add_filter(
				"wp4odoo_map_from_odoo_{$module_id}_{$entity_type}",
				function ( array $wp_data, array $odoo_data ) use ( $key ): array {
					return $this->handler->enrich_pull( $wp_data, $odoo_data, $this->grouped_rules[ $key ] );
				},
				20,
				2
			);

			// Post-save: write JetEngine meta fields with the known WP ID.
			add_action(
				"wp4odoo_after_save_{$module_id}_{$entity_type}",
				function ( int $wp_id, array $wp_data ) use ( $key ): void {
					$this->handler->write_jet_fields( $wp_id, $wp_data, $this->grouped_rules[ $key ] );
				},
				10,
				2
			);
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'jetengine_mappings' => [],
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'jetengine_mappings' => [
				'label'       => __( 'Field mappings', 'wp4odoo' ),
				'type'        => 'mappings',
				'description' => __( 'Map JetEngine meta fields to Odoo custom fields (x_*). Each rule enriches a specific module and entity type.', 'wp4odoo' ),
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
	 * Get external dependency status for JetEngine.
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

	/**
	 * Get validated JetEngine mapping rules from settings.
	 *
	 * @return array<int, array{target_module: string, entity_type: string, jet_field: string, odoo_field: string, type: string}>
	 */
	public function get_jetengine_mappings(): array {
		$settings = $this->get_settings();
		$raw      = $settings['jetengine_mappings'] ?? [];

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$validated = [];

		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$clean = JetEngine_Meta_Handler::validate_rule( $rule );

			if ( null !== $clean ) {
				$validated[] = $clean;
			}
		}

		return $validated;
	}

	// ─── Data access (unused — meta-module has no entity types) ──

	/**
	 * Load WordPress data for an entity.
	 *
	 * Meta-module does not own entity types — this is never called.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return [];
	}
}
