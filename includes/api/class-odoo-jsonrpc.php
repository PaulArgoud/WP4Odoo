<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

use WP4Odoo\Logger;

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
class Odoo_JsonRPC implements Transport {

	use Retryable_Http;

	/**
	 * Odoo server URL (no trailing slash).
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Odoo database name.
	 *
	 * @var string
	 */
	private string $database;

	/**
	 * Authenticated user ID.
	 *
	 * @var int|null
	 */
	private ?int $uid = null;

	/**
	 * API key or password.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Request counter for JSON-RPC ID.
	 *
	 * @var int
	 */
	private int $request_id = 0;

	/**
	 * Constructor.
	 *
	 * @param string $url      Odoo server URL.
	 * @param string $database Database name.
	 * @param string $api_key  API key or password.
	 * @param int    $timeout  Request timeout in seconds.
	 */
	public function __construct( string $url, string $database, string $api_key, int $timeout = 30 ) {
		$this->url      = rtrim( $url, '/' );
		$this->database = $database;
		$this->api_key  = $api_key;
		$this->timeout  = $timeout;
		$this->logger   = new Logger( 'jsonrpc' );
	}

	/**
	 * Authenticate against Odoo and retrieve the user ID.
	 *
	 * @param string $username The Odoo login.
	 * @return int The authenticated user ID (uid).
	 * @throws \RuntimeException On authentication failure.
	 */
	public function authenticate( string $username ): int {
		$result = $this->rpc_call( '/web/session/authenticate', [
			'db'       => $this->database,
			'login'    => $username,
			'password' => $this->api_key,
		] );

		if ( empty( $result['uid'] ) || false === $result['uid'] ) {
			throw new \RuntimeException(
				__( 'Authentication failed: invalid credentials.', 'wp4odoo' )
			);
		}

		$this->uid = (int) $result['uid'];

		$this->logger->debug( 'Authenticated successfully.', [
			'uid' => $this->uid,
			'url' => $this->url,
		] );

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
		if ( null === $this->uid ) {
			throw new \RuntimeException(
				__( 'Not authenticated. Call authenticate() first.', 'wp4odoo' )
			);
		}

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
	 * Get the authenticated user ID.
	 *
	 * @return int|null
	 */
	public function get_uid(): ?int {
		return $this->uid;
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
		++$this->request_id;

		$payload = [
			'jsonrpc' => '2.0',
			'method'  => 'call',
			'params'  => $params,
			'id'      => $this->request_id,
		];

		/** This filter is documented in includes/api/class-odoo-xmlrpc.php */
		$ssl_verify = apply_filters( 'wp4odoo_ssl_verify', true );

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
				'endpoint'  => $endpoint,
				'error'     => $error_msg,
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
