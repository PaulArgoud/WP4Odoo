<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Error_Type;
use WP4Odoo\Module_Base;
use WP4Odoo\Odoo_Model;
use WP4Odoo\Queue_Manager;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Bundle BOM Module — push WC bundles/composites as Odoo Manufacturing BOMs.
 *
 * Syncs WooCommerce Product Bundles and WooCommerce Composite Products
 * as Odoo Manufacturing BOMs (mrp.bom). Push-only (WC → Odoo).
 *
 * Component products must be synced by the WooCommerce module first.
 * Cross-module lookup: uses Entity_Map_Repository::get_odoo_id('woocommerce', 'product', $wp_id)
 * to resolve component Odoo IDs. If products aren't mapped yet, enqueues
 * them and returns a Transient failure for retry.
 *
 * Coexists with the WooCommerce module (no mutual exclusivity).
 *
 * Requires WC Product Bundles and/or WC Composite Products to be active.
 *
 * @package WP4Odoo
 * @since   3.0.5
 */
class WC_Bundle_BOM_Module extends Module_Base {

	use WC_Bundle_BOM_Hooks;

	protected const PLUGIN_MIN_VERSION  = '7.0';
	protected const PLUGIN_TESTED_UP_TO = '8.0';

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'bom' => 'mrp.bom',
	];

	/**
	 * Default field mappings.
	 *
	 * BOM data is pre-formatted by the handler (One2many tuples), so
	 * the mapping is identity (each key maps to the same Odoo field name).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'bom' => [
			'product_tmpl_id' => 'product_tmpl_id',
			'type'            => 'type',
			'product_qty'     => 'product_qty',
			'bom_line_ids'    => 'bom_line_ids',
			'code'            => 'code',
		],
	];

	/**
	 * BOM data handler.
	 *
	 * @var WC_Bundle_BOM_Handler
	 */
	private WC_Bundle_BOM_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_bundle_bom', 'WC Product Bundles BOM', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Bundle_BOM_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WC Bundles/Composite hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WC_Bundles' ) && ! class_exists( 'WC_Composite_Products' ) ) {
			$this->logger->warning( __( 'WC Bundle BOM module enabled but neither WC Product Bundles nor WC Composite Products is active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_bundles'] ) ) {
			add_action( 'save_post_product', $this->safe_callback( [ $this, 'on_bundle_save' ] ), 20, 1 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_settings(): array {
		return [
			'sync_bundles' => true,
			'bom_type'     => 'phantom',
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_bundles' => [
				'label'       => __( 'Sync bundles as BOMs', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WC bundle/composite products to Odoo as Manufacturing BOMs (mrp.bom).', 'wp4odoo' ),
			],
			'bom_type'     => [
				'label'       => __( 'BOM type', 'wp4odoo' ),
				'type'        => 'select',
				'description' => __( 'Kit (phantom) auto-explodes at delivery. Manufacture (normal) creates production orders.', 'wp4odoo' ),
				'options'     => [
					'phantom' => __( 'Kit (phantom)', 'wp4odoo' ),
					'normal'  => __( 'Manufacture (normal)', 'wp4odoo' ),
				],
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
			class_exists( 'WC_Bundles' ) || class_exists( 'WC_Composite_Products' ),
			'WC Product Bundles / WC Composite Products'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WC_PB_VERSION' ) ? WC_PB_VERSION : '';
	}

	/**
	 * Get the handler instance (used by hooks trait).
	 *
	 * @return WC_Bundle_BOM_Handler
	 */
	public function get_handler(): WC_Bundle_BOM_Handler {
		return $this->handler;
	}

	// ─── MRP model detection ──────────────────────────────

	/**
	 * Check whether Odoo has the mrp.bom model (Manufacturing module).
	 *
	 * @return bool
	 */
	private function has_mrp_bom_model(): bool {
		return $this->has_odoo_model( Odoo_Model::MrpBom, 'wp4odoo_has_mrp_bom' );
	}

	// ─── Push override ────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Validates that the Manufacturing module is installed in Odoo and
	 * that all component products are synced before pushing.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		if ( 'bom' === $entity_type && 'delete' !== $action ) {
			if ( ! $this->has_mrp_bom_model() ) {
				$this->logger->info( 'mrp.bom not available — Manufacturing module not installed in Odoo.', [ 'product_id' => $wp_id ] );
				return Sync_Result::success();
			}

			$missing = $this->ensure_components_synced( $wp_id );
			if ( ! empty( $missing ) ) {
				$this->logger->info(
					'Component products not yet synced — retrying later.',
					[
						'product_id'    => $wp_id,
						'missing_count' => count( $missing ),
					]
				);
				return Sync_Result::failure( 'Waiting for component products to sync.', Error_Type::Transient );
			}
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	// ─── Data access ──────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'bom'   => $this->load_bom_data( $wp_id ),
			default => [],
		};
	}

	/**
	 * Load and resolve BOM data for a bundle/composite product.
	 *
	 * Resolves the parent product's product_tmpl_id from Odoo and each
	 * component's Odoo product.product ID via cross-module entity_map lookup.
	 *
	 * @param int $product_id WC product ID.
	 * @return array<string, mixed> Formatted BOM data, or empty on failure.
	 */
	private function load_bom_data( int $product_id ): array {
		$components = $this->handler->load_bundle_or_composite( $product_id );
		if ( empty( $components ) ) {
			$this->logger->warning( 'No components found for bundle/composite.', [ 'product_id' => $product_id ] );
			return [];
		}

		// Resolve parent product → Odoo product.product ID → product.template ID.
		$parent_odoo_id = $this->resolve_product_odoo_id( $product_id );
		if ( ! $parent_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for parent bundle.', [ 'product_id' => $product_id ] );
			return [];
		}

		$tmpl_id = $this->resolve_product_template_id( $parent_odoo_id );
		if ( ! $tmpl_id ) {
			$this->logger->warning( 'Cannot resolve product_tmpl_id from Odoo.', [ 'odoo_product_id' => $parent_odoo_id ] );
			return [];
		}

		// Resolve each component → Odoo product.product ID.
		$component_lines = [];
		foreach ( $components as $comp ) {
			$comp_odoo_id = $this->resolve_product_odoo_id( $comp['wp_product_id'] );
			if ( ! $comp_odoo_id ) {
				$this->logger->warning( 'Cannot resolve Odoo product for component.', [ 'wp_product_id' => $comp['wp_product_id'] ] );
				return [];
			}
			$component_lines[] = [
				'odoo_id'  => $comp_odoo_id,
				'quantity' => $comp['quantity'],
			];
		}

		$settings = $this->get_settings();
		$bom_type = $settings['bom_type'] ?? 'phantom';

		return $this->handler->format_bom( $tmpl_id, $component_lines, $bom_type, $product_id );
	}

	// ─── Cross-module lookups ─────────────────────────────

	/**
	 * Resolve a WC product ID to its Odoo product.product ID.
	 *
	 * Uses cross-module entity_map lookup on the WooCommerce module.
	 *
	 * @param int $wp_id WC product ID.
	 * @return int Odoo product.product ID, or 0 if not mapped.
	 */
	private function resolve_product_odoo_id( int $wp_id ): int {
		return $this->entity_map()->get_odoo_id( 'woocommerce', 'product', $wp_id ) ?? 0;
	}

	/**
	 * Resolve an Odoo product.product ID to its product.template ID.
	 *
	 * Reads the product_tmpl_id Many2one field from Odoo.
	 *
	 * @param int $odoo_product_id Odoo product.product ID.
	 * @return int Odoo product.template ID, or 0 on failure.
	 */
	private function resolve_product_template_id( int $odoo_product_id ): int {
		try {
			$client = $this->client();
			$result = $client->read( 'product.product', [ $odoo_product_id ], [ 'product_tmpl_id' ] );

			if ( ! empty( $result[0]['product_tmpl_id'] ) && is_array( $result[0]['product_tmpl_id'] ) ) {
				return (int) $result[0]['product_tmpl_id'][0];
			}
		} catch ( \Exception $e ) {
			$this->logger->warning(
				'Failed to read product_tmpl_id from Odoo.',
				[
					'odoo_product_id' => $odoo_product_id,
					'error'           => $e->getMessage(),
				]
			);
		}

		return 0;
	}

	/**
	 * Ensure all component products are synced in the WooCommerce module.
	 *
	 * Returns the list of WP product IDs that are not yet mapped.
	 * For each missing one, enqueues a sync job in the WooCommerce module queue.
	 *
	 * @param int $product_id Parent bundle/composite WC product ID.
	 * @return array<int> WP product IDs that are not yet synced.
	 */
	private function ensure_components_synced( int $product_id ): array {
		$components = $this->handler->load_bundle_or_composite( $product_id );
		$missing    = [];

		// Check parent product.
		$parent_odoo_id = $this->resolve_product_odoo_id( $product_id );
		if ( ! $parent_odoo_id ) {
			$missing[] = $product_id;
			Queue_Manager::push( 'woocommerce', 'product', 'create', $product_id );
		}

		// Check component products.
		foreach ( $components as $comp ) {
			$comp_odoo_id = $this->resolve_product_odoo_id( $comp['wp_product_id'] );
			if ( ! $comp_odoo_id ) {
				$missing[] = $comp['wp_product_id'];
				Queue_Manager::push( 'woocommerce', 'product', 'create', $comp['wp_product_id'] );
			}
		}

		return $missing;
	}
}
