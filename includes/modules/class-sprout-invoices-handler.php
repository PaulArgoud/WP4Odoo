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
		return Status_Mapper::resolve( $post_status, self::STATUS_MAP, 'wp4odoo_si_invoice_status_map', 'draft' );
	}

	// ─── Parse invoice from Odoo ─────────────────────────

	/**
	 * Reverse status mapping: Odoo account.move state → SI post_status.
	 *
	 * @var array<string, string>
	 */
	private const REVERSE_STATUS_MAP = [
		'draft'  => 'publish',
		'posted' => 'complete',
		'cancel' => 'write-off',
	];

	/**
	 * Map an Odoo account.move state to an SI invoice post status.
	 *
	 * @param string $odoo_state Odoo account.move state.
	 * @return string SI post status.
	 */
	public function map_odoo_status_to_si( string $odoo_state ): string {
		return Status_Mapper::resolve( $odoo_state, self::REVERSE_STATUS_MAP, 'wp4odoo_si_reverse_invoice_status_map', 'publish' );
	}

	/**
	 * Parse Odoo account.move data into WordPress invoice format.
	 *
	 * Extracts invoice fields and converts invoice_line_ids One2many
	 * tuples back to SI line items format.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> WordPress invoice data.
	 */
	public function parse_invoice_from_odoo( array $odoo_data ): array {
		$line_items = [];
		$lines      = $odoo_data['invoice_line_ids'] ?? [];

		if ( is_array( $lines ) ) {
			foreach ( $lines as $line ) {
				// Odoo read returns line IDs as integers or tuples.
				if ( is_array( $line ) && isset( $line[2] ) && is_array( $line[2] ) ) {
					$vals = $line[2];
				} elseif ( is_array( $line ) && isset( $line['name'] ) ) {
					$vals = $line;
				} else {
					continue;
				}

				$line_items[] = [
					'desc' => $vals['name'] ?? '',
					'qty'  => (float) ( $vals['quantity'] ?? 1 ),
					'rate' => (float) ( $vals['price_unit'] ?? 0 ),
				];
			}
		}

		return [
			'title'      => $odoo_data['ref'] ?? '',
			'total'      => (float) ( $odoo_data['amount_total'] ?? 0 ),
			'issue_date' => $odoo_data['invoice_date'] ?? '',
			'due_date'   => $odoo_data['invoice_date_due'] ?? '',
			'ref'        => $odoo_data['ref'] ?? '',
			'status'     => $this->map_odoo_status_to_si( $odoo_data['state'] ?? 'draft' ),
			'line_items' => $line_items,
		];
	}

	/**
	 * Parse Odoo account.payment data into WordPress payment format.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, mixed> WordPress payment data.
	 */
	public function parse_payment_from_odoo( array $odoo_data ): array {
		return [
			'amount' => (float) ( $odoo_data['amount'] ?? 0 ),
			'date'   => $odoo_data['date'] ?? '',
			'method' => $odoo_data['ref'] ?? '',
		];
	}

	// ─── Save invoice ─────────────────────────────────────

	/**
	 * Save invoice data to an sa_invoice CPT post.
	 *
	 * Creates a new post when $wp_id is 0, updates an existing one otherwise.
	 * Sets SI meta fields (_total, _doc_line_items, _invoice_issue_date,
	 * _due_date, _invoice_id).
	 *
	 * @param array<string, mixed> $data  Parsed invoice data from parse_invoice_from_odoo().
	 * @param int                  $wp_id Existing post ID (0 to create new).
	 * @return int The post ID, or 0 on failure.
	 */
	public function save_invoice( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'  => $data['title'] ?? '',
			'post_type'   => 'sa_invoice',
			'post_status' => $data['status'] ?? 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = \wp_update_post( $post_args, true );
		} else {
			$result = \wp_insert_post( $post_args, true );
		}

		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save invoice post.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$post_id = $result;

		\update_post_meta( $post_id, '_total', $data['total'] ?? 0 );
		\update_post_meta( $post_id, '_invoice_issue_date', $data['issue_date'] ?? '' );
		\update_post_meta( $post_id, '_due_date', $data['due_date'] ?? '' );
		\update_post_meta( $post_id, '_invoice_id', $data['ref'] ?? '' );

		if ( ! empty( $data['line_items'] ) ) {
			\update_post_meta( $post_id, '_doc_line_items', $data['line_items'] );
		}

		return $post_id;
	}

	// ─── Save payment ─────────────────────────────────────

	/**
	 * Save payment data to an sa_payment CPT post.
	 *
	 * Creates a new post when $wp_id is 0, updates an existing one otherwise.
	 *
	 * @param array<string, mixed> $data  Parsed payment data from parse_payment_from_odoo().
	 * @param int                  $wp_id Existing post ID (0 to create new).
	 * @return int The post ID, or 0 on failure.
	 */
	public function save_payment( array $data, int $wp_id = 0 ): int {
		$post_args = [
			'post_title'  => $data['method'] ?? \__( 'Payment', 'wp4odoo' ),
			'post_type'   => 'sa_payment',
			'post_status' => 'publish',
		];

		if ( $wp_id > 0 ) {
			$post_args['ID'] = $wp_id;
			$result          = \wp_update_post( $post_args, true );
		} else {
			$result = \wp_insert_post( $post_args, true );
		}

		if ( \is_wp_error( $result ) ) {
			$this->logger->error( 'Failed to save payment post.', [ 'wp_id' => $wp_id ] );
			return 0;
		}

		$post_id = $result;

		\update_post_meta( $post_id, '_payment_total', $data['amount'] ?? 0 );
		\update_post_meta( $post_id, '_payment_method', $data['method'] ?? '' );
		\update_post_meta( $post_id, '_payment_date', $data['date'] ?? '' );

		return $post_id;
	}

	// ─── Private ──────────────────────────────────────────

	/**
	 * Build Odoo invoice_line_ids from SI line items.
	 *
	 * Normalizes SI keys (desc → name, qty → quantity, rate → price_unit)
	 * and delegates to the shared Odoo_Accounting_Formatter::build_invoice_lines().
	 *
	 * @param array<int, array<string, mixed>> $line_items SI line items.
	 * @param string                           $title      Invoice title (fallback line name).
	 * @param float                            $total      Invoice total (fallback amount).
	 * @return array<int, array<int, mixed>>
	 */
	private function build_invoice_lines( array $line_items, string $title, float $total ): array {
		$normalized = array_map(
			static fn( array $item ): array => [
				'name'       => (string) ( $item['desc'] ?? '' ),
				'quantity'   => (float) ( $item['qty'] ?? 1 ),
				'price_unit' => (float) ( $item['rate'] ?? 0 ),
			],
			$line_items
		);

		return Odoo_Accounting_Formatter::build_invoice_lines( $normalized, $title ?: __( 'Invoice', 'wp4odoo' ), $total );
	}
}
