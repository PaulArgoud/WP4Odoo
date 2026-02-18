<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for Odoo data fetching and bulk operations.
 *
 * Provides endpoints for fetching taxes, carriers, and languages
 * from the connected Odoo instance, and for bulk WooCommerce
 * import/export operations.
 *
 * Used by Admin_Ajax via trait composition.
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
trait Ajax_Data_Handlers {

	/**
	 * Bulk import all products from Odoo into WooCommerce.
	 *
	 * @return void
	 */
	public function bulk_import_products(): void {
		$this->verify_request();

		$plugin = \WP4Odoo_Plugin::instance();
		if ( null === $plugin->get_module( 'woocommerce' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'WooCommerce module is not registered.', 'wp4odoo' ),
				]
			);
		}

		$handler = new Bulk_Handler( $plugin->client(), new \WP4Odoo\Entity_Map_Repository() );
		wp_send_json_success( $handler->import_products() );
	}

	/**
	 * Bulk export all WooCommerce products to Odoo.
	 *
	 * @return void
	 */
	public function bulk_export_products(): void {
		$this->verify_request();

		$plugin = \WP4Odoo_Plugin::instance();
		if ( null === $plugin->get_module( 'woocommerce' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'WooCommerce module is not registered.', 'wp4odoo' ),
				]
			);
		}

		$handler = new Bulk_Handler( $plugin->client(), new \WP4Odoo\Entity_Map_Repository() );
		wp_send_json_success( $handler->export_products() );
	}

	/**
	 * Fetch available Odoo taxes for tax mapping.
	 *
	 * Reads account.tax records with type_tax_use = 'sale' from Odoo,
	 * returning ID, name, and amount for the admin mapping UI.
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	public function fetch_odoo_taxes(): void {
		$this->verify_request();

		try {
			$client  = \WP4Odoo_Plugin::instance()->client();
			$records = $client->search_read(
				'account.tax',
				[ [ 'type_tax_use', '=', 'sale' ] ],
				[ 'id', 'name', 'amount' ]
			);

			wp_send_json_success( [ 'items' => $records ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				[
					'message' => __( 'Failed to fetch Odoo taxes.', 'wp4odoo' ) . ' ' . $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Fetch available Odoo delivery carriers for shipping mapping.
	 *
	 * Reads delivery.carrier records from Odoo, returning ID and name
	 * for the admin mapping UI.
	 *
	 * @since 3.2.0
	 *
	 * @return void
	 */
	public function fetch_odoo_carriers(): void {
		$this->verify_request();

		try {
			$client  = \WP4Odoo_Plugin::instance()->client();
			$records = $client->search_read(
				'delivery.carrier',
				[],
				[ 'id', 'name' ]
			);

			wp_send_json_success( [ 'items' => $records ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				[
					'message' => __( 'Failed to fetch Odoo carriers.', 'wp4odoo' ) . ' ' . $e->getMessage(),
				]
			);
		}
	}

	/**
	 * Detect translation languages and Odoo availability.
	 *
	 * Probes the active translation plugin (WPML/Polylang) for available
	 * languages, then checks which ones are installed in Odoo via res.lang.
	 *
	 * @since 3.0.5
	 *
	 * @return void
	 */
	public function detect_languages(): void {
		$this->verify_request();

		$ts = new \WP4Odoo\I18n\Translation_Service(
			static fn() => \WP4Odoo_Plugin::instance()->client()
		);

		$result = $ts->detect_languages();

		if ( null === $result ) {
			wp_send_json_success(
				[
					'available' => false,
					'message'   => __( 'No translation plugin detected (WPML or Polylang required).', 'wp4odoo' ),
				]
			);
		}

		wp_send_json_success(
			[
				'available' => true,
				'plugin'    => $result['plugin'],
				'default'   => $result['default_language'],
				'languages' => $result['languages'],
			]
		);
	}
}
