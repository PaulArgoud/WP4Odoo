<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprout Invoices hook callbacks for push operations.
 *
 * Extracted from Sprout_Invoices_Module for single responsibility.
 * Handles invoice save and payment creation events.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
trait Sprout_Invoices_Hooks {

	/**
	 * Handle Sprout Invoices invoice save.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	public function on_invoice_save( int $post_id ): void {
		$this->handle_cpt_save( $post_id, 'sa_invoice', 'sync_invoices', 'invoice' );
	}

	/**
	 * Handle Sprout Invoices payment creation.
	 *
	 * Triggered by the si_new_payment action.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return void
	 */
	public function on_payment( int $payment_id ): void {
		if ( 'sa_payment' !== get_post_type( $payment_id ) ) {
			return;
		}

		$this->push_entity( 'sprout_invoices', 'payment', 'sync_payments', $payment_id );
	}
}
