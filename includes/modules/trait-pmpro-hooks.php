<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PMPro hook callbacks for push operations.
 *
 * Extracted from PMPro_Module for single responsibility.
 * Handles level saves, order creation/update, and membership changes.
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
trait PMPro_Hooks {

	/**
	 * Handle PMPro level saved in admin.
	 *
	 * @param int $level_id The membership level ID.
	 * @return void
	 */
	public function on_level_saved( int $level_id ): void {
		if ( ! $this->should_sync( 'sync_levels' ) ) {
			return;
		}

		if ( $level_id <= 0 ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'level', $level_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'pmpro', 'level', $action, $level_id, $odoo_id );
	}

	/**
	 * Handle PMPro order created.
	 *
	 * Only pushes successful or refunded orders.
	 *
	 * @param \MemberOrder $morder PMPro order object.
	 * @return void
	 */
	public function on_order_created( $morder ): void {
		$this->process_order( $morder );
	}

	/**
	 * Handle PMPro order updated (status change).
	 *
	 * @param \MemberOrder $morder PMPro order object.
	 * @return void
	 */
	public function on_order_updated( $morder ): void {
		$this->process_order( $morder );
	}

	/**
	 * Process a PMPro order for sync.
	 *
	 * Shared logic for on_order_created and on_order_updated.
	 *
	 * @param \MemberOrder $morder PMPro order object.
	 * @return void
	 */
	private function process_order( $morder ): void {
		if ( ! $this->should_sync( 'sync_orders' ) ) {
			return;
		}

		// Only sync successful or refunded orders.
		if ( ! in_array( $morder->status, [ 'success', 'refunded' ], true ) ) {
			return;
		}

		$order_id = (int) $morder->id;
		$odoo_id  = $this->get_mapping( 'order', $order_id ) ?? 0;
		$action   = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'pmpro', 'order', $action, $order_id, $odoo_id );
	}

	/**
	 * Handle PMPro membership level change for a user.
	 *
	 * Queries pmpro_memberships_users for the row ID since the hook
	 * only provides level_id and user_id.
	 *
	 * @param int $level_id     New membership level ID (0 if removed).
	 * @param int $user_id      WordPress user ID.
	 * @param int $cancel_level Previous level ID (0 if none).
	 * @return void
	 */
	public function on_membership_changed( int $level_id, int $user_id, int $cancel_level = 0 ): void {
		if ( ! $this->should_sync( 'sync_memberships' ) ) {
			return;
		}

		// Determine which level to look up.
		$target_level = $level_id > 0 ? $level_id : $cancel_level;
		if ( $target_level <= 0 ) {
			return;
		}

		// Find the latest membership row for this user/level.
		global $wpdb;
		$table  = $wpdb->prefix . 'pmpro_memberships_users';
		$row_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND membership_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$user_id,
				$target_level
			)
		);

		if ( $row_id <= 0 ) {
			$this->logger->debug(
				'No pmpro_memberships_users row found for membership change.',
				[
					'user_id'  => $user_id,
					'level_id' => $target_level,
				]
			);
			return;
		}

		$odoo_id = $this->get_mapping( 'membership', $row_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'pmpro', 'membership', $action, $row_id, $odoo_id );
	}
}
