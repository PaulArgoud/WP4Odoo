<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

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
		$this->handle_cpt_save( $post_id, 'memberpressproduct', 'sync_plans', 'plan' );
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
		// Only sync completed or refunded transactions.
		if ( ! in_array( $txn->status, [ 'complete', 'refunded' ], true ) ) {
			return;
		}

		$this->push_entity( 'memberpress', 'transaction', 'sync_transactions', (int) $txn->id );
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
		$this->push_entity( 'memberpress', 'subscription', 'sync_subscriptions', (int) $sub->id );
	}
}
