<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

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
class Odoo_XmlRPC extends Odoo_Transport_Base {

	/**
	 * Return the protocol name used for logging context.
	 *
	 * @return string
	 */
	protected function get_protocol_name(): string {
		return 'xmlrpc';
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

		// Fetch the server version from /xmlrpc/2/common version().
		try {
			$version_info = $this->xmlrpc_call( '/xmlrpc/2/common', 'version', [] );
			if ( is_array( $version_info ) && ! empty( $version_info['server_version'] ) ) {
				$this->server_version = (string) $version_info['server_version'];
			}
		} catch ( \Throwable $e ) {
			// Non-critical — log and continue.
			$this->logger->debug(
				'Could not retrieve Odoo version via XML-RPC.',
				[ 'error' => $e->getMessage() ]
			);
		}

		$this->logger->debug(
			'Authenticated successfully via XML-RPC.',
			[
				'uid'            => $this->uid,
				'url'            => $this->url,
				'server_version' => $this->server_version,
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
		$this->ensure_authenticated();

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
				(object) $kwargs,
			]
		);
	}

	/**
	 * Send an XML-RPC request.
	 *
	 * @param string            $endpoint URL path.
	 * @param string            $method   XML-RPC method name.
	 * @param array<int, mixed> $params   Method parameters.
	 * @return mixed
	 * @throws \RuntimeException On HTTP or XML-RPC error.
	 */
	private function xmlrpc_call( string $endpoint, string $method, array $params ): mixed {
		require_once ABSPATH . WPINC . '/IXR/class-IXR-request.php';
		require_once ABSPATH . WPINC . '/IXR/class-IXR-value.php';
		require_once ABSPATH . WPINC . '/IXR/class-IXR-message.php';

		$request = new \IXR_Request( $method, $params );
		$xml     = $request->getXml();

		$ssl_verify = ! defined( 'WP4ODOO_DISABLE_SSL_VERIFY' ) || ! WP4ODOO_DISABLE_SSL_VERIFY;

		if ( ! $ssl_verify ) {
			$this->logger->warning( 'SSL verification is disabled via WP4ODOO_DISABLE_SSL_VERIFY. This is insecure and should only be used for local development.' );
		}

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
