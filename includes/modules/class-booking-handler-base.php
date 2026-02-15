<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for booking plugin handlers.
 *
 * Provides the shared Logger dependency used by Amelia_Handler,
 * Bookly_Handler, and WC_Bookings_Handler.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
abstract class Booking_Handler_Base {

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
}
