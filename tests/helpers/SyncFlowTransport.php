<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\API\Transport;

/**
 * Mock transport returning method-appropriate values for sync flow testing.
 *
 * - search / search_read → empty array (no existing records)
 * - create → configurable Odoo ID
 * - write / unlink → true
 *
 * @package WP4Odoo\Tests\Integration
 * @since   3.6.0
 */
class SyncFlowTransport implements Transport {

	/**
	 * Recorded execute_kw calls.
	 *
	 * @var array<int, array{model: string, method: string, args: array, kwargs: array}>
	 */
	public array $calls = [];

	/**
	 * Odoo ID returned by create.
	 *
	 * @var int
	 */
	public int $create_id = 42;

	/**
	 * When true, execute_kw throws a RuntimeException.
	 *
	 * @var bool
	 */
	public bool $should_fail = false;

	/**
	 * Error message used when should_fail is true.
	 *
	 * @var string
	 */
	public string $fail_message = 'Simulated transport failure';

	/**
	 * Authenticate against Odoo.
	 *
	 * @param string $username The Odoo login.
	 * @return int Always returns 1 (stub).
	 */
	public function authenticate( string $username ): int {
		return 1;
	}

	/**
	 * Execute a method on an Odoo model.
	 *
	 * Returns method-appropriate values: empty array for search,
	 * configured ID for create, true for write/unlink.
	 *
	 * @param string               $model  Odoo model name.
	 * @param string               $method Method name.
	 * @param array<int, mixed>    $args   Positional arguments.
	 * @param array<string, mixed> $kwargs Keyword arguments.
	 * @return mixed
	 */
	public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
		$this->calls[] = compact( 'model', 'method', 'args', 'kwargs' );

		if ( $this->should_fail ) {
			throw new \RuntimeException( $this->fail_message );
		}

		return match ( $method ) {
			'search', 'search_read' => [],
			'create'                => $this->create_id,
			default                 => true,
		};
	}

	/**
	 * Get the authenticated user ID.
	 *
	 * @return int|null Always returns 1 (stub).
	 */
	public function get_uid(): ?int {
		return 1;
	}

	/**
	 * Get the Odoo server version string.
	 *
	 * @return string|null Always returns null (stub).
	 */
	public function get_server_version(): ?string {
		return null;
	}
}
