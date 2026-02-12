<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP POST helper for Odoo transports.
 *
 * Shared by Odoo_JsonRPC and Odoo_XmlRPC transports.
 * Requires the using class to have a `Logger $logger` property.
 *
 * Fails immediately on transient errors (WP_Error, HTTP 5xx) and lets
 * the Sync_Engine handle retry via queue-level exponential backoff.
 * This avoids blocking the queue processor with in-process usleep().
 *
 * @package WP4Odoo
 * @since   1.9.2
 */
trait Retryable_Http {

	/**
	 * Send an HTTP POST request.
	 *
	 * @param string               $url           Full URL.
	 * @param array<string, mixed> $request_args  Arguments for wp_remote_post().
	 * @param string               $endpoint      Endpoint label for log context.
	 * @return array The successful wp_remote_post() response.
	 * @throws \RuntimeException On HTTP error or server error (5xx).
	 */
	protected function http_post_with_retry( string $url, array $request_args, string $endpoint ): array {
		// Enable TCP connection reuse across batch calls.
		if ( ! isset( $request_args['headers']['Connection'] ) ) {
			$request_args['headers']['Connection'] = 'keep-alive';
		}

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			$error_msg = sprintf(
				/* translators: %s: error message from HTTP request */
				__( 'HTTP error: %s', 'wp4odoo' ),
				$response->get_error_message()
			);
			$this->logger->error(
				$error_msg,
				[
					'endpoint' => $endpoint,
				]
			);

			throw new \RuntimeException( $error_msg );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 500 ) {
			$error_msg = sprintf(
				/* translators: 1: HTTP status code, 2: endpoint */
				__( 'Server error HTTP %1$d on %2$s.', 'wp4odoo' ),
				$status_code,
				$endpoint
			);
			$this->logger->error(
				$error_msg,
				[
					'endpoint' => $endpoint,
					'status'   => $status_code,
				]
			);

			throw new \RuntimeException( $error_msg );
		}

		return $response;
	}
}
