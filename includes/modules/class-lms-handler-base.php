<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for LMS plugin handlers.
 *
 * Extracts shared invoice and sale order formatting from
 * LearnDash_Handler and LifterLMS_Handler.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
abstract class LMS_Handler_Base {

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	protected Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Build an Odoo account.move (invoice) from LMS data.
	 *
	 * Shared logic for LearnDash transactions and LifterLMS orders:
	 * resolves the product name from a WP post, formats the invoice
	 * via Odoo_Accounting_Formatter, and optionally sets auto-validate.
	 *
	 * @param int    $product_post_id WP post ID for name resolution (course/product).
	 * @param int    $product_odoo_id Resolved Odoo product.product ID.
	 * @param int    $partner_id      Resolved Odoo partner ID.
	 * @param float  $amount          Transaction/order amount.
	 * @param string $date            Date string (truncated to Y-m-d).
	 * @param string $ref             Invoice reference.
	 * @param string $description     Fallback line description.
	 * @param bool   $auto_post       Whether to auto-post the invoice.
	 * @return array<string, mixed> Odoo account.move data.
	 */
	protected function build_invoice( int $product_post_id, int $product_odoo_id, int $partner_id, float $amount, string $date, string $ref, string $description, bool $auto_post ): array {
		$product_name = '';
		if ( $product_post_id > 0 ) {
			$product_post = get_post( $product_post_id );
			if ( $product_post ) {
				$product_name = $product_post->post_title;
			}
		}

		$invoice = Odoo_Accounting_Formatter::for_account_move(
			$partner_id,
			$product_odoo_id,
			$amount,
			$date,
			$ref,
			$product_name,
			$description
		);

		if ( $auto_post ) {
			$invoice['_auto_validate'] = true;
		}

		return $invoice;
	}

	/**
	 * Build an Odoo sale.order from LMS enrollment data.
	 *
	 * Shared logic for LearnDash and LifterLMS enrollments.
	 *
	 * @param int    $product_odoo_id Resolved Odoo product.product ID.
	 * @param int    $partner_id      Resolved Odoo partner ID.
	 * @param string $date            Enrollment date (Y-m-d).
	 * @param string $line_name       Order line name (course/enrollment name).
	 * @return array<string, mixed> Odoo sale.order data.
	 */
	protected function build_sale_order( int $product_odoo_id, int $partner_id, string $date, string $line_name ): array {
		return [
			'partner_id' => $partner_id,
			'date_order' => $date,
			'state'      => 'sale',
			'order_line' => [
				[
					0,
					0,
					[
						'product_id' => $product_odoo_id,
						'quantity'   => 1,
						'name'       => $line_name,
					],
				],
			],
		];
	}
}
