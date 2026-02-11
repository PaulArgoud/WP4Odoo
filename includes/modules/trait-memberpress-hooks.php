<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MemberPress hook callbacks for push operations.
 *
 * Extracted from MemberPress_Module for single responsibility.
 * Handles plan saves, transaction stores, and subscription status changes.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   1.9.9
 */
trait MemberPress_Hooks {

	/**
	 * Handle MemberPress plan (product) save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_plan_save( int $post_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'memberpressproduct' !== get_post_type( $post_id ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_plans'] ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'plan', $post_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'memberpress', 'plan', $action, $post_id, $odoo_id );
	}

	/**
	 * Handle MemberPress transaction store (create or update).
	 *
	 * Only pushes completed or refunded transactions.
	 *
	 * @param \MeprTransaction $txn MemberPress transaction object.
	 * @return void
	 */
	public function on_transaction_store( $txn ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_transactions'] ) ) {
			return;
		}

		// Only sync completed or refunded transactions.
		if ( ! in_array( $txn->status, [ 'complete', 'refunded' ], true ) ) {
			return;
		}

		$txn_id  = (int) $txn->id;
		$odoo_id = $this->get_mapping( 'transaction', $txn_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'memberpress', 'transaction', $action, $txn_id, $odoo_id );
	}

	/**
	 * Handle MemberPress subscription status change.
	 *
	 * @param string             $old_status Old subscription status.
	 * @param string             $new_status New subscription status.
	 * @param \MeprSubscription $sub        MemberPress subscription object.
	 * @return void
	 */
	public function on_subscription_status_change( string $old_status, string $new_status, $sub ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_subscriptions'] ) ) {
			return;
		}

		$sub_id  = (int) $sub->id;
		$odoo_id = $this->get_mapping( 'subscription', $sub_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'memberpress', 'subscription', $action, $sub_id, $odoo_id );
	}
}
