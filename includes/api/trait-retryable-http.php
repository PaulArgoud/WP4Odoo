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
 * Does NOT retry internally — throws immediately on WP_Error or
 * HTTP 5xx. Retry is handled by the Sync_Engine at queue level
 * via exponential backoff (scheduled_at). This avoids blocking
 * the queue processor with in-process usleep().
 *
 * When the cURL extension is available, a persistent handle pool
 * reuses TCP+TLS connections across calls within the same PHP
 * request, saving ~50 ms per additional call to the same host.
 * Falls back to wp_remote_post() transparently on pool failure
 * or when cURL is unavailable.
 *
 * @package WP4Odoo
 * @since   1.9.2
 */
trait Retryable_Http {

	/**
	 * Persistent cURL handles keyed by host.
	 *
	 * Reusing the same handle for a given host avoids the
	 * TCP three-way handshake and TLS negotiation on every call.
	 * Handles are automatically closed by PHP on process exit.
	 *
	 * @since 3.8.0
	 * @var array<string, \CurlHandle>
	 */
	private static array $curl_pool = [];

	/**
	 * Send an HTTP POST request.
	 *
	 * Tries the persistent cURL pool first for connection reuse,
	 * then falls back to wp_remote_post() on failure.
	 *
	 * @param string               $url           Full URL.
	 * @param array<string, mixed> $request_args  Arguments for wp_remote_post().
	 * @param string               $endpoint      Endpoint label for log context.
	 * @return array The successful response (WP HTTP API format).
	 * @throws \RuntimeException On HTTP error or server error (5xx).
	 */
	protected function http_post_with_retry( string $url, array $request_args, string $endpoint ): array {
		// Enable TCP connection reuse across batch calls.
		if ( ! isset( $request_args['headers']['Connection'] ) ) {
			$request_args['headers']['Connection'] = 'keep-alive';
		}

		$response = $this->send_http_post( $url, $request_args );

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

		if ( 429 === $status_code || $status_code >= 500 ) {
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

			throw new \RuntimeException( $error_msg, $status_code );
		}

		return $response;
	}

	/**
	 * Send an HTTP POST, preferring the persistent cURL pool.
	 *
	 * Falls back to wp_remote_post() when the pool is unavailable
	 * or the cURL call fails (connection reset, timeout, etc.).
	 *
	 * @since 3.8.0
	 *
	 * @param string               $url          Full URL.
	 * @param array<string, mixed> $request_args wp_remote_post() compatible args.
	 * @return array|\WP_Error Response array or WP_Error on failure.
	 */
	private function send_http_post( string $url, array $request_args ) {
		/**
		 * Filter whether the persistent cURL connection pool is enabled.
		 *
		 * Disable this if your environment requires all HTTP traffic to go
		 * through the WordPress HTTP API (e.g. custom proxy configuration).
		 *
		 * @since 3.8.0
		 *
		 * @param bool $enabled Whether the pool is enabled (default true).
		 */
		$pool_enabled = function_exists( 'curl_init' )
			&& (bool) apply_filters( 'wp4odoo_enable_connection_pool', true );

		if ( $pool_enabled ) {
			$pool_result = $this->http_post_via_pool( $url, $request_args );
			if ( null !== $pool_result ) {
				return $pool_result;
			}
		}

		return wp_remote_post( $url, $request_args );
	}

	/**
	 * Execute an HTTP POST using a persistent cURL handle.
	 *
	 * Returns null when the pool cannot serve the request, signaling
	 * the caller to fall back to wp_remote_post().
	 *
	 * @since 3.8.0
	 *
	 * @param string               $url          Full URL.
	 * @param array<string, mixed> $request_args wp_remote_post() compatible args.
	 * @return array|null WP HTTP API response array, or null on pool failure.
	 */
	// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt_array, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_close, WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- Intentional: persistent cURL pool for TCP+TLS reuse; wp_remote_post() cannot reuse connections.
	private function http_post_via_pool( string $url, array $request_args ): ?array {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return null;
		}

		// Create or reuse handle for this host.
		if ( ! isset( self::$curl_pool[ $host ] ) ) {
			$ch = curl_init();
			if ( false === $ch ) {
				return null; // @codeCoverageIgnore
			}

			curl_setopt_array(
				$ch,
				[
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HEADER         => true,
					CURLOPT_FORBID_REUSE   => false,
					CURLOPT_FRESH_CONNECT  => false,
					CURLOPT_TCP_KEEPALIVE  => 1,
					CURLOPT_TCP_KEEPIDLE   => 30,
					CURLOPT_ENCODING       => '',
					CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
				]
			);

			// Use WordPress CA bundle when available.
			if ( defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
				$ca_bundle = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
				if ( file_exists( $ca_bundle ) ) {
					curl_setopt( $ch, CURLOPT_CAINFO, $ca_bundle );
				}
			}

			self::$curl_pool[ $host ] = $ch;
		}

		$ch = self::$curl_pool[ $host ];

		// Per-request options.
		$ssl_verify = $request_args['sslverify'] ?? true;

		curl_setopt_array(
			$ch,
			[
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $request_args['body'] ?? '',
				CURLOPT_TIMEOUT        => (int) ( $request_args['timeout'] ?? 30 ),
				CURLOPT_HTTPHEADER     => self::format_curl_headers( $request_args['headers'] ?? [] ),
				CURLOPT_SSL_VERIFYPEER => (bool) $ssl_verify,
				CURLOPT_SSL_VERIFYHOST => $ssl_verify ? 2 : 0,
			]
		);

		$raw_response = curl_exec( $ch );

		if ( false === $raw_response || ! is_string( $raw_response ) ) {
			// Connection failed — drop the stale handle and fall back.
			curl_close( $ch );
			unset( self::$curl_pool[ $host ] );
			return null;
		}

		$header_size = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$status_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		return [
			'headers'  => [],
			'body'     => substr( $raw_response, $header_size ),
			'response' => [
				'code'    => $status_code,
				'message' => '',
			],
			'cookies'  => [],
		];
	}
	// phpcs:enable

	/**
	 * Format headers as cURL expects: ['Key: Value', ...].
	 *
	 * @since 3.8.0
	 *
	 * @param array<string, string> $headers Associative header array.
	 * @return string[] Formatted header lines.
	 */
	private static function format_curl_headers( array $headers ): array {
		$formatted = [];
		foreach ( $headers as $key => $value ) {
			$formatted[] = "{$key}: {$value}";
		}
		return $formatted;
	}

	/**
	 * Reset the pool state (for testing).
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public static function reset_pool(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- Pool cleanup.
		array_map( 'curl_close', self::$curl_pool );
		self::$curl_pool = [];
	}
}
