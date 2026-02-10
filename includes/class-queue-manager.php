<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convenience wrapper around Sync_Engine::enqueue().
 *
 * Provides semantic methods for pushing/pulling sync jobs.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Queue_Manager {

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
		return Sync_Engine::enqueue(
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
		return Sync_Engine::enqueue(
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
		return Sync_Queue_Repository::cancel( $job_id );
	}

	/**
	 * Get all pending jobs for a module.
	 *
	 * @param string      $module      Module identifier.
	 * @param string|null $entity_type Optional entity type filter.
	 * @return array Array of job objects.
	 */
	public static function get_pending( string $module, ?string $entity_type = null ): array {
		return Sync_Queue_Repository::get_pending( $module, $entity_type );
	}
}
