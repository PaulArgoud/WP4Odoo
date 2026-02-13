<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ecwid polling via WP-Cron.
 *
 * Ecwid is a cloud-hosted e-commerce platform. Product and order data
 * lives on Ecwid servers, not in WordPress. This trait implements
 * WP-Cron-based polling using the Ecwid REST API to detect changes
 * via SHA-256 hash comparison (same pattern as Bookly).
 *
 * Expects the using class to provide:
 * - is_importing(): bool              (from Module_Base)
 * - get_settings(): array             (from Module_Base)
 * - generate_sync_hash(): string      (from Module_Base)
 * - entity_map(): Entity_Map_Repository (from Module_Base)
 * - logger: Logger                    (from Module_Base)
 * - get_handler(): Ecwid_Handler      (from Ecwid_Module)
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
trait Ecwid_Cron_Hooks {

	/**
	 * Register the WP-Cron polling event.
	 *
	 * @return void
	 */
	protected function register_cron(): void {
		if ( ! defined( 'ECWID_PLUGIN_DIR' ) ) {
			$this->logger->warning( __( 'Ecwid module enabled but Ecwid is not active.', 'wp4odoo' ) );
			return;
		}

		if ( ! wp_next_scheduled( 'wp4odoo_ecwid_poll' ) ) {
			wp_schedule_event( time(), 'wp4odoo_five_minutes', 'wp4odoo_ecwid_poll' );
		}

		add_action( 'wp4odoo_ecwid_poll', [ $this, 'poll' ] );
	}

	/**
	 * Poll Ecwid API for changes.
	 *
	 * @return void
	 */
	public function poll(): void {
		if ( $this->is_importing() ) {
			return;
		}

		global $wpdb;
		$lock_name = 'wp4odoo_ecwid_poll';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( '1' !== $acquired ) {
			return;
		}

		try {
			$settings = $this->get_settings();
			$store_id = (string) ( $settings['ecwid_store_id'] ?? '' );
			$token    = (string) ( $settings['ecwid_api_token'] ?? '' );

			if ( '' === $store_id || '' === $token ) {
				return;
			}

			if ( ! empty( $settings['sync_products'] ) ) {
				$this->poll_products( $store_id, $token );
			}

			if ( ! empty( $settings['sync_orders'] ) ) {
				$this->poll_orders( $store_id, $token );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Poll products: detect new, changed, and deleted products.
	 *
	 * @param string $store_id  Ecwid store ID.
	 * @param string $token     Ecwid API token.
	 * @return void
	 */
	private function poll_products( string $store_id, string $token ): void {
		$products = $this->get_handler()->fetch_products( $store_id, $token );
		$existing = $this->entity_map()->get_module_entity_mappings( 'ecwid', 'product' );
		$seen_ids = [];

		foreach ( $products as $product ) {
			$ecwid_id   = (int) ( $product['id'] ?? 0 );
			$seen_ids[] = $ecwid_id;

			$hash_data = $product;
			unset( $hash_data['id'] );
			$hash = $this->generate_sync_hash( $hash_data );

			if ( ! isset( $existing[ $ecwid_id ] ) ) {
				Queue_Manager::push( 'ecwid', 'product', 'create', $ecwid_id );
			} elseif ( $existing[ $ecwid_id ]['sync_hash'] !== $hash ) {
				Queue_Manager::push( 'ecwid', 'product', 'update', $ecwid_id, $existing[ $ecwid_id ]['odoo_id'] );
			}
		}

		$seen_lookup = array_flip( $seen_ids );
		foreach ( $existing as $wp_id => $map ) {
			if ( ! isset( $seen_lookup[ $wp_id ] ) ) {
				Queue_Manager::push( 'ecwid', 'product', 'delete', $wp_id, $map['odoo_id'] );
			}
		}
	}

	/**
	 * Poll orders: detect new, changed, and deleted orders.
	 *
	 * @param string $store_id  Ecwid store ID.
	 * @param string $token     Ecwid API token.
	 * @return void
	 */
	private function poll_orders( string $store_id, string $token ): void {
		$orders   = $this->get_handler()->fetch_orders( $store_id, $token );
		$existing = $this->entity_map()->get_module_entity_mappings( 'ecwid', 'order' );
		$seen_ids = [];

		foreach ( $orders as $order ) {
			$ecwid_id   = (int) ( $order['orderNumber'] ?? 0 );
			$seen_ids[] = $ecwid_id;

			$hash_data = $order;
			unset( $hash_data['orderNumber'] );
			$hash = $this->generate_sync_hash( $hash_data );

			if ( ! isset( $existing[ $ecwid_id ] ) ) {
				Queue_Manager::push( 'ecwid', 'order', 'create', $ecwid_id );
			} elseif ( $existing[ $ecwid_id ]['sync_hash'] !== $hash ) {
				Queue_Manager::push( 'ecwid', 'order', 'update', $ecwid_id, $existing[ $ecwid_id ]['odoo_id'] );
			}
		}

		$seen_lookup = array_flip( $seen_ids );
		foreach ( $existing as $wp_id => $map ) {
			if ( ! isset( $seen_lookup[ $wp_id ] ) ) {
				Queue_Manager::push( 'ecwid', 'order', 'delete', $wp_id, $map['odoo_id'] );
			}
		}
	}
}
