<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Error_Classification;
use WP4Odoo\Error_Type;
use WP4Odoo\Logger;
use WP4Odoo\Sync_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock Handler — bidirectional stock sync between WooCommerce and Odoo.
 *
 * Push path (WC → Odoo): adjusts Odoo inventory using version-adaptive API:
 * - Odoo 16+: write quantity on `stock.quant` + `action_apply_inventory()`
 * - Odoo 14-15: `stock.change.product.qty` wizard + `change_product_qty()`
 *
 * Pull path (Odoo → WC) is handled by WooCommerce_Module::save_stock_data().
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
class Stock_Handler {
	use Error_Classification;

	/**
	 * Odoo version threshold for quant-based inventory adjustment.
	 *
	 * Odoo 16 deprecated the `stock.change.product.qty` wizard in favour
	 * of direct `stock.quant` writes + `action_apply_inventory()`.
	 */
	private const QUANT_API_MIN_VERSION = 16;

	/**
	 * Odoo versions that changed how a product is flagged as "storable" (able to hold stock).
	 *
	 * Odoo 14-15 use `type = 'product'`, Odoo 16-17 use `detailed_type = 'product'`, and
	 * Odoo 18+ use the `is_storable` flag (Track Inventory). Odoo refuses quants on
	 * non-storable products ("Quants cannot be created for consumables or services").
	 */
	private const DETAILED_TYPE_MIN_VERSION = 16;
	private const IS_STORABLE_MIN_VERSION   = 18;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Push a stock quantity adjustment to Odoo.
	 *
	 * Detects Odoo version and uses the appropriate API path.
	 *
	 * @param Odoo_Client $client          Odoo API client.
	 * @param int         $odoo_product_id Odoo product.product ID.
	 * @param float       $quantity        New stock quantity.
	 * @return Sync_Result
	 */
	public function push_stock( Odoo_Client $client, int $odoo_product_id, float $quantity ): Sync_Result {
		if ( $odoo_product_id <= 0 ) {
			return Sync_Result::failure(
				__( 'Stock push: no Odoo product ID.', 'wp4odoo' ),
				Error_Type::Permanent
			);
		}

		try {
			$odoo_major = $this->get_odoo_major_version();

			// With an unknown Odoo version the right field is unknown too: leave the product alone.
			if ( '' !== (string) get_option( 'wp4odoo_odoo_version', '' ) ) {
				$this->ensure_storable( $client, $odoo_product_id, $odoo_major );
			}

			if ( $odoo_major >= self::QUANT_API_MIN_VERSION ) {
				$this->push_via_quant( $client, $odoo_product_id, $quantity );
			} else {
				$this->push_via_wizard( $client, $odoo_product_id, $quantity );
			}

			$this->logger->info(
				'Pushed stock to Odoo.',
				[
					'odoo_product_id' => $odoo_product_id,
					'quantity'        => $quantity,
					'method'          => $odoo_major >= self::QUANT_API_MIN_VERSION ? 'quant' : 'wizard',
				]
			);

			return Sync_Result::success( $odoo_product_id );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Stock push failed.',
				[
					'odoo_product_id' => $odoo_product_id,
					'quantity'        => $quantity,
					'error'           => $e->getMessage(),
				]
			);

			$error_type = $e instanceof \RuntimeException ? static::classify_exception( $e ) : Error_Type::Transient;
			return Sync_Result::failure(
				sprintf(
					/* translators: %s: error message */
					__( 'Stock push failed: %s', 'wp4odoo' ),
					$e->getMessage()
				),
				$error_type
			);
		}
	}

	/**
	 * Make sure the Odoo product can hold stock.
	 *
	 * A WooCommerce product that manages stock must be storable in Odoo, otherwise
	 * Odoo refuses to create quants (or to apply the quantity wizard) for it. The
	 * field that carries this depends on the Odoo version (see the constants).
	 *
	 * @param Odoo_Client $client          Odoo API client.
	 * @param int         $odoo_product_id Odoo product.product ID.
	 * @param int         $odoo_major      Odoo major version.
	 * @return void
	 */
	private function ensure_storable( Odoo_Client $client, int $odoo_product_id, int $odoo_major ): void {
		if ( $odoo_major >= self::IS_STORABLE_MIN_VERSION ) {
			$field = 'is_storable';
			$is_ok = static fn( $value ): bool => ! empty( $value );
			$fix   = [ 'is_storable' => true ];
		} elseif ( $odoo_major >= self::DETAILED_TYPE_MIN_VERSION ) {
			$field = 'detailed_type';
			$is_ok = static fn( $value ): bool => 'product' === $value;
			$fix   = [ 'detailed_type' => 'product' ];
		} else {
			$field = 'type';
			$is_ok = static fn( $value ): bool => 'product' === $value;
			$fix   = [ 'type' => 'product' ];
		}

		$rows = $client->read( 'product.product', [ $odoo_product_id ], [ $field, 'product_tmpl_id' ] );

		if ( empty( $rows[0] ) || ! array_key_exists( $field, $rows[0] ) || $is_ok( $rows[0][ $field ] ) ) {
			return;
		}

		$template    = $rows[0]['product_tmpl_id'] ?? null;
		$template_id = is_array( $template ) ? (int) $template[0] : (int) ( $template ?? 0 );

		if ( $template_id <= 0 ) {
			return;
		}

		$client->write( 'product.template', [ $template_id ], $fix );

		$this->logger->info(
			'Made the Odoo product storable.',
			[
				'odoo_product_id'  => $odoo_product_id,
				'odoo_template_id' => $template_id,
				'field'            => $field,
			]
		);
	}

	/**
	 * Push stock via stock.quant (Odoo 16+).
	 *
	 * Finds or creates a quant for the product in the main warehouse,
	 * sets the inventory quantity, and applies the adjustment.
	 *
	 * @param Odoo_Client $client          Odoo client.
	 * @param int         $odoo_product_id Odoo product.product ID.
	 * @param float       $quantity        Target stock quantity.
	 * @return void
	 */
	private function push_via_quant( Odoo_Client $client, int $odoo_product_id, float $quantity ): void {
		// Find existing quant for this product in stock locations.
		$quants = $client->search_read(
			'stock.quant',
			[
				[ 'product_id', '=', $odoo_product_id ],
				[ 'location_id.usage', '=', 'internal' ],
			],
			[ 'id', 'location_id' ],
			0,
			1
		);

		if ( ! empty( $quants ) ) {
			$quant_id = (int) $quants[0]['id'];

			// Set inventory_quantity and apply.
			$client->write( 'stock.quant', [ $quant_id ], [ 'inventory_quantity' => $quantity ] );
			$client->execute( 'stock.quant', 'action_apply_inventory', [ [ $quant_id ] ] );
		} else {
			// No quant exists yet: create one with the quantity.
			// Find the default stock location (stock.warehouse main location).
			$warehouses = $client->search_read(
				'stock.warehouse',
				[],
				[ 'lot_stock_id' ],
				0,
				1
			);

			$location_id = 0;
			if ( ! empty( $warehouses ) ) {
				$lot_stock   = $warehouses[0]['lot_stock_id'] ?? null;
				$location_id = is_array( $lot_stock ) ? (int) $lot_stock[0] : (int) ( $lot_stock ?? 0 );
			}

			if ( $location_id <= 0 ) {
				throw new \RuntimeException( __( 'Cannot determine warehouse stock location.', 'wp4odoo' ) );
			}

			$quant_id = $client->create(
				'stock.quant',
				[
					'product_id'         => $odoo_product_id,
					'location_id'        => $location_id,
					'inventory_quantity' => $quantity,
				]
			);

			$client->execute( 'stock.quant', 'action_apply_inventory', [ [ $quant_id ] ] );
		}
	}

	/**
	 * Push stock via stock.change.product.qty wizard (Odoo 14-15).
	 *
	 * Creates a wizard record and calls change_product_qty() to apply.
	 *
	 * @param Odoo_Client $client          Odoo client.
	 * @param int         $odoo_product_id Odoo product.product ID.
	 * @param float       $quantity        Target stock quantity.
	 * @return void
	 */
	private function push_via_wizard( Odoo_Client $client, int $odoo_product_id, float $quantity ): void {
		$wizard_id = $client->create(
			'stock.change.product.qty',
			[
				'product_id'   => $odoo_product_id,
				'new_quantity' => $quantity,
			]
		);

		$client->execute( 'stock.change.product.qty', 'change_product_qty', [ [ $wizard_id ] ] );
	}

	/**
	 * Get the major Odoo version from the stored option.
	 *
	 * @return int Major version number (e.g. 14, 16, 17). Defaults to 16 if unknown.
	 */
	private function get_odoo_major_version(): int {
		$version = (string) get_option( 'wp4odoo_odoo_version', '' );

		if ( '' === $version ) {
			return self::QUANT_API_MIN_VERSION; // Default to newer API.
		}

		return (int) $version;
	}
}
