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
 * WC Product Add-Ons Module — push product options to Odoo.
 *
 * Syncs WooCommerce product add-ons/options to Odoo as either product
 * attributes (product.template.attribute.line) or BOM lines (mrp.bom.line),
 * depending on configuration.
 *
 * Compatible with:
 * - WooCommerce Product Add-Ons (official, WooCommerce.com)
 * - ThemeHigh Product Add-Ons (THWEPO)
 * - PPOM for WooCommerce
 *
 * Push-only (WP → Odoo). Requires the WooCommerce module to be active.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
class WC_Addons_Module extends Module_Base {

	use WC_Addons_Hooks;

	protected const PLUGIN_MIN_VERSION  = '6.0';
	protected const PLUGIN_TESTED_UP_TO = '7.0';

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
	 * The actual model depends on the configured mode (attributes vs BOM).
	 * Default to the attribute line model.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'addon' => 'product.template',
	];

	/**
	 * Default field mappings.
	 *
	 * Add-on data is pre-formatted by the handler — identity pass-through.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'addon' => [
			'attribute_line_ids' => 'attribute_line_ids',
		],
	];

	/**
	 * Add-ons data handler.
	 *
	 * @var WC_Addons_Handler
	 */
	private WC_Addons_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wc_addons', 'WC Product Add-Ons', $client_provider, $entity_map, $settings );
		$this->handler = new WC_Addons_Handler();
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_required_modules(): array {
		return [ 'woocommerce' ];
	}

	/**
	 * Boot the module: register hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'WC_Product_Addons' ) && ! defined( 'THWEPO_VERSION' ) && ! defined( 'PPOM_VERSION' ) ) {
			$this->logger->warning( __( 'WC Add-Ons module enabled but no compatible add-on plugin is active.', 'wp4odoo' ) );
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
			'sync_addons' => true,
			'addon_mode'  => 'product_attributes',
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_addons' => [
				'label'       => __( 'Sync product add-ons', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WooCommerce product add-ons/options to Odoo.', 'wp4odoo' ),
			],
			'addon_mode'  => [
				'label'       => __( 'Sync mode', 'wp4odoo' ),
				'type'        => 'select',
				'description' => __( 'How to represent add-ons in Odoo: as product attributes or as BOM lines.', 'wp4odoo' ),
				'options'     => [
					'product_attributes' => __( 'Product attributes', 'wp4odoo' ),
					'bom_components'     => __( 'BOM components (requires Manufacturing)', 'wp4odoo' ),
				],
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
		return class_exists( 'WC_Product_Addons' ) || defined( 'THWEPO_VERSION' ) || defined( 'PPOM_VERSION' );
	}

	/**
	 * Get external dependency status.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency(
			static::detect(),
			'WC Product Add-Ons / ThemeHigh / PPOM'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		if ( class_exists( 'WC_Product_Addons' ) && defined( 'WC_PRODUCT_ADDONS_VERSION' ) ) {
			return (string) WC_PRODUCT_ADDONS_VERSION;
		}
		if ( defined( 'THWEPO_VERSION' ) ) {
			return (string) THWEPO_VERSION;
		}
		if ( defined( 'PPOM_VERSION' ) ) {
			return (string) PPOM_VERSION;
		}
		return '';
	}

	// ─── Push override ────────────────────────────────────

	/**
	 * Push add-ons to Odoo.
	 *
	 * Validates parent product sync and BOM model availability.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WC product ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): Sync_Result {
		if ( 'addon' === $entity_type && 'delete' !== $action ) {
			// Ensure parent product is synced.
			$product_odoo_id = $this->entity_map()->get_odoo_id( 'woocommerce', 'product', $wp_id );
			if ( ! $product_odoo_id ) {
				Queue_Manager::push( 'woocommerce', 'product', 'create', $wp_id );
				return Sync_Result::failure(
					'Parent product not yet synced — retrying later.',
					Error_Type::Transient
				);
			}

			$mode = $this->get_settings()['addon_mode'] ?? 'product_attributes';
			if ( 'bom_components' === $mode && ! $this->has_mrp_bom_model() ) {
				$this->logger->info( 'mrp.bom not available — Manufacturing module not installed in Odoo.' );
				return Sync_Result::success();
			}

			if ( 'bom_components' !== $mode ) {
				return $this->push_attribute_lines( $wp_id, (int) $product_odoo_id );
			}
		}

		// Deleting add-ons never removes attribute lines or BOMs from Odoo.
		if ( 'addon' === $entity_type && 'delete' === $action ) {
			return Sync_Result::success();
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	/**
	 * Odoo model targeted by the current add-on mode.
	 *
	 * Attribute mode writes to product.template (see push_attribute_lines()); BOM mode creates mrp.bom records.
	 *
	 * @param string $entity_type Entity type.
	 * @return string Odoo model name.
	 */
	protected function get_odoo_model( string $entity_type ): string {
		if ( 'addon' === $entity_type && 'bom_components' === ( $this->get_settings()['addon_mode'] ?? '' ) ) {
			return Odoo_Model::MrpBom->value;
		}

		return parent::get_odoo_model( $entity_type );
	}

	// ─── Attribute mode ───────────────────────────────────

	/**
	 * Push a product's add-ons as product attributes on its Odoo product.template.
	 *
	 * Each add-on becomes a `product.attribute` (found by name or created, without variants), each
	 * option a `product.attribute.value`, and the product template gets one attribute line per
	 * add-on. The option price is stored as the extra price of the template's attribute value.
	 * Safe to run repeatedly: everything is found before it is created.
	 *
	 * @param int $wp_id           WooCommerce product ID.
	 * @param int $product_odoo_id Odoo product.template or product.product ID of the parent product.
	 * @return Sync_Result
	 */
	private function push_attribute_lines( int $wp_id, int $product_odoo_id ): Sync_Result {
		$addons = $this->handler->load_addons( $wp_id );
		if ( empty( $addons ) ) {
			return Sync_Result::success();
		}

		$tmpl_id = $this->resolve_product_template_id( $product_odoo_id );
		if ( ! $tmpl_id ) {
			return Sync_Result::failure( 'Cannot resolve the Odoo product template — retrying later.', Error_Type::Transient );
		}

		try {
			$lines = $this->sync_attribute_lines( $tmpl_id, $addons );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Add-on attribute sync failed.',
				[
					'product_id' => $wp_id,
					'error'      => $e->getMessage(),
				]
			);

			return Sync_Result::failure(
				$e->getMessage(),
				$e instanceof \RuntimeException ? static::classify_exception( $e ) : Error_Type::Transient
			);
		}

		$this->save_mapping( 'addon', $wp_id, $tmpl_id, $this->generate_sync_hash( $addons ) );
		$this->logger->info(
			'Synced add-on attribute lines.',
			[
				'product_id'  => $wp_id,
				'template_id' => $tmpl_id,
				'lines'       => $lines,
			]
		);

		return Sync_Result::success( $tmpl_id );
	}

	/**
	 * Create or update the attribute lines of a product template.
	 *
	 * @param int                                                                                                     $tmpl_id Odoo product.template ID.
	 * @param array<int, array{name: string, type: string, options: array<int, array{label: string, price: float}>}> $addons  Normalized add-ons.
	 * @return int Number of attribute lines written.
	 */
	private function sync_attribute_lines( int $tmpl_id, array $addons ): int {
		$client  = $this->client();
		$written = 0;

		foreach ( $addons as $addon ) {
			if ( empty( $addon['options'] ) ) {
				continue;
			}

			$attribute_id = $client->search( 'product.attribute', [ [ 'name', '=', $addon['name'] ] ], 0, 1 )[0]
				?? $client->create(
					'product.attribute',
					[
						'name'           => $addon['name'],
						'create_variant' => 'no_variant',
					]
				);

			$value_ids = [];
			$prices    = [];
			foreach ( $addon['options'] as $option ) {
				$value_id = $client->search(
					'product.attribute.value',
					[
						[ 'attribute_id', '=', $attribute_id ],
						[ 'name', '=', $option['label'] ],
					],
					0,
					1
				)[0]
					?? $client->create(
						'product.attribute.value',
						[
							'attribute_id' => $attribute_id,
							'name'         => $option['label'],
						]
					);

				$value_ids[]         = (int) $value_id;
				$prices[ $value_id ] = (float) $option['price'];
			}

			$line = [
				'product_tmpl_id' => $tmpl_id,
				'attribute_id'    => (int) $attribute_id,
				'value_ids'       => [ [ 6, 0, $value_ids ] ],
			];

			$existing = $client->search(
				'product.template.attribute.line',
				[
					[ 'product_tmpl_id', '=', $tmpl_id ],
					[ 'attribute_id', '=', $attribute_id ],
				],
				0,
				1
			);

			if ( ! empty( $existing ) ) {
				$client->write( 'product.template.attribute.line', [ (int) $existing[0] ], [ 'value_ids' => $line['value_ids'] ] );
			} else {
				$client->create( 'product.template.attribute.line', $line );
			}

			$this->apply_extra_prices( $tmpl_id, $prices );
			++$written;
		}

		return $written;
	}

	/**
	 * Store each option's price as the template attribute value's extra price.
	 *
	 * @param int                $tmpl_id Odoo product.template ID.
	 * @param array<int, float> $prices  Extra price keyed by product.attribute.value ID.
	 * @return void
	 */
	private function apply_extra_prices( int $tmpl_id, array $prices ): void {
		$client = $this->client();

		$records = $client->search_read(
			'product.template.attribute.value',
			[
				[ 'product_tmpl_id', '=', $tmpl_id ],
				[ 'product_attribute_value_id', 'in', array_map( 'intval', array_keys( $prices ) ) ],
			],
			[ 'product_attribute_value_id', 'price_extra' ]
		);

		foreach ( $records as $record ) {
			$value = $record['product_attribute_value_id'];
			$value = is_array( $value ) ? (int) $value[0] : (int) $value;
			$price = $prices[ $value ] ?? null;

			if ( null !== $price && (float) ( $record['price_extra'] ?? 0 ) !== $price ) {
				$client->write( 'product.template.attribute.value', [ (int) $record['id'] ], [ 'price_extra' => $price ] );
			}
		}
	}

	// ─── Data access ──────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WC product ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'addon' => $this->load_addon_data( $wp_id ),
			default => [],
		};
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Add-on data is pre-formatted by handler — identity pass-through.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array<string, mixed>
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'addon' === $entity_type ) {
			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// ─── Deduplication ────────────────────────────────────

	/**
	 * Deduplication domain for add-ons.
	 *
	 * In attribute mode, dedup by product_tmpl_id (one set of attribute
	 * lines per product template).
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'addon' === $entity_type && ! empty( $odoo_values['product_tmpl_id'] ) ) {
			return [ [ 'product_tmpl_id', '=', $odoo_values['product_tmpl_id'] ] ];
		}

		return [];
	}

	// ─── Internal helpers ─────────────────────────────────

	/**
	 * Check whether Odoo has the mrp.bom model.
	 *
	 * @return bool
	 */
	private function has_mrp_bom_model(): bool {
		return $this->has_odoo_model( Odoo_Model::MrpBom, 'wp4odoo_has_mrp_bom' );
	}

	/**
	 * Load and format add-on data for a product.
	 *
	 * @param int $product_id WC product ID.
	 * @return array<string, mixed> Odoo-ready data, or empty.
	 */
	private function load_addon_data( int $product_id ): array {
		$addons = $this->handler->load_addons( $product_id );
		if ( empty( $addons ) ) {
			return [];
		}

		// Resolve parent product → Odoo product.template ID.
		$product_odoo_id = $this->entity_map()->get_odoo_id( 'woocommerce', 'product', $product_id );
		if ( ! $product_odoo_id ) {
			$this->logger->warning( 'Cannot resolve Odoo product for add-ons.', [ 'product_id' => $product_id ] );
			return [];
		}

		$tmpl_id = $this->resolve_product_template_id( $product_odoo_id );
		if ( ! $tmpl_id ) {
			$this->logger->warning( 'Cannot resolve product_tmpl_id from Odoo.', [ 'odoo_product_id' => $product_odoo_id ] );
			return [];
		}

		$mode = $this->get_settings()['addon_mode'] ?? 'product_attributes';

		if ( 'bom_components' === $mode ) {
			return $this->handler->format_as_bom_lines( $addons, $tmpl_id );
		}

		return $this->handler->format_as_attributes( $addons, $tmpl_id );
	}

	/**
	 * Resolve a mapped Odoo product ID (a product.template, or a product.product) to its product.template ID.
	 *
	 * @param int $odoo_product_id Odoo product.product ID.
	 * @return int Odoo product.template ID, or 0 on failure.
	 */
	private function resolve_product_template_id( int $odoo_product_id ): int {
		try {
			$client = $this->client();

			// WooCommerce products are mapped to product.template, so the ID is normally the template's own.
			if ( ! empty( $client->search( 'product.template', [ [ 'id', '=', $odoo_product_id ] ], 0, 1 ) ) ) {
				return $odoo_product_id;
			}

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
}
