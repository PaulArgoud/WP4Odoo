<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error classification for sync failures.
 *
 * Used by Sync_Result and Sync_Engine to determine retry strategy:
 * - Transient: retry with exponential backoff (network, timeout, 5xx).
 * - Permanent: fail immediately, do not retry (bad data, missing model, auth failure).
 *
 * @package WP4Odoo
 * @since   2.3.0
 */
enum Error_Type: string {

	/**
	 * Transient error — retry with backoff.
	 *
	 * Network timeout, Odoo 5xx, rate limiting, temporary unavailability.
	 */
	case Transient = 'transient';

	/**
	 * Permanent error — do not retry.
	 *
	 * Invalid data, missing Odoo model, entity not found, mapping failure,
	 * authentication failure, missing API credentials.
	 */
	case Permanent = 'permanent';
}
