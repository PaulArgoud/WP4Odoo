<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sprout Invoices Handler — data access for invoices and payments.
 *
 * Loads SI invoice/payment data and formats it for Odoo account.move / account.payment.
 * Invoice line items are converted to One2many tuples [(0, 0, {...})].
 *
 * Called by Sprout_Invoices_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class Sprout_Invoices_Handler {

	/**
	 * Invoice status mapping: SI post_status → Odoo account.move state.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'temp'      => 'draft',
		'publish'   => 'draft',
		'partial'   => 'draft',
		'complete'  => 'posted',
		'write-off' => 'cancel',
	];

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	// ─── Load invoice ─────────────────────────────────────

	/**
	 * Load a Sprout Invoices invoice as Odoo account.move data.
	 *
	 * Returns pre-formatted data with invoice_line_ids as One2many tuples.
	 *
	 * @param int $post_id    SI invoice post ID.
	 * @param int $partner_id Resolved Odoo partner ID.
	 * @return array<string, mixed> Invoice data, or empty if not found.
	 */
	public function load_invoice( int $post_id, int $partner_id ): array {
		$invoice = \SI_Invoice::get_instance( $post_id );
		if ( ! $invoice->id ) {
			$this->logger->warning( 'Sprout Invoices invoice not found.', [ 'post_id' => $post_id ] );
			return [];
		}

		$post       = get_post( $post_id );
		$total      = (float) get_post_meta( $post_id, '_total', true );
		$line_items = get_post_meta( $post_id, '_doc_line_items', true );
		$due_date   = (string) get_post_meta( $post_id, '_due_date', true );
		$issue_date = (string) get_post_meta( $post_id, '_invoice_issue_date', true );
		$invoice_id = (string) get_post_meta( $post_id, '_invoice_id', true );

		$lines = $this->build_invoice_lines( is_array( $line_items ) ? $line_items : [], $post->post_title ?? '', $total );

		return [
			'move_type'        => 'out_invoice',
			'partner_id'       => $partner_id,
			'invoice_date'     => $issue_date ?: gmdate( 'Y-m-d' ),
			'invoice_date_due' => $due_date,
			'ref'              => $invoice_id ?: (string) $post_id,
			'invoice_line_ids' => $lines,
		];
	}

	// ─── Load payment ─────────────────────────────────────

	/**
	 * Load a Sprout Invoices payment as Odoo account.payment data.
	 *
	 * @param int $post_id    SI payment post ID.
	 * @param int $partner_id Resolved Odoo partner ID.
	 * @return array<string, mixed> Payment data, or empty if not found.
	 */
	public function load_payment( int $post_id, int $partner_id ): array {
		$payment = \SI_Payment::get_instance( $post_id );
		if ( ! $payment->id ) {
			$this->logger->warning( 'Sprout Invoices payment not found.', [ 'post_id' => $post_id ] );
			return [];
		}

		$amount = (float) get_post_meta( $post_id, '_payment_total', true );
		$method = (string) get_post_meta( $post_id, '_payment_method', true );
		$date   = (string) get_post_meta( $post_id, '_payment_date', true );

		return [
			'partner_id'   => $partner_id,
			'amount'       => $amount,
			'date'         => $date ?: gmdate( 'Y-m-d' ),
			'payment_type' => 'inbound',
			'ref'          => $method ?: __( 'Payment', 'wp4odoo' ),
		];
	}

	// ─── Helpers ──────────────────────────────────────────

	/**
	 * Get the client post ID for an invoice.
	 *
	 * @param int $invoice_id Invoice post ID.
	 * @return int Client post ID, or 0.
	 */
	public function get_client_id( int $invoice_id ): int {
		return (int) get_post_meta( $invoice_id, '_client_id', true );
	}

	/**
	 * Get the related invoice post ID for a payment.
	 *
	 * @param int $payment_id Payment post ID.
	 * @return int Invoice post ID, or 0.
	 */
	public function get_invoice_id_for_payment( int $payment_id ): int {
		return (int) get_post_meta( $payment_id, '_invoice_id', true );
	}

	/**
	 * Map an SI invoice post status to an Odoo account.move state.
	 *
	 * @param string $post_status SI post status.
	 * @return string Odoo account.move state.
	 */
	public function map_status( string $post_status ): string {
		/**
		 * Filters the Sprout Invoices → Odoo invoice status map.
		 *
		 * @param array<string, string> $map Status mapping.
		 */
		$map = apply_filters( 'wp4odoo_si_invoice_status_map', self::STATUS_MAP );

		return $map[ $post_status ] ?? 'draft';
	}

	// ─── Private ──────────────────────────────────────────

	/**
	 * Build Odoo invoice_line_ids from SI line items.
	 *
	 * SI stores line items as an array of arrays with 'desc', 'rate', 'qty' keys.
	 * Converts to Odoo One2many create tuples: [(0, 0, {values})].
	 *
	 * Falls back to a single line with the invoice total if no items exist.
	 *
	 * @param array<int, array<string, mixed>> $line_items SI line items.
	 * @param string                           $title      Invoice title (fallback line name).
	 * @param float                            $total      Invoice total (fallback amount).
	 * @return array<int, array<int, mixed>>
	 */
	private function build_invoice_lines( array $line_items, string $title, float $total ): array {
		$lines = [];

		foreach ( $line_items as $item ) {
			if ( empty( $item['desc'] ) ) {
				continue;
			}

			$lines[] = [
				0,
				0,
				[
					'name'       => $item['desc'],
					'quantity'   => (float) ( $item['qty'] ?? 1 ),
					'price_unit' => (float) ( $item['rate'] ?? 0 ),
				],
			];
		}

		if ( empty( $lines ) && $total > 0 ) {
			$lines[] = [
				0,
				0,
				[
					'name'       => $title ?: __( 'Invoice', 'wp4odoo' ),
					'quantity'   => 1,
					'price_unit' => $total,
				],
			];
		}

		return $lines;
	}
}
