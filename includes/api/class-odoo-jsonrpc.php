<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-RPC 2.0 transport for Odoo 17+.
 *
 * All calls go through POST /jsonrpc with the JSON-RPC 2.0 envelope.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Odoo_JsonRPC extends Odoo_Transport_Base {

	/**
	 * Return the protocol name used for logging context.
	 *
	 * @return string
	 */
	protected function get_protocol_name(): string {
		return 'jsonrpc';
	}

	/**
	 * Authenticate against Odoo and retrieve the user ID.
	 *
	 * @param string $username The Odoo login.
	 * @return int The authenticated user ID (uid).
	 * @throws \RuntimeException On authentication failure.
	 */
	public function authenticate( string $username ): int {
		$result = $this->rpc_call(
			'/web/session/authenticate',
			[
				'db'       => $this->database,
				'login'    => $username,
				'password' => $this->api_key,
			]
		);

		if ( empty( $result['uid'] ) || false === $result['uid'] ) {

			throw new \RuntimeException(
				__( 'Authentication failed: invalid credentials.', 'wp4odoo' )
			);
		}

		$this->uid = (int) $result['uid'];

		if ( ! empty( $result['server_version'] ) ) {
			$this->server_version = (string) $result['server_version'];
		}

		$this->logger->debug(
			'Authenticated successfully.',
			[
				'uid'            => $this->uid,
				'url'            => $this->url,
				'server_version' => $this->server_version,
			]
		);

		return $this->uid;
	}

	/**
	 * Execute a method on an Odoo model via execute_kw.
	 *
	 * @param string               $model  Odoo model name (e.g., 'res.partner').
	 * @param string               $method Method name (e.g., 'search_read').
	 * @param array<int, mixed>    $args   Positional arguments.
	 * @param array<string, mixed> $kwargs Keyword arguments.
	 * @return mixed The Odoo response result.
	 * @throws \RuntimeException On RPC error or if not authenticated.
	 */
	public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
		$this->ensure_authenticated();

		$params = [
			'service' => 'object',
			'method'  => 'execute_kw',
			'args'    => [
				$this->database,
				$this->uid,
				$this->api_key,
				$model,
				$method,
				$args,
				(object) $kwargs,
			],
		];

		return $this->rpc_call( '/jsonrpc', $params );
	}

	/**
	 * Send a JSON-RPC request to Odoo.
	 *
	 * @param string               $endpoint The URL path (e.g., '/jsonrpc' or '/web/session/authenticate').
	 * @param array<string, mixed> $params   The params object for the JSON-RPC call.
	 * @return mixed The 'result' field from the JSON-RPC response.
	 * @throws \RuntimeException On HTTP or RPC error.
	 */
	private function rpc_call( string $endpoint, array $params ): mixed {
		$payload = [
			'jsonrpc' => '2.0',
			'method'  => 'call',
			'params'  => $params,
			'id'      => bin2hex( random_bytes( 8 ) ),
		];

		$ssl_verify = ! defined( 'WP4ODOO_DISABLE_SSL_VERIFY' ) || ! WP4ODOO_DISABLE_SSL_VERIFY;

		if ( ! $ssl_verify ) {
			$this->logger->warning( 'SSL verification is disabled via WP4ODOO_DISABLE_SSL_VERIFY. This is insecure and should only be used for local development.' );
		}

		$request_args = [
			'timeout'   => $this->timeout,
			'headers'   => [ 'Content-Type' => 'application/json' ],
			'body'      => wp_json_encode( $payload ),
			'sslverify' => $ssl_verify,
		];

		$response    = $this->http_post_with_retry( $this->url . $endpoint, $request_args, $endpoint );
		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( null === $body ) {
			$error_msg = sprintf(
				/* translators: %d: HTTP status code */
				__( 'Invalid JSON response from Odoo (HTTP %d).', 'wp4odoo' ),
				$status_code
			);

			throw new \RuntimeException( $error_msg );
		}

		if ( isset( $body['error'] ) ) {
			$error_data = $body['error']['data'] ?? $body['error'];
			$error_msg  = $error_data['message'] ?? $body['error']['message'] ?? __( 'Unknown RPC error', 'wp4odoo' );

			$context = [
				'endpoint' => $endpoint,
				'error'    => $error_msg,
			];
			// Include model/method context from execute_kw calls.
			if ( isset( $params['args'][3], $params['args'][4] ) ) {
				$context['model']  = $params['args'][3];
				$context['method'] = $params['args'][4];
			}
			if ( isset( $error_data['debug'] ) ) {
				$context['debug'] = mb_substr( (string) $error_data['debug'], 0, 500 );
			}

			$this->logger->error( 'Odoo RPC error.', $context );

			throw new \RuntimeException(
				sprintf(
					/* translators: %s: error message from Odoo */
					__( 'Odoo RPC error: %s', 'wp4odoo' ),
					$error_msg
				)
			);
		}

		return $body['result'] ?? null;
	}
}
