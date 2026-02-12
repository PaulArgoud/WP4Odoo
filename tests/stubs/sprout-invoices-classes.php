<?php
/**
 * Sprout Invoices stubs for unit tests.
 *
 * Provides minimal class definitions so the handler can call
 * SI_Invoice::get_instance() and SI_Payment::get_instance().
 *
 * @package WP4Odoo\Tests
 */

/**
 * Stub base class for SI custom post types.
 */
class SI_Post_Type {

	/**
	 * Post ID (0 means not found).
	 *
	 * @var int
	 */
	public int $id = 0;
}

/**
 * Stub for Sprout Invoices invoice (CPT sa_invoice).
 */
class SI_Invoice extends SI_Post_Type {

	/**
	 * Get an invoice instance by post ID.
	 *
	 * @param int $id Post ID.
	 * @return self
	 */
	public static function get_instance( int $id ): self {
		$instance = new self();
		$post     = get_post( $id );
		if ( $post && 'sa_invoice' === $post->post_type ) {
			$instance->id = $id;
		}
		return $instance;
	}
}

/**
 * Stub for Sprout Invoices payment (CPT sa_payment).
 */
class SI_Payment extends SI_Post_Type {

	/**
	 * Get a payment instance by post ID.
	 *
	 * @param int $id Post ID.
	 * @return self
	 */
	public static function get_instance( int $id ): self {
		$instance = new self();
		$post     = get_post( $id );
		if ( $post && 'sa_payment' === $post->post_type ) {
			$instance->id = $id;
		}
		return $instance;
	}
}
