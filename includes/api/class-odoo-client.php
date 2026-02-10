<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * High-level Odoo API client.
 *
 * Wraps the transport layer (JSON-RPC or XML-RPC) and provides
 * a clean interface for Odoo model operations. Uses lazy connection
 * (connects on first actual API call).
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Odoo_Client {

	/**
	 * Transport instance (JsonRPC or XmlRPC).
	 *
	 * @var Transport|null
	 */
	private ?Transport $transport = null;

	/**
	 * Whether the client is connected and authenticated.
	 *
	 * @var bool
	 */
	private bool $connected = false;

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger = new Logger( 'api' );
	}

	/**
	 * Ensure the transport is connected and authenticated.
	 *
	 * @return void
	 * @throws \RuntimeException If connection fails.
	 */
	private function ensure_connected(): void {
		if ( $this->connected ) {
			return;
		}

		$credentials = Odoo_Auth::get_credentials();

		if ( empty( $credentials['url'] ) || empty( $credentials['database'] ) ) {

			throw new \RuntimeException(
				__( 'Odoo connection not configured.', 'wp4odoo' )
			);
		}

		if ( empty( $credentials['username'] ) || empty( $credentials['api_key'] ) ) {

			throw new \RuntimeException(
				__( 'Odoo credentials not configured.', 'wp4odoo' )
			);
		}

		$timeout = $credentials['timeout'] ?: 30;

		if ( 'xmlrpc' === $credentials['protocol'] ) {
			$this->transport = new Odoo_XmlRPC(
				$credentials['url'],
				$credentials['database'],
				$credentials['api_key'],
				$timeout
			);
		} else {
			$this->transport = new Odoo_JsonRPC(
				$credentials['url'],
				$credentials['database'],
				$credentials['api_key'],
				$timeout
			);
		}

		$this->transport->authenticate( $credentials['username'] );
		$this->connected = true;
	}

	/**
	 * Search for record IDs matching a domain.
	 *
	 * @param string             $model  Odoo model name.
	 * @param array<int, mixed> $domain Search domain (Odoo Polish notation).
	 * @param int               $offset Offset for pagination.
	 * @param int               $limit  Maximum records to return (0 = no limit).
	 * @param string            $order  Sort order (e.g., "name asc").
	 * @return array<int> Array of matching record IDs.
	 */
	public function search( string $model, array $domain = [], int $offset = 0, int $limit = 0, string $order = '' ): array {
		$kwargs = [];

		if ( $offset > 0 ) {
			$kwargs['offset'] = $offset;
		}
		if ( $limit > 0 ) {
			$kwargs['limit'] = $limit;
		}
		if ( '' !== $order ) {
			$kwargs['order'] = $order;
		}

		$result = $this->call( $model, 'search', [ $domain ], $kwargs );

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Search and read records in one call.
	 *
	 * @param string              $model  Odoo model name.
	 * @param array<int, mixed>  $domain Search domain.
	 * @param array<int, string> $fields Fields to read (empty = all).
	 * @param int                $offset Offset for pagination.
	 * @param int                $limit  Maximum records.
	 * @param string             $order  Sort order.
	 * @return array<int, array<string, mixed>> Array of record arrays.
	 */
	public function search_read( string $model, array $domain = [], array $fields = [], int $offset = 0, int $limit = 0, string $order = '' ): array {
		$kwargs = [];

		if ( ! empty( $fields ) ) {
			$kwargs['fields'] = $fields;
		}
		if ( $offset > 0 ) {
			$kwargs['offset'] = $offset;
		}
		if ( $limit > 0 ) {
			$kwargs['limit'] = $limit;
		}
		if ( '' !== $order ) {
			$kwargs['order'] = $order;
		}

		$result = $this->call( $model, 'search_read', [ $domain ], $kwargs );

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Read specific records by IDs.
	 *
	 * @param string              $model  Odoo model name.
	 * @param array<int>          $ids    Record IDs to read.
	 * @param array<int, string>  $fields Fields to read.
	 * @return array<int, array<string, mixed>> Array of record arrays.
	 */
	public function read( string $model, array $ids, array $fields = [] ): array {
		$kwargs = [];

		if ( ! empty( $fields ) ) {
			$kwargs['fields'] = $fields;
		}

		$result = $this->call( $model, 'read', [ $ids ], $kwargs );

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Create a new record.
	 *
	 * @param string               $model  Odoo model name.
	 * @param array<string, mixed> $values Field values.
	 * @return int The new record ID.
	 */
	public function create( string $model, array $values ): int {
		$result = $this->call( $model, 'create', [ $values ] );

		return (int) $result;
	}

	/**
	 * Update existing records.
	 *
	 * @param string               $model  Odoo model name.
	 * @param array<int>           $ids    Record IDs to update.
	 * @param array<string, mixed> $values Field values to update.
	 * @return bool True on success.
	 */
	public function write( string $model, array $ids, array $values ): bool {
		$result = $this->call( $model, 'write', [ $ids, $values ] );

		return (bool) $result;
	}

	/**
	 * Delete records.
	 *
	 * @param string     $model Odoo model name.
	 * @param array<int> $ids   Record IDs to delete.
	 * @return bool True on success.
	 */
	public function unlink( string $model, array $ids ): bool {
		$result = $this->call( $model, 'unlink', [ $ids ] );

		return (bool) $result;
	}

	/**
	 * Count records matching a domain.
	 *
	 * @param string             $model  Odoo model name.
	 * @param array<int, mixed> $domain Search domain.
	 * @return int
	 */
	public function search_count( string $model, array $domain = [] ): int {
		$result = $this->call( $model, 'search_count', [ $domain ] );

		return (int) $result;
	}

	/**
	 * Get field definitions for a model.
	 *
	 * @param string              $model      Odoo model name.
	 * @param array<int, string> $attributes Optional list of attributes to return.
	 * @return array<string, mixed>
	 */
	public function fields_get( string $model, array $attributes = [] ): array {
		$kwargs = [];

		if ( ! empty( $attributes ) ) {
			$kwargs['attributes'] = $attributes;
		}

		$result = $this->call( $model, 'fields_get', [], $kwargs );

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Execute an arbitrary method on a model.
	 *
	 * @param string               $model  Odoo model name.
	 * @param string               $method Method name.
	 * @param array<int, mixed>    $args   Positional arguments.
	 * @param array<string, mixed> $kwargs Keyword arguments.
	 * @return mixed
	 */
	public function execute( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
		return $this->call( $model, $method, $args, $kwargs );
	}

	/**
	 * Check if the client is connected.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return $this->connected;
	}

	/**
	 * Internal call wrapper: ensures connection, delegates to transport, fires action, handles errors.
	 *
	 * @param string               $model  Odoo model name.
	 * @param string               $method Method name.
	 * @param array<int, mixed>    $args   Positional arguments.
	 * @param array<string, mixed> $kwargs Keyword arguments.
	 * @return mixed
	 * @throws \RuntimeException On failure.
	 */
	private function call( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
		$this->ensure_connected();

		try {
			$result = $this->transport->execute_kw( $model, $method, $args, $kwargs );

			/**
			 * Fires after every Odoo API call.
			 *
			 * @since 1.0.0
			 *
			 * @param string               $model  The Odoo model.
			 * @param string               $method The method called.
			 * @param array<int, mixed>    $args   Positional arguments.
			 * @param array<string, mixed> $kwargs Keyword arguments.
			 * @param mixed  $result The API response.
			 */
			do_action( 'wp4odoo_api_call', $model, $method, $args, $kwargs, $result );

			return $result;
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'API call failed.',
				[
					'model'  => $model,
					'method' => $method,
					'error'  => $e->getMessage(),
				]
			);

			throw $e;
		}
	}
}
