<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Subscriptions hook callbacks for push operations.
 *
 * Extracted from WC_Subscriptions_Module for single responsibility.
 * Handles subscription product saves, subscription status changes,
 * and renewal payment completions.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.6.5
 */
trait WC_Subscriptions_Hooks {

	/**
	 * Handle WC subscription product save.
	 *
	 * Only processes subscription and variable-subscription product types.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_product_save( int $post_id ): void {
		if ( ! $this->should_sync( 'sync_products' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'product' !== \get_post_type( $post_id ) ) {
			return;
		}

		$product = \wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		if ( ! in_array( $product->get_type(), [ 'subscription', 'variable-subscription' ], true ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'product', $post_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'wc_subscriptions', 'product', $action, $post_id, $odoo_id );
	}

	/**
	 * Handle WC subscription status change.
	 *
	 * @param \WC_Subscription $subscription WC Subscription object.
	 * @param string           $new_status   New subscription status.
	 * @param string           $old_status   Old subscription status.
	 * @return void
	 */
	public function on_subscription_status_updated( $subscription, string $new_status, string $old_status ): void {
		if ( ! $this->should_sync( 'sync_subscriptions' ) ) {
			return;
		}

		$sub_id  = (int) $subscription->get_id();
		$odoo_id = $this->get_mapping( 'subscription', $sub_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'wc_subscriptions', 'subscription', $action, $sub_id, $odoo_id );
	}

	/**
	 * Handle WC subscription renewal payment completion.
	 *
	 * Enqueues the renewal order (not the subscription) for invoice creation.
	 *
	 * @param \WC_Subscription $subscription WC Subscription object.
	 * @param \WC_Order        $last_order   The renewal order.
	 * @return void
	 */
	public function on_renewal_payment_complete( $subscription, $last_order ): void {
		if ( ! $this->should_sync( 'sync_renewals' ) ) {
			return;
		}

		$order_id = (int) $last_order->get_id();

		Queue_Manager::push( 'wc_subscriptions', 'renewal', 'create', $order_id, 0 );
	}
}
