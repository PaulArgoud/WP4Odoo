<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error classification for Odoo runtime exceptions.
 *
 * Determines whether a RuntimeException represents a transient
 * (network/server) or permanent (validation/access) error to
 * control retry behavior in the sync engine.
 *
 * @package WP4Odoo
 * @since   3.2.5
 */
trait Error_Classification {

	/**
	 * Classify a runtime exception into an Error_Type.
	 *
	 * Connection errors (HTTP 5xx, timeout, network) are Transient.
	 * Business errors (AccessError, ValidationError, missing fields) are Permanent.
	 *
	 * @param \RuntimeException $e The exception to classify.
	 * @return Error_Type
	 */
	protected static function classify_exception( \RuntimeException $e ): Error_Type {
		$code    = $e->getCode();
		$message = strtolower( $e->getMessage() );

		// Odoo business errors are permanent (won't be fixed by retrying).
		// Check BEFORE status code since some Odoo versions wrap these in HTTP 500.
		if ( str_contains( $message, 'access denied' )
			|| str_contains( $message, 'accesserror' )
			|| str_contains( $message, 'validationerror' )
			|| str_contains( $message, 'userinputerror' )
			|| str_contains( $message, 'missing required' )
			|| str_contains( $message, 'constraint' ) ) {
			return Error_Type::Permanent;
		}

		// HTTP 429 (rate limit) and 503 (maintenance) are always transient.
		if ( 429 === $code || 503 === $code ) {
			return Error_Type::Transient;
		}

		// Other HTTP 5xx: transient (server overload / temporary unavailability).
		if ( $code >= 500 && $code < 600 ) {
			return Error_Type::Transient;
		}

		// Network / timeout errors from wp_remote_post.
		if ( str_contains( $message, 'http error' )
			|| str_contains( $message, 'timed out' )
			|| str_contains( $message, 'connection refused' )
			|| str_contains( $message, 'could not resolve' ) ) {
			return Error_Type::Transient;
		}

		// Default: treat unknown errors as transient for safety (allows retry).
		return Error_Type::Transient;
	}
}
