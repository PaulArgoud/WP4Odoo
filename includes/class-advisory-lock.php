<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MySQL advisory lock wrapper using GET_LOCK / RELEASE_LOCK.
 *
 * Provides a reusable, testable advisory lock mechanism that replaces
 * duplicated boilerplate in Push_Lock, Partner_Service, and Sync_Engine.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
class Advisory_Lock {

	/**
	 * MySQL advisory lock name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Lock acquisition timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Whether this instance currently holds the lock.
	 *
	 * @var bool
	 */
	private bool $held = false;

	/**
	 * Constructor.
	 *
	 * @param string $name    MySQL advisory lock name.
	 * @param int    $timeout Lock acquisition timeout in seconds (default 5).
	 */
	public function __construct( string $name, int $timeout = 5 ) {
		$this->name    = $name;
		$this->timeout = $timeout;
	}

	/**
	 * Acquire the advisory lock.
	 *
	 * Uses MySQL GET_LOCK() which is atomic and server-level.
	 * Returns true if the lock was acquired within the timeout period.
	 *
	 * @return bool True if lock acquired.
	 */
	public function acquire(): bool {
		if ( $this->held ) {
			return true;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $this->name, $this->timeout )
		);

		$this->held = '1' === (string) $result;
		return $this->held;
	}

	/**
	 * Release the advisory lock.
	 *
	 * No-op if the lock is not currently held by this instance.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( ! $this->held ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->name )
		);

		$this->held = false;
	}

	/**
	 * Check whether this instance currently holds the lock.
	 *
	 * @return bool True if the lock is held.
	 */
	public function is_held(): bool {
		return $this->held;
	}

	/**
	 * Get the advisory lock name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Destructor — release the lock if still held.
	 *
	 * Safety net for code paths where release() is not called explicitly
	 * (e.g. an early return or uncaught exception outside a finally block).
	 *
	 * @since 3.9.0
	 */
	public function __destruct() {
		if ( $this->held ) {
			try {
				$this->release();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Destructor must not throw — silently absorb.
			}
		}
	}
}
