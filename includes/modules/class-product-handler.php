<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Handler — WooCommerce product/variant data access.
 *
 * Centralises load, save and delete operations for WC products
 * and variations. Called by WooCommerce_Module via its
 * load_wp_data / save_wp_data / delete_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   1.8.0
 */
class Product_Handler {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

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
	 * Constructor.
	 *
	 * @param Logger                     $logger           Logger instance.
	 * @param Exchange_Rate_Service|null $rate_service     Exchange rate service (null to disable conversion).
	 * @param bool                       $convert_currency Whether to convert prices on currency mismatch.
	 */
	public function __construct( Logger $logger, ?Exchange_Rate_Service $rate_service = null, bool $convert_currency = false ) {
		$this->logger           = $logger;
		$this->rate_service     = $rate_service;
		$this->convert_currency = $convert_currency;
	}

	// ─── Load ────────────────────────────────────────────────

	/**
	 * Load WooCommerce product data.
	 *
	 * @param int $wp_id Product ID.
	 * @return array
	 */
	public function load( int $wp_id ): array {
		$product = wc_get_product( $wp_id );
		if ( ! $product ) {
			return [];
		}

		return [
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'regular_price'  => $product->get_regular_price(),
			'stock_quantity' => $product->get_stock_quantity(),
			'weight'         => $product->get_weight(),
			'description'    => $product->get_description(),
		];
	}

	/**
	 * Load WooCommerce variation data.
	 *
	 * @param int $wp_id Variation ID.
	 * @return array
	 */
	public function load_variant( int $wp_id ): array {
		$product = wc_get_product( $wp_id );
		if ( ! $product ) {
			return [];
		}

		return [
			'sku'            => $product->get_sku(),
			'regular_price'  => $product->get_regular_price(),
			'stock_quantity' => $product->get_stock_quantity(),
			'weight'         => $product->get_weight(),
			'display_name'   => $product->get_name(),
		];
	}

	// ─── Save ────────────────────────────────────────────────

	/**
	 * Save product data to WooCommerce.
	 *
	 * @param array $data  Mapped product data.
	 * @param int   $wp_id Existing product ID (0 to create).
	 * @return int Product ID or 0 on failure.
	 */
	public function save( array $data, int $wp_id = 0 ): int {
		if ( $wp_id > 0 ) {
			$product = wc_get_product( $wp_id );
		} else {
			$product = new \WC_Product();
		}

		if ( ! $product ) {
			$this->logger->error( 'Failed to get or create WC product.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		// Currency guard: skip or convert price if Odoo currency ≠ WC shop currency.
		$guard             = Currency_Guard::check( $data['_wp4odoo_currency'] ?? null );
		$currency_mismatch = $guard['mismatch'];
		$odoo_currency     = $guard['odoo_currency'];

		if ( $currency_mismatch && $this->convert_currency && null !== $this->rate_service && isset( $data['regular_price'] ) ) {
			$original  = (float) $data['regular_price'];
			$converted = $this->rate_service->convert( $original, $guard['odoo_currency'], $guard['wc_currency'] );

			if ( null !== $converted ) {
				$data['regular_price'] = $converted;
				$currency_mismatch     = false;

				$this->logger->info(
					'Product price converted.',
					[
						'wp_product_id' => $wp_id,
						'from'          => $guard['odoo_currency'],
						'to'            => $guard['wc_currency'],
						'original'      => $original,
						'converted'     => $converted,
					]
				);
			}
		}

		if ( $currency_mismatch ) {
			$this->logger->warning(
				'Product currency mismatch, skipping price update.',
				[
					'wp_product_id' => $wp_id,
					'odoo_currency' => $guard['odoo_currency'],
					'wc_currency'   => $guard['wc_currency'],
				]
			);
		}

		if ( isset( $data['name'] ) ) {
			$product->set_name( $data['name'] );
		}
		if ( isset( $data['sku'] ) ) {
			$product->set_sku( $data['sku'] );
		}
		if ( isset( $data['regular_price'] ) && ! $currency_mismatch ) {
			$product->set_regular_price( (string) $data['regular_price'] );
		}
		if ( isset( $data['weight'] ) ) {
			$product->set_weight( (string) $data['weight'] );
		}
		if ( isset( $data['description'] ) ) {
			$product->set_description( $data['description'] );
		}
		if ( isset( $data['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $data['stock_quantity'] );
		}

		$saved_id = $product->save();

		// Store Odoo currency code in product meta.
		if ( $saved_id > 0 && '' !== $odoo_currency ) {
			update_post_meta( $saved_id, '_wp4odoo_currency', $odoo_currency );
		}

		// Assign product category from Odoo categ_id (Many2one: [id, name]).
		if ( $saved_id > 0 ) {
			$this->assign_category( $saved_id, $data );
		}

		return $saved_id > 0 ? $saved_id : 0;
	}

	/**
	 * Save variant (variation) data to WooCommerce.
	 *
	 * Handles the base class save_wp_data path for existing variations.
	 * New variation creation is handled by Variant_Handler.
	 *
	 * @param array $data  Mapped variant data.
	 * @param int   $wp_id Existing variation ID (0 to create).
	 * @return int Variation ID or 0 on failure.
	 */
	public function save_variant( array $data, int $wp_id = 0 ): int {
		if ( $wp_id > 0 ) {
			$variation = wc_get_product( $wp_id );
			if ( ! $variation ) {
				return 0;
			}

			// Currency guard: skip or convert price if Odoo currency ≠ WC shop currency.
			$guard             = Currency_Guard::check( $data['_wp4odoo_currency'] ?? null );
			$currency_mismatch = $guard['mismatch'];

			if ( $currency_mismatch && $this->convert_currency && null !== $this->rate_service && isset( $data['regular_price'] ) ) {
				$converted = $this->rate_service->convert(
					(float) $data['regular_price'],
					$guard['odoo_currency'],
					$guard['wc_currency']
				);

				if ( null !== $converted ) {
					$data['regular_price'] = $converted;
					$currency_mismatch     = false;
				}
			}

			if ( isset( $data['sku'] ) && '' !== $data['sku'] ) {
				$variation->set_sku( $data['sku'] );
			}
			if ( isset( $data['regular_price'] ) && ! $currency_mismatch ) {
				$variation->set_regular_price( (string) $data['regular_price'] );
			}
			if ( isset( $data['stock_quantity'] ) ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( (int) $data['stock_quantity'] );
			}
			if ( isset( $data['weight'] ) && $data['weight'] ) {
				$variation->set_weight( (string) $data['weight'] );
			}

			$saved_id = $variation->save();
			return $saved_id > 0 ? $saved_id : 0;
		}

		// Cannot create variation without parent context; handled by Variant_Handler.
		$this->logger->warning( 'Variant creation without parent context is not supported via save_wp_data.' );
		return 0;
	}

	// ─── Category assignment ────────────────────────────────

	/**
	 * Assign a product_cat term from Odoo's categ_id Many2one field.
	 *
	 * The categ_id is a Many2one that returns [id, name] from Odoo.
	 * We find-or-create the product_cat term by name and assign it.
	 *
	 * @since 3.1.0
	 *
	 * @param int   $wp_id Product ID.
	 * @param array $data  Mapped product data.
	 * @return void
	 */
	private function assign_category( int $wp_id, array $data ): void {
		$categ_id = $data['_wp4odoo_categ_id'] ?? null;
		if ( ! $categ_id ) {
			return;
		}

		// Many2one: [id, name] tuple.
		$categ_name = is_array( $categ_id ) ? (string) ( $categ_id[1] ?? '' ) : '';

		if ( '' === $categ_name ) {
			return;
		}

		$term = term_exists( $categ_name, 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( $categ_name, 'product_cat' );
		}

		if ( ! is_wp_error( $term ) ) {
			$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term ); // @phpstan-ignore function.alreadyNarrowedType
			wp_set_object_terms( $wp_id, [ $term_id ], 'product_cat' );
		}
	}

	// ─── Delete ──────────────────────────────────────────────

	/**
	 * Delete a WooCommerce product or variation.
	 *
	 * @param int $wp_id Product/variation ID.
	 * @return bool True on success.
	 */
	public function delete( int $wp_id ): bool {
		$product = wc_get_product( $wp_id );
		if ( $product ) {
			$product->delete( true );
			return true;
		}
		return false;
	}
}
