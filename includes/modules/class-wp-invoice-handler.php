<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Invoice Handler — data access for WP-Invoice invoices.
 *
 * Loads WPI_Invoice data and formats it for Odoo account.move.
 * Itemized list entries are converted to One2many tuples [(0, 0, {...})].
 *
 * Called by WP_Invoice_Module via its load_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   2.7.5
 */
class WP_Invoice_Handler {

	/**
	 * Invoice status mapping: WP-Invoice status → Odoo account.move state.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_MAP = [
		'active'  => 'draft',
		'paid'    => 'posted',
		'pending' => 'draft',
		'draft'   => 'draft',
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
	 * Load a WP-Invoice invoice as Odoo account.move data.
	 *
	 * Uses WPI_Invoice::load_invoice() to populate the data array,
	 * then builds invoice_line_ids from the itemized_list.
	 *
	 * @param int $post_id    WP-Invoice post ID.
	 * @param int $partner_id Resolved Odoo partner ID.
	 * @return array<string, mixed> Invoice data, or empty if not found.
	 */
	public function load_invoice( int $post_id, int $partner_id ): array {
		$invoice = new \WPI_Invoice();
		$invoice->load_invoice( 'id=' . $post_id );

		if ( empty( $invoice->data ) ) {
			$this->logger->warning( 'WP-Invoice invoice not found.', [ 'post_id' => $post_id ] );
			return [];
		}

		$data       = $invoice->data;
		$total      = (float) ( $data['total'] ?? 0 );
		$tax        = (float) ( $data['tax'] ?? 0 );
		$items      = (array) ( $data['itemized_list'] ?? [] );
		$invoice_id = (string) ( $data['invoice_id'] ?? $post_id );
		$due_date   = (string) ( $data['due_date'] ?? '' );
		$post_date  = (string) ( $data['post_date'] ?? '' );

		$lines = $this->build_invoice_lines( $items, $invoice_id, $total );

		$issue_date = '';
		if ( $post_date ) {
			$issue_date = substr( $post_date, 0, 10 );
		}

		return [
			'move_type'        => 'out_invoice',
			'partner_id'       => $partner_id,
			'invoice_date'     => $issue_date ?: gmdate( 'Y-m-d' ),
			'invoice_date_due' => $due_date ? substr( $due_date, 0, 10 ) : '',
			'ref'              => $invoice_id,
			'invoice_line_ids' => $lines,
		];
	}

	// ─── Helpers ──────────────────────────────────────────

	/**
	 * Get the user data from a WP-Invoice invoice.
	 *
	 * @param int $post_id Invoice post ID.
	 * @return array{user_id: int, email: string, name: string} User data.
	 */
	public function get_user_data( int $post_id ): array {
		$invoice = new \WPI_Invoice();
		$invoice->load_invoice( 'id=' . $post_id );

		$user_data = $invoice->data['user_data'] ?? [];

		return [
			'user_id' => (int) ( $user_data['ID'] ?? 0 ),
			'email'   => (string) ( $user_data['user_email'] ?? '' ),
			'name'    => (string) ( $user_data['display_name'] ?? '' ),
		];
	}

	/**
	 * Map a WP-Invoice status to an Odoo account.move state.
	 *
	 * @param string $status WP-Invoice status.
	 * @return string Odoo account.move state.
	 */
	public function map_status( string $status ): string {
		return Status_Mapper::resolve( $status, self::STATUS_MAP, 'wp4odoo_wpi_invoice_status_map', 'draft' );
	}

	// ─── Private ──────────────────────────────────────────

	/**
	 * Build Odoo invoice_line_ids from WP-Invoice itemized list.
	 *
	 * Normalizes WP-Invoice keys (price → price_unit) and delegates
	 * to the shared Odoo_Accounting_Formatter::build_invoice_lines().
	 *
	 * @param array<int, array<string, mixed>> $items      WP-Invoice itemized list.
	 * @param string                           $invoice_id Invoice reference (fallback name).
	 * @param float                            $total      Invoice total (fallback amount).
	 * @return array<int, array<int, mixed>>
	 */
	private function build_invoice_lines( array $items, string $invoice_id, float $total ): array {
		$normalized = array_map(
			static fn( array $item ): array => [
				'name'       => (string) ( $item['name'] ?? '' ),
				'quantity'   => (float) ( $item['quantity'] ?? 1 ),
				'price_unit' => (float) ( $item['price'] ?? 0 ),
			],
			$items
		);

		/* translators: %s: invoice ID/reference. */
		return Odoo_Accounting_Formatter::build_invoice_lines( $normalized, sprintf( __( 'Invoice %s', 'wp4odoo' ), $invoice_id ), $total );
	}
}
