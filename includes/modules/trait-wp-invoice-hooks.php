<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Invoice hook callbacks for push operations.
 *
 * Extracted from WP_Invoice_Module for single responsibility.
 * Handles invoice create/update and payment events.
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
trait WP_Invoice_Hooks {

	/**
	 * Handle WP-Invoice invoice create or update.
	 *
	 * Triggered by wpi_object_created / wpi_object_updated.
	 *
	 * @param int $invoice_id Invoice post ID.
	 * @return void
	 */
	public function on_invoice_save( int $invoice_id ): void {
		if ( 'wpi_object' !== get_post_type( $invoice_id ) ) {
			return;
		}

		$this->push_entity( 'wp_invoice', 'invoice', 'sync_invoices', $invoice_id );
	}

	/**
	 * Handle WP-Invoice successful payment.
	 *
	 * Triggered by wpi_successful_payment. Re-syncs the invoice
	 * so its status is updated in Odoo.
	 *
	 * @param int $invoice_id Invoice post ID.
	 * @return void
	 */
	public function on_payment( int $invoice_id ): void {
		if ( 'wpi_object' !== get_post_type( $invoice_id ) ) {
			return;
		}

		$this->push_entity( 'wp_invoice', 'invoice', 'sync_invoices', $invoice_id );
	}
}
