<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for the wp4odoo_entity_map table.
 *
 * Centralizes all database operations on the entity mapping table
 * that links WordPress entities to Odoo records.
 *
 * Includes a per-request static cache to avoid redundant DB lookups
 * for the same entity during batch processing.
 *
 * @package WP4Odoo
 * @since   1.2.0
 */
class Entity_Map_Repository {

	/**
	 * Maximum number of IDs in a single SQL IN clause.
	 *
	 * Prevents oversized queries that could exceed max_allowed_packet.
	 */
	private const BATCH_CHUNK_SIZE = 500;

	/**
	 * Per-request lookup cache.
	 *
	 * Keys use the format "{module}:{entity_type}:wp:{wp_id}" or
	 * "{module}:{entity_type}:odoo:{odoo_id}".
	 *
	 * @var array<string, int|null>
	 */
	private array $cache = [];

	/**
	 * Get the Odoo ID mapped to a WordPress entity.
	 *
	 * @param string $module      Module identifier.
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return int|null The Odoo ID, or null if not mapped.
	 */
	public function get_odoo_id( string $module, string $entity_type, int $wp_id ): ?int {
		$cache_key = "{$module}:{$entity_type}:wp:{$wp_id}";

		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wp4odoo_entity_map';

		$odoo_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT odoo_id FROM {$table} WHERE module = %s AND entity_type = %s AND wp_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				$module,
				$entity_type,
				$wp_id
			)
		);

		$result = null !== $odoo_id ? (int) $odoo_id : null;

		$this->cache[ $cache_key ] = $result;

		if ( null !== $result ) {
			$this->cache[ "{$module}:{$entity_type}:odoo:{$result}" ] = $wp_id;
		}

		return $result;
	}

	/**
	 * Get the WordPress ID mapped to an Odoo entity.
	 *
	 * @param string $module      Module identifier.
	 * @param string $entity_type Entity type.
	 * @param int    $odoo_id     Odoo ID.
	 * @return int|null The WordPress ID, or null if not mapped.
	 */
	public function get_wp_id( string $module, string $entity_type, int $odoo_id ): ?int {
		$cache_key = "{$module}:{$entity_type}:odoo:{$odoo_id}";

		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wp4odoo_entity_map';

		$wp_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT wp_id FROM {$table} WHERE module = %s AND entity_type = %s AND odoo_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				$module,
				$entity_type,
				$odoo_id
			)
		);

		$result = null !== $wp_id ? (int) $wp_id : null;

		$this->cache[ $cache_key ] = $result;

		if ( null !== $result ) {
			$this->cache[ "{$module}:{$entity_type}:wp:{$result}" ] = $odoo_id;
		}

		return $result;
	}

	/**
	 * Batch-fetch WordPress IDs for multiple Odoo IDs.
	 *
	 * @param string       $module      Module identifier.
	 * @param string       $entity_type Entity type.
	 * @param array<int>   $odoo_ids    Odoo IDs to look up.
	 * @return array<int, int> Map of odoo_id => wp_id for existing mappings.
	 */
	public function get_wp_ids_batch( string $module, string $entity_type, array $odoo_ids ): array {
		if ( empty( $odoo_ids ) ) {
			return [];
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wp4odoo_entity_map';
		$map   = [];

		foreach ( array_chunk( $odoo_ids, self::BATCH_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$prepare_args = array_merge( [ $module, $entity_type ], array_map( 'intval', $chunk ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table and $placeholders are safe (prefix + array_fill).
			$sql = "SELECT odoo_id, wp_id FROM {$table} WHERE module = %s AND entity_type = %s AND odoo_id IN ({$placeholders})";

			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders for batch query; $sql from safe prefix + array_fill.
				$wpdb->prepare( $sql, $prepare_args )
			);

			if ( $rows ) {
				foreach ( $rows as $row ) {
					$o_id = (int) $row->odoo_id;
					$w_id = (int) $row->wp_id;

					$map[ $o_id ] = $w_id;

					$this->cache[ "{$module}:{$entity_type}:odoo:{$o_id}" ] = $w_id;
					$this->cache[ "{$module}:{$entity_type}:wp:{$w_id}" ]   = $o_id;
				}
			}
		}

		return $map;
	}

	/**
	 * Batch-fetch Odoo IDs for multiple WordPress IDs.
	 *
	 * @param string       $module      Module identifier.
	 * @param string       $entity_type Entity type.
	 * @param array<int>   $wp_ids      WordPress IDs to look up.
	 * @return array<int, int> Map of wp_id => odoo_id for existing mappings.
	 */
	public function get_odoo_ids_batch( string $module, string $entity_type, array $wp_ids ): array {
		if ( empty( $wp_ids ) ) {
			return [];
		}

		global $wpdb;

		$table = $wpdb->prefix . 'wp4odoo_entity_map';
		$map   = [];

		foreach ( array_chunk( $wp_ids, self::BATCH_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$prepare_args = array_merge( [ $module, $entity_type ], array_map( 'intval', $chunk ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table and $placeholders safe (prefix + array_fill).
			$sql = "SELECT wp_id, odoo_id FROM {$table} WHERE module = %s AND entity_type = %s AND wp_id IN ({$placeholders})";

			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders for batch query; $sql from safe prefix + array_fill.
				$wpdb->prepare( $sql, $prepare_args )
			);

			if ( $rows ) {
				foreach ( $rows as $row ) {
					$w_id = (int) $row->wp_id;
					$o_id = (int) $row->odoo_id;

					$map[ $w_id ] = $o_id;

					$this->cache[ "{$module}:{$entity_type}:wp:{$w_id}" ]   = $o_id;
					$this->cache[ "{$module}:{$entity_type}:odoo:{$o_id}" ] = $w_id;
				}
			}
		}

		return $map;
	}

	/**
	 * Save a mapping between a WordPress entity and an Odoo record.
	 *
	 * Uses INSERT ON DUPLICATE KEY UPDATE to upsert the mapping.
	 *
	 * @param string $module      Module identifier.
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @param int    $odoo_id     Odoo ID.
	 * @param string $odoo_model  Odoo model name (e.g. 'res.partner').
	 * @param string $sync_hash   SHA-256 hash of synced data.
	 * @return bool True on success.
	 */
	public function save( string $module, string $entity_type, int $wp_id, int $odoo_id, string $odoo_model, string $sync_hash = '' ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'wp4odoo_entity_map';

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (module, entity_type, wp_id, odoo_id, odoo_model, sync_hash, last_synced_at)
				VALUES (%s, %s, %d, %d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE odoo_id = VALUES(odoo_id), odoo_model = VALUES(odoo_model), sync_hash = VALUES(sync_hash), last_synced_at = VALUES(last_synced_at)",
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$module,
				$entity_type,
				$wp_id,
				$odoo_id,
				$odoo_model,
				$sync_hash,
				$now
			)
		);

		if ( false !== $result ) {
			$this->cache[ "{$module}:{$entity_type}:wp:{$wp_id}" ]     = $odoo_id;
			$this->cache[ "{$module}:{$entity_type}:odoo:{$odoo_id}" ] = $wp_id;
		}

		return false !== $result;
	}

	/**
	 * Remove a mapping.
	 *
	 * @param string $module      Module identifier.
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool True if a mapping was deleted.
	 */
	public function remove( string $module, string $entity_type, int $wp_id ): bool {
		$forward_key = "{$module}:{$entity_type}:wp:{$wp_id}";
		$odoo_id     = $this->cache[ $forward_key ] ?? null;

		// If forward cache was never populated, query DB to find the odoo_id
		// so we can also invalidate the reverse cache entry.
		if ( null === $odoo_id ) {
			$odoo_id = $this->get_odoo_id( $module, $entity_type, $wp_id );
		}

		global $wpdb;

		$table   = $wpdb->prefix . 'wp4odoo_entity_map';
		$deleted = $wpdb->delete(
			$table,
			[
				'module'      => $module,
				'entity_type' => $entity_type,
				'wp_id'       => $wp_id,
			],
			[ '%s', '%s', '%d' ]
		);

		unset( $this->cache[ $forward_key ] );
		if ( null !== $odoo_id ) {
			unset( $this->cache[ "{$module}:{$entity_type}:odoo:{$odoo_id}" ] );
		}

		return $deleted > 0;
	}

	/**
	 * Get all entity mappings for a module and entity type.
	 *
	 * Returns an associative array keyed by wp_id containing odoo_id and sync_hash.
	 * Used by polling modules (Bookly) to efficiently diff against source data.
	 *
	 * @param string $module      Module identifier.
	 * @param string $entity_type Entity type.
	 * @return array<int, array{odoo_id: int, sync_hash: string}>
	 */
	public function get_module_entity_mappings( string $module, string $entity_type ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wp4odoo_entity_map';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT wp_id, odoo_id, sync_hash FROM {$table} WHERE module = %s AND entity_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
				$module,
				$entity_type
			)
		);

		$map = [];
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$w_id = (int) $row->wp_id;
				$o_id = (int) $row->odoo_id;

				$map[ $w_id ] = [
					'odoo_id'   => $o_id,
					'sync_hash' => $row->sync_hash ?? '',
				];

				$this->cache[ "{$module}:{$entity_type}:wp:{$w_id}" ]   = $o_id;
				$this->cache[ "{$module}:{$entity_type}:odoo:{$o_id}" ] = $w_id;
			}
		}

		return $map;
	}

	/**
	 * Flush the per-request lookup cache.
	 *
	 * Useful for testing or after bulk operations.
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->cache = [];
	}
}
