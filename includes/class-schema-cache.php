<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Odoo model schema cache.
 *
 * Caches `fields_get()` results per Odoo model in memory and
 * WordPress transients (24 h). Used by Module_Base to validate
 * field mappings at push time and warn about missing fields.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class Schema_Cache {

	/**
	 * Transient key prefix.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'wp4odoo_schema_';

	/**
	 * Cache time-to-live in seconds (24 hours).
	 *
	 * @var int
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * In-memory cache to avoid repeated transient reads.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $memory = [];

	/**
	 * Get field definitions for an Odoo model.
	 *
	 * Checks memory first, then transient, then calls the Odoo API.
	 * Returns an empty array on failure (non-blocking — the push
	 * proceeds without validation).
	 *
	 * @param \Closure $client_fn Closure returning the Odoo_Client instance.
	 * @param string   $model     Odoo model name (e.g. 'product.template').
	 * @return array<string, mixed> Field name => field metadata, or empty array.
	 */
	public static function get_fields( \Closure $client_fn, string $model ): array {
		if ( isset( self::$memory[ $model ] ) ) {
			return self::$memory[ $model ];
		}

		$key    = self::TRANSIENT_PREFIX . md5( $model );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			self::$memory[ $model ] = $cached;
			return $cached;
		}

		try {
			$fields = $client_fn()->fields_get( $model, [ 'string', 'type', 'required' ] );
		} catch ( \Throwable $e ) {
			return [];
		}

		if ( ! empty( $fields ) ) {
			set_transient( $key, $fields, self::CACHE_TTL );
			self::$memory[ $model ] = $fields;
		}

		return $fields;
	}

	/**
	 * Flush the in-memory cache.
	 *
	 * Transients auto-expire; this only clears the per-request memory.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memory = [];
	}

	/**
	 * Flush both in-memory and persistent (transient) caches.
	 *
	 * Deletes all `wp4odoo_schema_*` transients from the database
	 * and clears the per-request memory. Use this when Odoo model
	 * schemas have changed and cached field definitions are stale.
	 *
	 * Also fires the `wp4odoo_schema_cache_flushed` action after cleanup
	 * so third-party code can react to the invalidation.
	 *
	 * @return int Number of transients deleted.
	 *
	 * @since 3.7.0
	 */
	public static function flush_all(): int {
		global $wpdb;

		self::$memory = [];

		// Delete all schema transients in one query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%'
			)
		);

		/**
		 * Fires after the schema cache has been fully flushed.
		 *
		 * @since 3.7.0
		 *
		 * @param int $deleted Number of transient rows deleted.
		 */
		do_action( 'wp4odoo_schema_cache_flushed', $deleted );

		return $deleted;
	}
}
