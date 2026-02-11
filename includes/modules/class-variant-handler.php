<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Entity_Map_Repository;
use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles product variant import from Odoo (product.product → WC variations).
 *
 * Converts Odoo product.product records into WooCommerce product variations
 * under a variable product parent. Called by WooCommerce_Module after a
 * product.template pull when multiple variants exist.
 *
 * @package WP4Odoo
 * @since   1.4.0
 */
class Variant_Handler {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Closure returning the Odoo_Client.
	 *
	 * @var \Closure
	 */
	private \Closure $client_fn;

	/**
	 * Exchange rate service for currency conversion.
	 *
	 * @var Exchange_Rate_Service|null
	 */
	private ?Exchange_Rate_Service $rate_service;

	/**
	 * Whether currency conversion is enabled.
	 *
	 * @var bool
	 */
	private bool $convert_currency;

	/**
	 * Entity map repository.
	 *
	 * @var \WP4Odoo\Entity_Map_Repository
	 */
	private \WP4Odoo\Entity_Map_Repository $entity_map;

	/**
	 * Constructor.
	 *
	 * @param Logger                          $logger           Logger instance.
	 * @param \Closure                        $client_fn        Closure returning \WP4Odoo\API\Odoo_Client.
	 * @param \WP4Odoo\Entity_Map_Repository  $entity_map       Entity map repository.
	 * @param Exchange_Rate_Service|null       $rate_service     Exchange rate service (null to disable conversion).
	 * @param bool                            $convert_currency Whether to convert prices on currency mismatch.
	 */
	public function __construct( Logger $logger, \Closure $client_fn, \WP4Odoo\Entity_Map_Repository $entity_map, ?Exchange_Rate_Service $rate_service = null, bool $convert_currency = false ) {
		$this->logger           = $logger;
		$this->client_fn        = $client_fn;
		$this->entity_map       = $entity_map;
		$this->rate_service     = $rate_service;
		$this->convert_currency = $convert_currency;
	}

	/**
	 * Pull all variants for a given Odoo product.template into WooCommerce.
	 *
	 * Reads product.product records linked to the template. If only one variant
	 * exists with no attribute values, the product is treated as simple and skipped.
	 * Otherwise, the parent WC product is converted to variable and variations are
	 * created/updated.
	 *
	 * @param int $template_odoo_id The Odoo product.template ID.
	 * @param int $wp_parent_id     The WC parent product ID.
	 * @return bool True on success.
	 */
	public function pull_variants( int $template_odoo_id, int $wp_parent_id ): bool {
		$client = ( $this->client_fn )();

		$variants = $client->search_read(
			'product.product',
			[ [ 'product_tmpl_id', '=', $template_odoo_id ] ],
			[ 'id', 'default_code', 'lst_price', 'qty_available', 'weight', 'display_name', 'product_template_attribute_value_ids', 'currency_id' ]
		);

		if ( empty( $variants ) ) {
			$this->logger->info(
				'No variants found for template.',
				[
					'template_odoo_id' => $template_odoo_id,
				]
			);
			return true;
		}

		// Single variant with no attributes: simple product, skip.
		if ( 1 === count( $variants ) ) {
			$attr_ids = $variants[0]['product_template_attribute_value_ids'] ?? [];
			if ( empty( $attr_ids ) ) {
				$this->logger->info(
					'Single variant without attributes, skipping (simple product).',
					[
						'template_odoo_id' => $template_odoo_id,
					]
				);
				return true;
			}
		}

		// Convert WC product to variable if needed.
		$parent = $this->ensure_variable_product( $wp_parent_id );
		if ( ! $parent ) {
			$this->logger->error(
				'Failed to ensure variable product.',
				[
					'wp_parent_id' => $wp_parent_id,
				]
			);
			return false;
		}

		// Resolve attributes for all variants.
		$all_attributes = $this->collect_attributes( $client, $variants );

		// Register product-level attributes on the parent.
		if ( ! empty( $all_attributes ) ) {
			$this->set_parent_attributes( $parent, $all_attributes );
		}

		// Create/update each variation.
		foreach ( $variants as $variant ) {
			$variant_odoo_id = (int) $variant['id'];

			// Check existing mapping.
			$existing_wp_id = $this->entity_map->get_wp_id( 'woocommerce', 'variant', $variant_odoo_id );

			// Currency guard: skip or convert price if Odoo currency ≠ WC shop currency.
			$guard      = Currency_Guard::check( $variant['currency_id'] ?? false );
			$skip_price = $guard['mismatch'];

			if ( $skip_price && $this->convert_currency && null !== $this->rate_service ) {
				$raw_price = (float) ( $variant['lst_price'] ?? 0 );
				if ( $raw_price > 0.0 ) {
					$converted = $this->rate_service->convert( $raw_price, $guard['odoo_currency'], $guard['wc_currency'] );

					if ( null !== $converted ) {
						$variant['lst_price'] = $converted;
						$skip_price           = false;

						$this->logger->info(
							'Variant price converted.',
							[
								'variant_odoo_id' => $variant_odoo_id,
								'from'            => $guard['odoo_currency'],
								'to'              => $guard['wc_currency'],
								'original'        => $raw_price,
								'converted'       => $converted,
							]
						);
					}
				}
			}

			if ( $skip_price ) {
				$this->logger->warning(
					'Variant currency mismatch, skipping price.',
					[
						'variant_odoo_id' => $variant_odoo_id,
						'odoo_currency'   => $guard['odoo_currency'],
						'wc_currency'     => $guard['wc_currency'],
					]
				);
			}

			$variation_data = [
				'sku'            => $variant['default_code'] ?? '',
				'stock_quantity' => $variant['qty_available'] ?? 0,
				'weight'         => $variant['weight'] ?? 0,
			];

			if ( ! $skip_price ) {
				$variation_data['regular_price'] = $variant['lst_price'] ?? 0;
			}

			// Resolve attribute values for this variant.
			$attr_value_ids = $variant['product_template_attribute_value_ids'] ?? [];
			$attributes     = [];
			if ( ! empty( $attr_value_ids ) && ! empty( $all_attributes['_resolved'] ) ) {
				foreach ( $attr_value_ids as $val_id ) {
					if ( isset( $all_attributes['_resolved'][ $val_id ] ) ) {
						$pair                = $all_attributes['_resolved'][ $val_id ];
						$slug                = 'pa_' . sanitize_title( $pair['attribute'] );
						$attributes[ $slug ] = $pair['value'];
					}
				}
			}

			$variation_id = $this->save_variation(
				$wp_parent_id,
				$variation_data,
				$attributes,
				$existing_wp_id ?: 0
			);

			if ( $variation_id > 0 ) {
				$this->entity_map->save(
					'woocommerce',
					'variant',
					$variation_id,
					$variant_odoo_id,
					'product.product'
				);

				$this->logger->info(
					'Variant synced.',
					[
						'variant_odoo_id' => $variant_odoo_id,
						'variation_wp_id' => $variation_id,
						'parent_wp_id'    => $wp_parent_id,
					]
				);
			}
		}

		return true;
	}

	/**
	 * Ensure a WC product is of type "variable".
	 *
	 * If the product is currently simple, converts it to variable.
	 *
	 * @param int $wp_product_id WC product ID.
	 * @return \WC_Product_Variable|null The variable product, or null on failure.
	 */
	public function ensure_variable_product( int $wp_product_id ): ?\WC_Product_Variable {
		$product = wc_get_product( $wp_product_id );

		if ( ! $product ) {
			return null;
		}

		if ( $product instanceof \WC_Product_Variable ) {
			return $product;
		}

		// Convert simple → variable.
		wp_set_object_terms( $wp_product_id, 'variable', 'product_type' );

		return new \WC_Product_Variable( $wp_product_id );
	}

	/**
	 * Create or update a WC product variation.
	 *
	 * @param int   $parent_id      WC parent product ID.
	 * @param array $variant_data   Variant data (sku, regular_price, stock_quantity, weight).
	 * @param array $attributes     Variation attributes (pa_slug => value).
	 * @param int   $existing_wp_id Existing variation ID (0 to create).
	 * @return int Variation ID or 0 on failure.
	 */
	public function save_variation( int $parent_id, array $variant_data, array $attributes = [], int $existing_wp_id = 0 ): int {
		if ( $existing_wp_id > 0 ) {
			$variation = wc_get_product( $existing_wp_id );
			if ( ! $variation || ! ( $variation instanceof \WC_Product_Variation ) ) {
				$variation = new \WC_Product_Variation();
			}
		} else {
			$variation = new \WC_Product_Variation();
		}

		$variation->set_parent_id( $parent_id );

		$sku = is_string( $variant_data['sku'] ?? false ) ? $variant_data['sku'] : '';
		if ( '' !== $sku ) {
			$variation->set_sku( $sku );
		}

		if ( isset( $variant_data['regular_price'] ) ) {
			$variation->set_regular_price( (string) $variant_data['regular_price'] );
		}

		if ( isset( $variant_data['stock_quantity'] ) ) {
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( (int) $variant_data['stock_quantity'] );
		}

		if ( isset( $variant_data['weight'] ) && $variant_data['weight'] ) {
			$variation->set_weight( (string) $variant_data['weight'] );
		}

		if ( ! empty( $attributes ) ) {
			$variation->set_attributes( $attributes );
		}

		$saved_id = $variation->save();

		return $saved_id > 0 ? $saved_id : 0;
	}

	/**
	 * Collect and resolve all attribute values across all variants.
	 *
	 * Reads product.template.attribute.value from Odoo in a single batch
	 * and returns structured data for parent attribute registration.
	 *
	 * @param object $client   Odoo_Client instance.
	 * @param array  $variants Array of Odoo variant records.
	 * @return array Attribute data including '_resolved' map.
	 */
	private function collect_attributes( object $client, array $variants ): array {
		$all_value_ids = [];
		foreach ( $variants as $variant ) {
			$ids = $variant['product_template_attribute_value_ids'] ?? [];
			if ( is_array( $ids ) ) {
				$all_value_ids = array_merge( $all_value_ids, $ids );
			}
		}

		$all_value_ids = array_unique( array_map( 'intval', $all_value_ids ) );

		if ( empty( $all_value_ids ) ) {
			return [];
		}

		$records = $client->read(
			'product.template.attribute.value',
			array_values( $all_value_ids ),
			[ 'attribute_id', 'name' ]
		);

		if ( empty( $records ) ) {
			return [];
		}

		$resolved = [];
		$by_attr  = []; // attribute_name => [value1, value2, ...]

		foreach ( $records as $record ) {
			$attr_name  = is_array( $record['attribute_id'] ?? null )
				? (string) $record['attribute_id'][1]
				: (string) ( $record['attribute_id'] ?? '' );
			$value_name = (string) ( $record['name'] ?? '' );
			$record_id  = (int) ( $record['id'] ?? 0 );

			if ( '' === $attr_name || '' === $value_name || 0 === $record_id ) {
				continue;
			}

			$resolved[ $record_id ] = [
				'attribute' => $attr_name,
				'value'     => $value_name,
			];

			if ( ! isset( $by_attr[ $attr_name ] ) ) {
				$by_attr[ $attr_name ] = [];
			}
			if ( ! in_array( $value_name, $by_attr[ $attr_name ], true ) ) {
				$by_attr[ $attr_name ][] = $value_name;
			}
		}

		return [
			'_resolved' => $resolved,
			'_by_attr'  => $by_attr,
		];
	}

	/**
	 * Register product-level attributes on the variable product.
	 *
	 * Creates WC_Product_Attribute objects for each Odoo attribute
	 * and assigns them to the parent product.
	 *
	 * @param \WC_Product_Variable $parent         The parent variable product.
	 * @param array                $all_attributes  Attribute data from collect_attributes().
	 * @return void
	 */
	private function set_parent_attributes( \WC_Product_Variable $parent, array $all_attributes ): void {
		$by_attr = $all_attributes['_by_attr'] ?? [];

		if ( empty( $by_attr ) ) {
			return;
		}

		$product_attributes = [];
		$position           = 0;

		foreach ( $by_attr as $attr_name => $values ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( 'pa_' . sanitize_title( $attr_name ) );
			$attribute->set_options( $values );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attribute->set_position( $position++ );

			$product_attributes[] = $attribute;
		}

		$parent->set_attributes( $product_attributes );
		$parent->save();
	}
}
