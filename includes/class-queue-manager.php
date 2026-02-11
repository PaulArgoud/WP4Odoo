<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static convenience wrapper for sync queue operations.
 *
 * Provides semantic methods for pushing/pulling sync jobs and
 * queue management (stats, retry, cleanup). Stays static because
 * it is called from ~30 hook callbacks across all module traits.
 *
 * Internally delegates to a lazily created Sync_Queue_Repository.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Queue_Manager {

	/**
	 * Lazy Sync_Queue_Repository instance.
	 *
	 * @var Sync_Queue_Repository|null
	 */
	private static ?Sync_Queue_Repository $repo = null;

	/**
	 * Get or create the internal repository instance.
	 *
	 * @return Sync_Queue_Repository
	 */
	private static function repo(): Sync_Queue_Repository {
		if ( null === self::$repo ) {
			self::$repo = new Sync_Queue_Repository();
		}
		return self::$repo;
	}

	/**
	 * Enqueue a WordPress-to-Odoo sync job.
	 *
	 * @param string   $module      Module identifier.
	 * @param string   $entity_type Entity type.
	 * @param string   $action      'create', 'update', or 'delete'.
	 * @param int      $wp_id       WordPress entity ID.
	 * @param int|null $odoo_id     Odoo entity ID (if known).
	 * @param array    $payload     Additional data.
	 * @param int      $priority    Priority (1-10).
	 * @return int|false Job ID or false.
	 */
	public static function push(
		string $module,
		string $entity_type,
		string $action,
		int $wp_id,
		?int $odoo_id = null,
		array $payload = [],
		int $priority = 5
	): int|false {
		return self::repo()->enqueue(
			[
				'module'      => $module,
				'direction'   => 'wp_to_odoo',
				'entity_type' => $entity_type,
				'action'      => $action,
				'wp_id'       => $wp_id,
				'odoo_id'     => $odoo_id,
				'payload'     => $payload,
				'priority'    => $priority,
			]
		);
	}

	/**
	 * Enqueue an Odoo-to-WordPress sync job.
	 *
	 * @param string   $module      Module identifier.
	 * @param string   $entity_type Entity type.
	 * @param string   $action      'create', 'update', or 'delete'.
	 * @param int      $odoo_id     Odoo entity ID.
	 * @param int|null $wp_id       WordPress entity ID (if known).
	 * @param array    $payload     Additional data.
	 * @param int      $priority    Priority (1-10).
	 * @return int|false Job ID or false.
	 */
	public static function pull(
		string $module,
		string $entity_type,
		string $action,
		int $odoo_id,
		?int $wp_id = null,
		array $payload = [],
		int $priority = 5
	): int|false {
		return self::repo()->enqueue(
			[
				'module'      => $module,
				'direction'   => 'odoo_to_wp',
				'entity_type' => $entity_type,
				'action'      => $action,
				'wp_id'       => $wp_id,
				'odoo_id'     => $odoo_id,
				'payload'     => $payload,
				'priority'    => $priority,
			]
		);
	}

	/**
	 * Cancel a pending job.
	 *
	 * Only deletes jobs with status 'pending'.
	 *
	 * @param int $job_id The queue job ID.
	 * @return bool True if deleted.
	 */
	public static function cancel( int $job_id ): bool {
		return self::repo()->cancel( $job_id );
	}

	/**
	 * Get all pending jobs for a module.
	 *
	 * @param string      $module      Module identifier.
	 * @param string|null $entity_type Optional entity type filter.
	 * @return array Array of job objects.
	 */
	public static function get_pending( string $module, ?string $entity_type = null ): array {
		return self::repo()->get_pending( $module, $entity_type );
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array{pending: int, processing: int, completed: int, failed: int, total: int, last_completed_at: string}
	 */
	public static function get_stats(): array {
		return self::repo()->get_stats();
	}

	/**
	 * Retry all failed jobs by resetting their status to pending.
	 *
	 * @return int Number of jobs reset.
	 */
	public static function retry_failed(): int {
		return self::repo()->retry_failed();
	}

	/**
	 * Clean up completed and old failed jobs.
	 *
	 * @param int $days_old Delete completed/failed jobs older than this many days.
	 * @return int Number of deleted rows.
	 */
	public static function cleanup( int $days_old = 7 ): int {
		return self::repo()->cleanup( $days_old );
	}
}
