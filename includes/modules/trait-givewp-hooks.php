<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GiveWP hook callbacks for push operations.
 *
 * Extracted from GiveWP_Module for single responsibility.
 * Handles donation form saves and donation status changes.
 *
 * Recurring donations are handled transparently: each recurring
 * payment fires the same give_update_payment_status hook, so every
 * instalment is pushed to Odoo automatically.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
trait GiveWP_Hooks {

	/**
	 * Handle GiveWP donation form save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_form_save( int $post_id ): void {
		$this->handle_cpt_save( $post_id, 'give_forms', 'sync_forms', 'form' );
	}

	/**
	 * Handle GiveWP donation status change.
	 *
	 * Only pushes completed (publish) or refunded donations.
	 * Covers both one-time and recurring donations — each GiveWP
	 * recurring payment fires this same hook.
	 *
	 * @param int    $payment_id The payment (donation) ID.
	 * @param string $new_status New payment status.
	 * @param string $old_status Old payment status.
	 * @return void
	 */
	public function on_donation_status_change( int $payment_id, string $new_status, string $old_status ): void {
		if ( ! $this->should_sync( 'sync_donations' ) ) {
			return;
		}

		// Only sync completed or refunded donations.
		if ( ! in_array( $new_status, [ 'publish', 'refunded' ], true ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'donation', $payment_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'givewp', 'donation', $action, $payment_id, $odoo_id );
	}
}
