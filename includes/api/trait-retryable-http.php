<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retryable HTTP POST requests with exponential backoff and jitter.
 *
 * Shared by Odoo_JsonRPC and Odoo_XmlRPC transports.
 * Requires the using class to have a `Logger $logger` property.
 *
 * @package WP4Odoo
 * @since   1.9.2
 */
trait Retryable_Http {

	/**
	 * Maximum retry attempts for transient HTTP errors.
	 */
	private const MAX_RETRIES = 3;

	/**
	 * Send an HTTP POST with automatic retry on transient failures.
	 *
	 * Uses exponential backoff (2^attempt * 500ms) with random jitter (0-1000ms).
	 *
	 * @param string               $url           Full URL.
	 * @param array<string, mixed> $request_args  Arguments for wp_remote_post().
	 * @param string               $endpoint      Endpoint label for log context.
	 * @return array The successful wp_remote_post() response.
	 * @throws \RuntimeException After all retries exhausted.
	 */
	protected function http_post_with_retry( string $url, array $request_args, string $endpoint ): array {
		$response = null;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$response = wp_remote_post( $url, $request_args );

			if ( ! is_wp_error( $response ) ) {
				$status_code = wp_remote_retrieve_response_code( $response );
				if ( $status_code >= 500 && $attempt < self::MAX_RETRIES ) {
					$delay_us = (int) ( pow( 2, $attempt ) * 500 + wp_rand( 0, 1000 ) ) * 1000;
					usleep( $delay_us );

					$this->logger->warning(
						'Server error, retrying.',
						[
							'endpoint' => $endpoint,
							'status'   => $status_code,
							'attempt'  => $attempt,
						]
					);
					continue;
				}
				return $response;
			}

			if ( $attempt < self::MAX_RETRIES ) {
				$delay_us = (int) ( pow( 2, $attempt ) * 500 + wp_rand( 0, 1000 ) ) * 1000;
				usleep( $delay_us );

				$this->logger->warning(
					'HTTP request failed, retrying.',
					[
						'endpoint' => $endpoint,
						'attempt'  => $attempt,
						'error'    => $response->get_error_message(),
					]
				);
			}
		}

		$error_msg = sprintf(
			/* translators: %s: error message from HTTP request */
			__( 'HTTP error: %s', 'wp4odoo' ),
			$response->get_error_message()
		);
		$this->logger->error(
			$error_msg,
			[
				'endpoint' => $endpoint,
				'attempts' => self::MAX_RETRIES,
			]
		);

		throw new \RuntimeException( $error_msg );
	}
}
