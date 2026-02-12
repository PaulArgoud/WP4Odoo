<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Queue_Manager;

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
		if ( $this->is_importing() ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( 'sa_invoice' !== get_post_type( $post_id ) ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_invoices'] ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'invoice', $post_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'sprout_invoices', 'invoice', $action, $post_id, $odoo_id );
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
		if ( $this->is_importing() ) {
			return;
		}

		$settings = $this->get_settings();
		if ( empty( $settings['sync_payments'] ) ) {
			return;
		}

		if ( 'sa_payment' !== get_post_type( $payment_id ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( 'payment', $payment_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( 'sprout_invoices', 'payment', $action, $payment_id, $odoo_id );
	}
}
