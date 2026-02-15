<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\API\Transport;

/**
 * Mock Transport implementation for testing Odoo_Client.
 *
 * Records all execute_kw calls and returns configurable values or exceptions.
 * Shared across OdooClientTest, TranslationServiceTest, and other tests
 * that need to inject a mock transport into Odoo_Client.
 */
class MockTransport implements Transport {

	/**
	 * Configurable return value for execute_kw.
	 *
	 * @var mixed
	 */
	public mixed $return_value = null;

	/**
	 * Optional exception to throw from execute_kw.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $throw = null;

	/**
	 * Recorded execute_kw calls.
	 *
	 * @var array<int, array{model: string, method: string, args: array, kwargs: array}>
	 */
	public array $calls = [];

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
	 * Records the call, optionally throws, or returns configured value.
	 *
	 * @param string               $model  Odoo model name.
	 * @param string               $method Method name.
	 * @param array<int, mixed>    $args   Positional arguments.
	 * @param array<string, mixed> $kwargs Keyword arguments.
	 * @return mixed
	 * @throws \Throwable If $throw is set.
	 */
	public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
		$this->calls[] = [
			'model'  => $model,
			'method' => $method,
			'args'   => $args,
			'kwargs' => $kwargs,
		];

		if ( $this->throw ) {
			throw $this->throw;
		}

		return $this->return_value;
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
