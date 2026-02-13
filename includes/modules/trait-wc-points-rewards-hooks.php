<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Points & Rewards hook callbacks for push operations.
 *
 * Extracted from WC_Points_Rewards_Module for single responsibility.
 * Handles all point balance changes (increase, decrease, set).
 *
 * All three WC hooks delegate to on_points_change() since any
 * balance modification triggers the same sync: push current total
 * balance to the Odoo loyalty.card.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
trait WC_Points_Rewards_Hooks {

	/**
	 * Handle any point balance change.
	 *
	 * Called by all three WC Points hooks (increase, decrease, set).
	 * Enqueues a balance sync job to push the current total to Odoo.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public function on_points_change( int $user_id ): void {
		if ( $this->is_importing() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_balances'] ) ) {
			return;
		}

		if ( $user_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'balance', $user_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'wc_points_rewards', 'balance', $action, $user_id, $odoo_id );
	}
}
