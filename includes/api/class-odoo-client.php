<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

use WP4Odoo\Logger;
use WP4Odoo\Settings_Repository;

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
	 * Odoo company ID for multi-company context.
	 *
	 * When > 0, injected as `allowed_company_ids` into every API call's
	 * kwargs context. Used by multisite to scope operations to a
	 * specific Odoo res.company.
	 *
	 * @var int
	 */
	private int $company_id = 0;

	/**
	 * Credentials provider closure.
	 *
	 * Returns the decrypted Odoo credentials array. Defaults to
	 * Odoo_Auth::get_credentials() for backward compatibility but
	 * can be overridden for testing or custom credential sources.
	 *
	 * @since 3.8.0
	 * @var \Closure(): array{url: string, database: string, username: string, api_key: string, protocol: string, timeout: int, company_id: int}
	 */
	private \Closure $credentials_provider;

	/**
	 * Constructor.
	 *
	 * @param Transport|null          $transport            Optional pre-configured transport (skips auto-connection).
	 * @param Settings_Repository|null $settings            Optional settings repository for the logger.
	 * @param \Closure|null           $credentials_provider Returns credentials array. Defaults to Odoo_Auth::get_credentials().
	 */
	public function __construct( ?Transport $transport = null, ?Settings_Repository $settings = null, ?\Closure $credentials_provider = null ) {
		$this->logger               = Logger::for_channel( 'api', $settings );
		$this->credentials_provider = $credentials_provider ?? static fn() => Odoo_Auth::get_credentials();

		if ( null !== $transport ) {
			$this->transport = $transport;
			$this->connected = true;
		}
	}

	/**
	 * Get the configured Odoo company ID.
	 *
	 * Returns 0 when no multi-company scoping is active.
	 * Triggers connection if not yet connected so the value
	 * is read from credentials.
	 *
	 * @return int
	 */
	public function get_company_id(): int {
		$this->ensure_connected();
		return $this->company_id;
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

		$credentials = ( $this->credentials_provider )();

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

		$timeout = $credentials['timeout'] ?? 30; // @phpstan-ignore nullCoalesce.offset

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
		$this->company_id = (int) $credentials['company_id'];
		$this->connected  = true;
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
	 * @param string              $model   Odoo model name.
	 * @param array<int>          $ids     Record IDs to read.
	 * @param array<int, string>  $fields  Fields to read.
	 * @param array<string, mixed> $context Optional Odoo context (e.g. ['lang' => 'fr_FR']).
	 * @return array<int, array<string, mixed>> Array of record arrays.
	 */
	public function read( string $model, array $ids, array $fields = [], array $context = [] ): array {
		$kwargs = [];

		if ( ! empty( $fields ) ) {
			$kwargs['fields'] = $fields;
		}
		if ( ! empty( $context ) ) {
			$kwargs['context'] = $context;
		}

		$result = $this->call( $model, 'read', [ $ids ], $kwargs );

		return is_array( $result ) ? $result : [];
	}

	/**
	 * Create a new record.
	 *
	 * @param string               $model   Odoo model name.
	 * @param array<string, mixed> $values  Field values.
	 * @param array<string, mixed> $context Optional Odoo context (e.g. ['lang' => 'fr_FR']).
	 * @return int The new record ID.
	 */
	public function create( string $model, array $values, array $context = [] ): int {
		$kwargs = [];
		if ( ! empty( $context ) ) {
			$kwargs['context'] = $context;
		}

		$result = $this->call( $model, 'create', [ $values ], $kwargs );

		return (int) $result;
	}

	/**
	 * Create multiple records in a single RPC call.
	 *
	 * Odoo's `create()` natively accepts a list of value dicts
	 * and returns a list of IDs. This avoids N round-trips.
	 *
	 * @param string                          $model       Odoo model name.
	 * @param array<int, array<string, mixed>> $values_list Array of value dicts.
	 * @return array<int> Array of new record IDs (same order as input).
	 */
	public function create_batch( string $model, array $values_list ): array {
		if ( empty( $values_list ) ) {
			return [];
		}

		// Single record: delegate to scalar create for backward compat.
		if ( 1 === count( $values_list ) ) {
			return [ $this->create( $model, reset( $values_list ) ) ];
		}

		$result = $this->call( $model, 'create', [ array_values( $values_list ) ] );

		return is_array( $result ) ? array_map( intval( ... ), $result ) : [ (int) $result ];
	}

	/**
	 * Update existing records.
	 *
	 * @param string               $model   Odoo model name.
	 * @param array<int>           $ids     Record IDs to update.
	 * @param array<string, mixed> $values  Field values to update.
	 * @param array<string, mixed> $context Optional Odoo context (e.g. ['lang' => 'fr_FR']).
	 * @return bool True on success.
	 */
	public function write( string $model, array $ids, array $values, array $context = [] ): bool {
		$kwargs = [];
		if ( ! empty( $context ) ) {
			$kwargs['context'] = $context;
		}

		$result = $this->call( $model, 'write', [ $ids, $values ], $kwargs );

		return (bool) $result;
	}

	/**
	 * Update multiple records sharing the same field values in one call.
	 *
	 * Odoo's `write()` accepts multiple IDs natively. This method
	 * groups updates by identical value dicts and batches them.
	 *
	 * @param string                                  $model   Odoo model name.
	 * @param array<int, array{ids: array<int>, values: array<string, mixed>}> $batches Grouped updates.
	 * @return bool True if all batches succeeded.
	 */
	public function write_batch( string $model, array $batches ): bool {
		if ( empty( $batches ) ) {
			return true;
		}

		foreach ( $batches as $batch ) {
			if ( ! $this->write( $model, $batch['ids'], $batch['values'] ) ) {
				return false;
			}
		}

		return true;
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
	 * Reset the connection state.
	 *
	 * Forces the next API call to re-read credentials and create
	 * a fresh transport. Useful after credential changes (WP-CLI,
	 * tests) or when reusing a singleton across requests.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->connected = false;
		$this->transport = null;
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

		// Multi-company context injection: scope all API calls to the
		// configured Odoo company. Odoo uses allowed_company_ids in
		// the context to restrict record visibility and defaults.
		if ( $this->company_id > 0 ) {
			if ( ! isset( $kwargs['context'] ) ) {
				$kwargs['context'] = [];
			}
			if ( ! isset( $kwargs['context']['allowed_company_ids'] ) ) {
				$kwargs['context']['allowed_company_ids'] = [ $this->company_id ];
			}
		}

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
			// Auto-retry on session/authentication errors (403, expired session).
			// Skip retry for non-idempotent methods to prevent unintended
			// side effects if the call succeeded but the response was lost.
			if ( ! $this->is_non_idempotent( $method ) && $this->is_session_error( $e ) ) {
				$this->logger->info(
					'Session expired, re-authenticating and retrying.',
					[
						'model'  => $model,
						'method' => $method,
					]
				);

				$this->reset();
				$this->ensure_connected();

				$result = $this->transport->execute_kw( $model, $method, $args, $kwargs );

				/** This action is documented above. */
				do_action( 'wp4odoo_api_call', $model, $method, $args, $kwargs, $result );

				return $result;
			}

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

	/**
	 * Non-idempotent Odoo method prefixes/names.
	 *
	 * Methods that must NOT be retried on session errors because they
	 * may have side effects even if the response was lost.
	 */
	private const NON_IDEMPOTENT_METHODS = [
		'create',
		'action_',      // Odoo workflow actions (action_post, action_confirm, etc.).
		'button_',      // Odoo UI button methods.
		'validate',     // OCA donation.donation validate().
	];

	/**
	 * Check if an Odoo method is non-idempotent (should not be retried).
	 *
	 * @param string $method Odoo method name.
	 * @return bool True if the method is non-idempotent.
	 */
	private function is_non_idempotent( string $method ): bool {
		foreach ( self::NON_IDEMPOTENT_METHODS as $pattern ) {
			if ( $method === $pattern || str_starts_with( $method, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if an exception indicates a session/authentication error.
	 *
	 * Detection strategy (layered, most reliable first):
	 * 1. HTTP 403 by exception code (set by transport layer).
	 * 2. HTTP 401 by exception code (Odoo 17+ may return 401).
	 * 3. Odoo JSON-RPC error code 100 (session invalid).
	 * 4. Keyword-based message patterns (fallback for unlabeled errors).
	 *
	 * Does NOT match "access denied" because Odoo raises AccessError
	 * with that phrase for business-level permission errors (e.g. user
	 * lacks model access rights), which are not resolvable by re-auth.
	 *
	 * @param \Throwable $e The exception to inspect.
	 * @return bool True if re-authentication might resolve the error.
	 */
	private function is_session_error( \Throwable $e ): bool {
		$code = $e->getCode();

		// HTTP 403/401 by exception code (most reliable).
		if ( 403 === $code || 401 === $code ) {
			return true;
		}

		// Odoo JSON-RPC session error code.
		if ( 100 === $code ) {
			return true;
		}

		$message = strtolower( $e->getMessage() );

		// Keyword-based detection for session/auth errors.
		// Uses word-boundary regex for '403'/'401' to avoid false positives
		// (e.g. "Product #1403" should NOT trigger re-auth).
		// Note: 'access denied' is deliberately excluded — Odoo uses it
		// for AccessError business exceptions (insufficient model ACL),
		// not session expiry. Retrying would be pointless.
		return str_contains( $message, 'session expired' )
			|| str_contains( $message, 'session_expired' )
			|| str_contains( $message, 'session invalid' )
			|| str_contains( $message, 'odoo session' )
			|| str_contains( $message, 'authentication failed' )
			|| (bool) preg_match( '/\bhttp\s*(?:403|401)\b|\b(?:403|401)\s*(?:forbidden|unauthorized)\b/', $message );
	}
}
