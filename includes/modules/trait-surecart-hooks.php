<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SureCart hook callbacks for push sync.
 *
 * Expects the using class to provide:
 * - is_importing(): bool        (from Module_Base)
 * - should_sync(string): bool   (from Module_Base)
 * - push_entity(string, string, int): void (from Sync_Helpers)
 * - safe_callback(callable): Closure       (from Module_Base)
 * - logger: Logger              (from Module_Base)
 *
 * @package WP4Odoo
 * @since   3.5.0
 */
trait SureCart_Hooks {

	/**
	 * Handle SureCart product creation (push to Odoo queue).
	 *
	 * Hooked to `surecart/product_created`.
	 *
	 * @param object $product SureCart product object.
	 * @return void
	 */
	public function on_product_created( $product ): void {
		$product_id = (int) ( $product->id ?? 0 );
		if ( 0 === $product_id ) {
			return;
		}

		$this->push_entity( 'product', 'sync_products', $product_id );
	}

	/**
	 * Handle SureCart product update (push to Odoo queue).
	 *
	 * Hooked to `surecart/product_updated`.
	 *
	 * @param object $product SureCart product object.
	 * @return void
	 */
	public function on_product_updated( $product ): void {
		$product_id = (int) ( $product->id ?? 0 );
		if ( 0 === $product_id ) {
			return;
		}

		$this->push_entity( 'product', 'sync_products', $product_id );
	}

	/**
	 * Handle SureCart checkout/order creation (push to Odoo queue).
	 *
	 * Hooked to `surecart/checkout_confirmed`.
	 *
	 * @param object $checkout SureCart checkout object.
	 * @return void
	 */
	public function on_order_created( $checkout ): void {
		$order_id = (int) ( $checkout->id ?? 0 );
		if ( 0 === $order_id ) {
			return;
		}

		$this->push_entity( 'order', 'sync_orders', $order_id );
	}

	/**
	 * Handle SureCart subscription creation (push to Odoo queue).
	 *
	 * Hooked to `surecart/subscription_created`.
	 *
	 * @param object $subscription SureCart subscription object.
	 * @return void
	 */
	public function on_subscription_created( $subscription ): void {
		$sub_id = (int) ( $subscription->id ?? 0 );
		if ( 0 === $sub_id ) {
			return;
		}

		$this->push_entity( 'subscription', 'sync_subscriptions', $sub_id );
	}

	/**
	 * Handle SureCart subscription update (push to Odoo queue).
	 *
	 * Hooked to `surecart/subscription_updated`.
	 *
	 * @param object $subscription SureCart subscription object.
	 * @return void
	 */
	public function on_subscription_updated( $subscription ): void {
		$sub_id = (int) ( $subscription->id ?? 0 );
		if ( 0 === $sub_id ) {
			return;
		}

		$this->push_entity( 'subscription', 'sync_subscriptions', $sub_id );
	}
}
