<?php
/**
 * WP-Invoice stubs for unit tests.
 *
 * Provides a minimal WPI_Invoice class that loads invoice data
 * from $GLOBALS['_wpi_invoices'] for test isolation.
 *
 * @package WP4Odoo\Tests
 */

/**
 * Stub for WP-Invoice invoice object.
 *
 * Real WPI_Invoice stores data in $this->data after load_invoice().
 */
class WPI_Invoice {

	/**
	 * Invoice data populated by load_invoice().
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * Load invoice data by ID.
	 *
	 * In the real plugin: $inv->load_invoice("id=$id").
	 * Stub reads from $GLOBALS['_wpi_invoices'][$id].
	 *
	 * @param string $args Query string (e.g. "id=123").
	 * @return void
	 */
	public function load_invoice( string $args ): void {
		parse_str( $args, $parsed );
		$id = (int) ( $parsed['id'] ?? 0 );

		if ( isset( $GLOBALS['_wpi_invoices'][ $id ] ) ) {
			$this->data = $GLOBALS['_wpi_invoices'][ $id ];
		}
	}
}
