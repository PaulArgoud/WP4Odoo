<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JSON-2 transport for Odoo 19+.
 *
 * Uses the External JSON-2 API (`POST /json/2/<model>/<method>`) that
 * replaces the deprecated `/jsonrpc` and `/xmlrpc` endpoints. Authentication
 * is a bearer API key (passwords are not accepted) and the database may be
 * selected with the `X-Odoo-Database` header.
 *
 * The {@see Transport} interface speaks the legacy `execute_kw` dialect
 * (positional args + kwargs). This class translates the calls made by
 * `Odoo_Client` into JSON-2's named-argument bodies.
 *
 * @package WP4Odoo
 * @since   3.10.0
 */
class Odoo_Json2 extends Odoo_Transport_Base {

	/**
	 * Names of the positional arguments of the ORM methods used by the plugin.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const POSITIONAL_ARGS = [
		'search'       => [ 'domain' ],
		'search_read'  => [ 'domain' ],
		'search_count' => [ 'domain' ],
		'read'         => [ 'ids', 'fields' ],
		'write'        => [ 'ids', 'vals' ],
		'unlink'       => [ 'ids' ],
		'fields_get'   => [ 'allfields' ],
	];

	/**
	 * Return the protocol name used for logging context.
	 *
	 * @return string
	 */
	protected function get_protocol_name(): string {
		return 'json2';
	}

	/**
	 * Authenticate with the API key and resolve the user ID.
	 *
	 * @param string $username The Odoo login (used only as a fallback lookup).
	 * @return int The authenticated user ID (uid).
	 * @throws \RuntimeException On authentication failure.
	 */
	public function authenticate( string $username ): int {
		// Marks the transport as ready; the bearer key is validated by the first call.
		$this->uid = 0;

		try {
			$context = $this->post( 'res.users', 'context_get', [] );
			$uid     = is_array( $context ) ? (int) ( $context['uid'] ?? 0 ) : 0;

			if ( $uid <= 0 && '' !== $username ) {
				$rows = $this->post(
					'res.users',
					'search_read',
					[
						'domain' => [ [ 'login', '=', $username ] ],
						'fields' => [ 'id' ],
						'limit'  => 1,
					]
				);
				$uid  = is_array( $rows ) && ! empty( $rows[0]['id'] ) ? (int) $rows[0]['id'] : 0;
			}
		} catch ( \Throwable $e ) {
			$this->uid = null;

			throw new \RuntimeException(
				sprintf(
					/* translators: %s: error message */
					__( 'Authentication failed: %s', 'wp4odoo' ),
					$e->getMessage()
				),
				(int) $e->getCode(),
				$e
			);
		}

		if ( $uid <= 0 ) {
			$this->uid = null;

			throw new \RuntimeException(
				__( 'Authentication failed: invalid credentials.', 'wp4odoo' )
			);
		}

		$this->uid            = $uid;
		$this->server_version = $this->fetch_server_version();

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
	 * Execute a method on an Odoo model.
	 *
	 * @param string               $model  Odoo model name (e.g., 'res.partner').
	 * @param string               $method Method name (e.g., 'search_read').
	 * @param array<int, mixed>    $args   Positional arguments (execute_kw dialect).
	 * @param array<string, mixed> $kwargs Keyword arguments.
	 * @return mixed The Odoo response result.
	 * @throws \RuntimeException On RPC error, unsupported call shape or if not authenticated.
	 */
	public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
		$this->ensure_authenticated();

		$single_create = false;
		$body          = $this->build_body( $method, $args, $kwargs, $single_create );
		$result        = $this->post( $model, $method, $body );

		// Legacy create() returns an int for a single dict, JSON-2 always returns a list.
		if ( $single_create && is_array( $result ) ) {
			return $result[0] ?? null;
		}

		return $result;
	}

	/**
	 * Translate execute_kw positional args + kwargs into a JSON-2 body.
	 *
	 * @param string               $method        Method name.
	 * @param array<int, mixed>    $args          Positional arguments.
	 * @param array<string, mixed> $kwargs        Keyword arguments.
	 * @param bool                 $single_create Set to true when create() received one values dict.
	 * @return array<string, mixed>
	 * @throws \RuntimeException If the call cannot be expressed with named arguments.
	 */
	private function build_body( string $method, array $args, array $kwargs, bool &$single_create ): array {
		$body = [];

		if ( 'create' === $method ) {
			$values = $args[0] ?? [];
			if ( ! array_is_list( $values ) ) {
				$single_create = true;
				$values        = [ $values ];
			}
			$body['vals_list'] = $values;
		} elseif ( isset( self::POSITIONAL_ARGS[ $method ] ) ) {
			foreach ( self::POSITIONAL_ARGS[ $method ] as $index => $name ) {
				if ( array_key_exists( $index, $args ) ) {
					$body[ $name ] = $args[ $index ];
				}
			}
		} elseif ( [] !== $args ) {
			// Record methods (action_*, etc.): first positional argument is the recordset ids.
			if ( ! is_array( $args[0] ) || ! array_is_list( $args[0] ) || count( $args ) > 1 ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: Odoo method name */
						__( 'The JSON-2 transport cannot translate positional arguments for method "%s".', 'wp4odoo' ),
						$method
					)
				);
			}
			$body['ids'] = $args[0];
		}

		foreach ( $kwargs as $name => $value ) {
			$body[ (string) $name ] = $value;
		}

		return $body;
	}

	/**
	 * POST a JSON-2 request.
	 *
	 * @param string               $model  Odoo model name.
	 * @param string               $method Method name.
	 * @param array<string, mixed> $body   Named arguments.
	 * @return mixed Decoded JSON result.
	 * @throws \RuntimeException On HTTP or Odoo error.
	 */
	private function post( string $model, string $method, array $body ): mixed {
		$endpoint = '/json/2/' . rawurlencode( $model ) . '/' . rawurlencode( $method );

		return $this->request(
			$endpoint,
			[
				'Content-Type'    => 'application/json; charset=utf-8',
				'Authorization'   => 'bearer ' . $this->api_key,
				'X-Odoo-Database' => $this->database,
				'User-Agent'      => 'WP4Odoo/' . ( defined( 'WP4ODOO_VERSION' ) ? WP4ODOO_VERSION : '' ),
			],
			[] === $body ? '{}' : (string) wp_json_encode( $body ),
			$model,
			$method
		);
	}

	/**
	 * Best-effort lookup of the server version (not part of JSON-2).
	 *
	 * @return string|null
	 */
	private function fetch_server_version(): ?string {
		try {
			$result = $this->request(
				'/web/webclient/version_info',
				[ 'Content-Type' => 'application/json' ],
				(string) wp_json_encode(
					[
						'jsonrpc' => '2.0',
						'method'  => 'call',
						'params'  => (object) [],
						'id'      => 1,
					]
				)
			);

			if ( is_array( $result ) && ! empty( $result['server_version'] ) ) {
				return (string) $result['server_version'];
			}
		} catch ( \Throwable $e ) {
			$this->logger->debug( 'Could not read Odoo server version.', [ 'error' => $e->getMessage() ] );
		}

		return null;
	}

	/**
	 * Send an HTTP request and decode the response.
	 *
	 * @param string                $endpoint URL path.
	 * @param array<string, string> $headers  Request headers.
	 * @param string                $payload  JSON body.
	 * @param string                $model    Model name (log context only).
	 * @param string                $method   Method name (log context only).
	 * @return mixed JSON-2 result, or the `result` member of a JSON-RPC envelope.
	 * @throws \RuntimeException On HTTP or Odoo error.
	 */
	private function request( string $endpoint, array $headers, string $payload, string $model = '', string $method = '' ): mixed {
		$ssl_verify = ! defined( 'WP4ODOO_DISABLE_SSL_VERIFY' ) || ! WP4ODOO_DISABLE_SSL_VERIFY;

		if ( ! $ssl_verify ) {
			$this->logger->warning( 'SSL verification is disabled via WP4ODOO_DISABLE_SSL_VERIFY. This is insecure and should only be used for local development.' );
		}

		$request_args = [
			'timeout'   => $this->timeout,
			'headers'   => $headers,
			'body'      => $payload,
			'sslverify' => $ssl_verify,
		];

		$response    = $this->http_post_with_retry( $this->url . $endpoint, $request_args, $endpoint, true );
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code >= 400 ) {
			$message = is_array( $body ) ? (string) ( $body['message'] ?? '' ) : '';
			$name    = is_array( $body ) ? (string) ( $body['name'] ?? '' ) : '';
			if ( '' === $message ) {
				$message = '' !== $name
					? $name
					/* translators: %d: HTTP status code */
					: sprintf( __( 'HTTP %d', 'wp4odoo' ), $status_code );
			} elseif ( '' !== $name ) {
				// Keep the exception class (e.g. odoo.exceptions.ValidationError) for error classification.
				$message .= ' (' . $name . ')';
			}

			$context = [
				'endpoint' => $endpoint,
				'status'   => $status_code,
				'error'    => $message,
			];
			if ( '' !== $model ) {
				$context['model']  = $model;
				$context['method'] = $method;
			}
			if ( is_array( $body ) && isset( $body['debug'] ) ) {
				$context['debug'] = mb_substr( (string) $body['debug'], 0, 500 );
			}

			$this->logger->error( 'Odoo RPC error.', $context );

			throw new \RuntimeException(
				sprintf(
					/* translators: %s: error message from Odoo */
					__( 'Odoo RPC error: %s', 'wp4odoo' ),
					$message
				),
				$status_code
			);
		}

		// A literal `null` is a valid result (e.g. action_apply_inventory); only malformed JSON is an error.
		if ( JSON_ERROR_NONE !== json_last_error() && '' !== trim( wp_remote_retrieve_body( $response ) ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Invalid JSON response from Odoo (HTTP %d).', 'wp4odoo' ),
					$status_code
				)
			);
		}

		// The version probe uses a JSON-RPC envelope; JSON-2 returns the bare result.
		if ( is_array( $body ) && isset( $body['jsonrpc'] ) ) {
			return $body['result'] ?? null;
		}

		return $body;
	}
}
