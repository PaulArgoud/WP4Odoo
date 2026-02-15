<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for membership plugin handlers.
 *
 * Provides the shared Logger dependency used by MemberPress_Handler,
 * PMPro_Handler, and RCP_Handler.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
abstract class Membership_Handler_Base {

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
