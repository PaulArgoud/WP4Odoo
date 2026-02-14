<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce hook callbacks for push operations.
 *
 * Extracted from WooCommerce_Module for single responsibility.
 * Handles product save/delete, order creation, and order status changes.
 *
 * Expects the using class to provide:
 * - is_importing(): bool              (from Module_Base)
 * - get_mapping(): ?int               (from Module_Base)
 * - partner_service: Partner_Service  (private property)
 * - translation_service(): Translation_Service (from Module_Helpers)
 * - logger: Logger                    (from Module_Base)
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
trait WooCommerce_Hooks {

	/**
	 * Handle product save in WooCommerce.
	 *
	 * @param int $product_id WC product ID.
	 * @return void
	 */
	public function on_product_save( int $product_id ): void {
		if ( ! $this->should_sync( 'sync_products' ) ) {
			return;
		}

		// If this is a translated post, enqueue translations for the original instead.
		$ts = $this->translation_service();
		if ( $ts->is_available() ) {
			$adapter = $ts->get_adapter();
			if ( $adapter && $adapter->is_translation( $product_id ) ) {
				$original_id = $adapter->get_original_post_id( $product_id );
				if ( $original_id !== $product_id ) {
					$this->enqueue_product_translations( $original_id );
					return;
				}
			}
		}

		$odoo_id = $this->get_mapping( 'product', $product_id );
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'woocommerce', 'product', $action, $product_id, $odoo_id ?? 0 );

		$this->enqueue_product_translations( $product_id );
	}

	/**
	 * Enqueue translation pushes for a product's translated posts.
	 *
	 * For each translation (WPML/Polylang), enqueues an 'update' job
	 * with a _translate payload flag so WooCommerce_Module::push_to_odoo()
	 * can intercept and push translated fields via Translation_Service.
	 *
	 * @param int $product_id Original (source) product ID.
	 * @return void
	 */
	private function enqueue_product_translations( int $product_id ): void {
		$ts = $this->translation_service();
		if ( ! $ts->is_available() ) {
			return;
		}

		$adapter = $ts->get_adapter();
		if ( ! $adapter ) {
			return;
		}

		if ( $adapter->is_translation( $product_id ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'product', $product_id );
		if ( ! $odoo_id ) {
			return;
		}

		$translations = $adapter->get_translations( $product_id );
		foreach ( $translations as $lang => $translated_post_id ) {
			Queue_Manager::push(
				'woocommerce',
				'product',
				'update',
				$product_id,
				$odoo_id,
				[
					'_translate'     => true,
					'_lang'          => $lang,
					'_translated_id' => $translated_post_id,
				]
			);
		}
	}

	/**
	 * Handle product deletion.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_product_delete( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'product', $post_id );
		if ( $odoo_id ) {
			Queue_Manager::push( 'woocommerce', 'product', 'delete', $post_id, $odoo_id );
		}
	}

	/**
	 * Handle new WooCommerce order.
	 *
	 * @param int $order_id WC order ID.
	 * @return void
	 */
	public function on_new_order( int $order_id ): void {
		if ( ! $this->should_sync( 'sync_orders' ) ) {
			return;
		}

		// Link customer to Odoo partner via Partner_Service.
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$email = $order->get_billing_email();
			$name  = $order->get_formatted_billing_full_name();
			if ( $email ) {
				$user_id = $order->get_customer_id();
				$this->partner_service->get_or_create( $email, [ 'name' => $name ], $user_id );
			}
		}

		Queue_Manager::push( 'woocommerce', 'order', 'create', $order_id );
	}

	/**
	 * Handle order status change.
	 *
	 * @param int    $order_id   WC order ID.
	 * @param string $old_status Old status.
	 * @param string $new_status New status.
	 * @return void
	 */
	public function on_order_status_changed( int $order_id, string $old_status, string $new_status ): void {
		if ( ! $this->should_sync( 'sync_orders' ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'order', $order_id ) ?? 0;
		Queue_Manager::push( 'woocommerce', 'order', 'update', $order_id, $odoo_id );
	}
}
