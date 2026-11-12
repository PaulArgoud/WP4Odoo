<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ACF Meta-Module — maps ACF custom fields to Odoo x_ fields.
 *
 * This is not a classic sync module — it never pushes or pulls on its own.
 * Instead, it hooks into other modules' filter/action pipelines to enrich
 * their data with ACF field values (push) and write Odoo values back to
 * ACF fields (pull).
 *
 * No mutual exclusivity with other modules.
 *
 * Requires the Advanced Custom Fields plugin (free or Pro) to be active.
 *
 * @package WP4Odoo
 * @since   3.1.0
 */
class ACF_Module extends Module_Base {

	protected const PLUGIN_MIN_VERSION  = '6';
	protected const PLUGIN_TESTED_UP_TO = '6';

	/**
	 * Sync direction: bidirectional (enriches both push and pull).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models: empty — ACF module does not own any entity types.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [];

	/**
	 * Default field mappings: empty — ACF uses its own mapping configuration.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [];

	/**
	 * ACF data handler.
	 *
	 * @var ACF_Handler
	 */
	private ACF_Handler $handler;

	/**
	 * Mapping rules grouped by module/entity: ["{module}_{entity}" => [rules...]].
	 *
	 * @var array<string, array<int, array{acf_field: string, odoo_field: string, type: string, context: string}>>
	 */
	private array $grouped_rules = [];

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'acf', 'Advanced Custom Fields', $client_provider, $entity_map, $settings );
		$this->handler = new ACF_Handler( $this->logger );
	}

	/**
	 * Boot the module: register enrichment filters/actions on target modules.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'ACF' ) && ! defined( 'ACF_MAJOR_VERSION' ) ) {
			$this->logger->warning( __( 'ACF module enabled but Advanced Custom Fields is not active.', 'wp4odoo' ) );
			return;
		}

		$mappings = $this->get_acf_mappings();

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

			// Push enrichment: inject ACF values into Odoo data.
			add_filter(
				"wp4odoo_map_to_odoo_{$module_id}_{$entity_type}",
				function ( array $odoo_values, array $wp_data ) use ( $key ): array {
					return $this->handler->enrich_push( $odoo_values, $wp_data, $this->grouped_rules[ $key ] );
				},
				20,
				2
			);

			// Pull enrichment: extract Odoo x_ values into _acf_ keys.
			add_filter(
				"wp4odoo_map_from_odoo_{$module_id}_{$entity_type}",
				function ( array $wp_data, array $odoo_data ) use ( $key ): array {
					return $this->handler->enrich_pull( $wp_data, $odoo_data, $this->grouped_rules[ $key ] );
				},
				20,
				2
			);

			// Post-save: write ACF fields with the known WP ID.
			add_action(
				"wp4odoo_after_save_{$module_id}_{$entity_type}",
				function ( int $wp_id, array $wp_data ) use ( $key ): void {
					$this->handler->write_acf_fields( $wp_id, $wp_data, $this->grouped_rules[ $key ] );
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
			'acf_mappings' => [],
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'acf_mappings' => [
				'label'       => __( 'Field mappings', 'wp4odoo' ),
				'type'        => 'mappings',
				'description' => __( 'Map ACF fields to Odoo custom fields (x_*). Each rule enriches a specific module and entity type.', 'wp4odoo' ),
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
		return class_exists( 'ACF' ) || defined( 'ACF_MAJOR_VERSION' );
	}

	/**
	 * Get external dependency status for ACF.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency(
			static::detect(),
			'Advanced Custom Fields'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'ACF_MAJOR_VERSION' ) ? (string) ACF_MAJOR_VERSION : '';
	}

	/**
	 * Get validated ACF mapping rules from settings.
	 *
	 * @return array<int, array{target_module: string, entity_type: string, acf_field: string, odoo_field: string, type: string, context: string}>
	 */
	public function get_acf_mappings(): array {
		$settings = $this->get_settings();
		$raw      = $settings['acf_mappings'] ?? [];

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$validated = [];

		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$clean = ACF_Handler::validate_rule( $rule );

			if ( null !== $clean ) {
				$validated[] = $clean;
			}
		}

		return $validated;
	}

	// ─── Data access (unused — ACF has no entity types) ──

	/**
	 * Load WordPress data for an entity.
	 *
	 * ACF module does not own entity types — this is never called.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return [];
	}
}
