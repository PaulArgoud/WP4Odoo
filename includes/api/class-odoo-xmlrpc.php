<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XML-RPC transport for Odoo (legacy fallback).
 *
 * Uses two endpoints:
 * - /xmlrpc/2/common for authentication
 * - /xmlrpc/2/object for CRUD operations
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Odoo_XmlRPC implements Transport {

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
		$this->logger   = new Logger( 'xmlrpc' );
	}

	/**
	 * Authenticate via XML-RPC /xmlrpc/2/common.
	 *
	 * @param string $username The Odoo login.
	 * @return int The authenticated uid.
	 * @throws \RuntimeException On failure.
	 */
	public function authenticate( string $username ): int {
		$result = $this->xmlrpc_call(
			'/xmlrpc/2/common',
			'authenticate',
			[ $this->database, $username, $this->api_key, [] ]
		);

		if ( empty( $result ) || false === $result ) {

			throw new \RuntimeException(
				__( 'Authentication failed: invalid credentials.', 'wp4odoo' )
			);
		}

		$this->uid = (int) $result;

		$this->logger->debug(
			'Authenticated successfully via XML-RPC.',
			[
				'uid' => $this->uid,
				'url' => $this->url,
			]
		);

		return $this->uid;
	}

	/**
	 * Execute a method on an Odoo model via /xmlrpc/2/object.
	 *
	 * Same interface as Odoo_JsonRPC::execute_kw().
	 *
	 * @param string               $model  Model name.
	 * @param string               $method Method name.
	 * @param array<int, mixed>    $args   Positional arguments.
	 * @param array<string, mixed> $kwargs Keyword arguments.
	 * @return mixed
	 * @throws \RuntimeException On failure or if not authenticated.
	 */
	public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
		if ( null === $this->uid ) {

			throw new \RuntimeException(
				__( 'Not authenticated. Call authenticate() first.', 'wp4odoo' )
			);
		}

		return $this->xmlrpc_call(
			'/xmlrpc/2/object',
			'execute_kw',
			[
				$this->database,
				$this->uid,
				$this->api_key,
				$model,
				$method,
				$args,
				$kwargs,
			]
		);
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
	 * Send an XML-RPC request.
	 *
	 * @param string               $endpoint URL path.
	 * @param string               $method   XML-RPC method name.
	 * @param array<int, mixed>    $params   Method parameters.
	 * @return mixed
	 * @throws \RuntimeException On HTTP or XML-RPC error.
	 */
	private function xmlrpc_call( string $endpoint, string $method, array $params ): mixed {
		require_once ABSPATH . WPINC . '/IXR/class-IXR-request.php';
		require_once ABSPATH . WPINC . '/IXR/class-IXR-value.php';
		require_once ABSPATH . WPINC . '/IXR/class-IXR-message.php';

		$request = new \IXR_Request( $method, $params );
		$xml     = $request->getXml();

		/**
		 * Filters whether SSL verification is enabled for Odoo API calls.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $verify Whether to verify SSL. Default true.
		 */
		$ssl_verify = apply_filters( 'wp4odoo_ssl_verify', true );

		$request_args = [
			'timeout'   => $this->timeout,
			'headers'   => [ 'Content-Type' => 'text/xml' ],
			'body'      => $xml,
			'sslverify' => $ssl_verify,
		];

		$response = $this->http_post_with_retry( $this->url . $endpoint, $request_args, $endpoint );
		$body     = wp_remote_retrieve_body( $response );

		$message = new \IXR_Message( $body );

		if ( ! $message->parse() ) {

			throw new \RuntimeException(
				__( 'Failed to parse XML-RPC response from Odoo.', 'wp4odoo' )
			);
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP XML-RPC response object property.
		if ( 'fault' === $message->messageType ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP XML-RPC response object property.
			$fault_string = $message->faultString ?? __( 'Unknown XML-RPC fault', 'wp4odoo' );

			$context = [
				'endpoint'    => $endpoint,
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP XML-RPC response object property.
				'faultCode'   => $message->faultCode ?? 0,
				'faultString' => $fault_string,
			];
			// Include model/method context from execute_kw calls.
			if ( isset( $params[3], $params[4] ) ) {
				$context['model']  = $params[3];
				$context['method'] = $params[4];
			}

			$this->logger->error( 'Odoo XML-RPC fault.', $context );

			throw new \RuntimeException(
				sprintf(
					/* translators: %s: error message from Odoo */
					__( 'Odoo XML-RPC error: %s', 'wp4odoo' ),
					$fault_string
				)
			);
		}

		return $message->params[0] ?? null;
	}
}
