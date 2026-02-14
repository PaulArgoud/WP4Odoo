<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RCP hook callbacks for push operations.
 *
 * Extracted from RCP_Module for single responsibility.
 * Handles level edits, payment creation, and membership status changes.
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
trait RCP_Hooks {

	/**
	 * Handle RCP membership level save.
	 *
	 * @param int $level_id Level ID.
	 * @return void
	 */
	public function on_level_saved( int $level_id ): void {
		if ( ! $this->should_sync( 'sync_levels' ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'level', $level_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'rcp', 'level', $action, $level_id, $odoo_id );
	}

	/**
	 * Handle RCP payment creation.
	 *
	 * Only syncs completed payments.
	 *
	 * @param int                  $payment_id Payment ID.
	 * @param array<string, mixed> $args       Payment data.
	 * @return void
	 */
	public function on_payment_created( int $payment_id, array $args = [] ): void {
		if ( ! $this->should_sync( 'sync_payments' ) ) {
			return;
		}

		// Only sync completed payments.
		$status = $args['status'] ?? '';
		if ( 'complete' !== $status ) {
			return;
		}

		Queue_Manager::push( 'rcp', 'payment', 'create', $payment_id );
	}

	/**
	 * Handle RCP membership activation.
	 *
	 * @param \RCP_Membership $membership RCP membership object.
	 * @return void
	 */
	public function on_membership_activated( \RCP_Membership $membership ): void {
		if ( ! $this->should_sync( 'sync_memberships' ) ) {
			return;
		}

		$membership_id = $membership->get_id();
		$odoo_id       = $this->get_mapping( 'membership', $membership_id ) ?? 0;
		$action        = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'rcp', 'membership', $action, $membership_id, $odoo_id );
	}

	/**
	 * Handle RCP membership status transition.
	 *
	 * Captures all status changes (cancel, expire, etc.).
	 *
	 * @param string $old_status    Old membership status.
	 * @param string $new_status    New membership status.
	 * @param int    $membership_id RCP membership ID.
	 * @return void
	 */
	public function on_membership_status_change( string $old_status, string $new_status, int $membership_id ): void {
		if ( ! $this->should_sync( 'sync_memberships' ) ) {
			return;
		}

		// Skip if transitioning to active — handled by on_membership_activated.
		if ( 'active' === $new_status ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'membership', $membership_id ) ?? 0;
		if ( ! $odoo_id ) {
			// Membership not yet synced — nothing to update.
			return;
		}

		Queue_Manager::push( 'rcp', 'membership', 'update', $membership_id, $odoo_id );
	}
}
