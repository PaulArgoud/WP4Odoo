<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync queue manager with both static and instance APIs.
 *
 * Provides semantic methods for pushing/pulling sync jobs and
 * queue management (stats, retry, cleanup). The static API is
 * preserved for backward compatibility (~30 callsites across
 * module traits and infrastructure). The instance API enables
 * dependency injection via Module_Base::queue() for testability.
 *
 * Internally delegates to a lazily created Sync_Queue_Repository.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Queue_Manager {

	/**
	 * Default debounce delay in seconds.
	 *
	 * When non-zero, newly enqueued jobs get a future scheduled_at
	 * so rapid-fire hook callbacks coalesce into a single job.
	 */
	private const DEBOUNCE_SECONDS = 5;

	/**
	 * Queue depth threshold for warning-level alerting.
	 *
	 * When the number of pending jobs exceeds this value, a
	 * `wp4odoo_queue_depth_warning` action is fired.
	 *
	 * @since 3.4.0
	 */
	public const QUEUE_DEPTH_WARNING = 1000;

	/**
	 * Queue depth threshold for critical-level alerting.
	 *
	 * When the number of pending jobs exceeds this value, a
	 * `wp4odoo_queue_depth_critical` action is fired and a
	 * critical log entry is written.
	 *
	 * @since 3.4.0
	 */
	public const QUEUE_DEPTH_CRITICAL = 5000;

	/**
	 * Lazy Sync_Queue_Repository instance (static, for static API).
	 *
	 * @var Sync_Queue_Repository|null
	 */
	private static ?Sync_Queue_Repository $repo = null;

	/**
	 * Instance-level Sync_Queue_Repository (for instance API).
	 *
	 * @var Sync_Queue_Repository|null
	 */
	private ?Sync_Queue_Repository $instance_repo = null;

	/**
	 * Optional Logger instance for queue depth alerting.
	 *
	 * When provided (e.g. by Sync_Engine), depth alerts share the
	 * caller's Logger and its correlation ID. When null, a one-off
	 * Logger is created on demand (backward compatibility).
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger = null;

	/**
	 * Get or create the static repository instance.
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
	 * Get the instance-level repository.
	 *
	 * Falls back to the static repository when no instance repo is set.
	 *
	 * @return Sync_Queue_Repository
	 */
	private function get_repo(): Sync_Queue_Repository {
		return $this->instance_repo ?? self::repo();
	}

	// -------------------------------------------------------------------------
	// Constructor (instance API)
	// -------------------------------------------------------------------------

	/**
	 * Create a Queue_Manager instance.
	 *
	 * Optionally accepts a Sync_Queue_Repository and Logger for DI/testing.
	 * When omitted, the shared static repository is used and a one-off
	 * Logger is created on demand for depth alerts.
	 *
	 * @param Sync_Queue_Repository|null $repo   Optional repository for DI/testing.
	 * @param Logger|null                $logger Optional logger for depth alerts.
	 */
	public function __construct( ?Sync_Queue_Repository $repo = null, ?Logger $logger = null ) {
		$this->instance_repo = $repo;
		$this->logger        = $logger;
	}

	// -------------------------------------------------------------------------
	// Instance methods (used via Module_Base::queue())
	// -------------------------------------------------------------------------

	/**
	 * Enqueue a WordPress-to-Odoo sync job (instance method).
	 *
	 * @param string   $module       Module identifier.
	 * @param string   $entity_type  Entity type.
	 * @param string   $action       'create', 'update', or 'delete'.
	 * @param int      $wp_id        WordPress entity ID.
	 * @param int|null $odoo_id      Odoo entity ID (if known).
	 * @param array    $payload      Additional data.
	 * @param int      $priority     Priority (1-10).
	 * @param int      $debounce     Debounce delay in seconds (0 = immediate).
	 * @return int|false Job ID or false.
	 */
	public function enqueue_push(
		string $module,
		string $entity_type,
		string $action,
		int $wp_id,
		?int $odoo_id = null,
		array $payload = [],
		int $priority = 5,
		int $debounce = self::DEBOUNCE_SECONDS
	): int|false {
		$args = [
			'module'      => $module,
			'direction'   => 'wp_to_odoo',
			'entity_type' => $entity_type,
			'action'      => $action,
			'wp_id'       => $wp_id,
			'odoo_id'     => $odoo_id,
			'payload'     => $payload,
			'priority'    => $priority,
		];

		if ( $debounce > 0 ) {
			$args['scheduled_at'] = gmdate( 'Y-m-d H:i:s', time() + $debounce );
		}

		$result = $this->get_repo()->enqueue( $args );

		if ( false !== $result ) {
			$this->check_queue_depth();
		}

		return $result;
	}

	/**
	 * Enqueue an Odoo-to-WordPress sync job (instance method).
	 *
	 * @param string   $module       Module identifier.
	 * @param string   $entity_type  Entity type.
	 * @param string   $action       'create', 'update', or 'delete'.
	 * @param int      $odoo_id      Odoo entity ID.
	 * @param int|null $wp_id        WordPress entity ID (if known).
	 * @param array    $payload      Additional data.
	 * @param int      $priority     Priority (1-10).
	 * @param int      $debounce     Debounce delay in seconds (0 = immediate).
	 * @return int|false Job ID or false.
	 */
	public function enqueue_pull(
		string $module,
		string $entity_type,
		string $action,
		int $odoo_id,
		?int $wp_id = null,
		array $payload = [],
		int $priority = 5,
		int $debounce = 0
	): int|false {
		$args = [
			'module'      => $module,
			'direction'   => 'odoo_to_wp',
			'entity_type' => $entity_type,
			'action'      => $action,
			'wp_id'       => $wp_id,
			'odoo_id'     => $odoo_id,
			'payload'     => $payload,
			'priority'    => $priority,
		];

		if ( $debounce > 0 ) {
			$args['scheduled_at'] = gmdate( 'Y-m-d H:i:s', time() + $debounce );
		}

		$result = $this->get_repo()->enqueue( $args );

		if ( false !== $result ) {
			$this->check_queue_depth();
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Queue depth alerting (M4)
	// -------------------------------------------------------------------------

	/**
	 * Get the current queue depth (number of pending jobs).
	 *
	 * @return int Number of pending jobs in the queue.
	 */
	public function get_queue_depth(): int {
		return self::get_depth();
	}

	/**
	 * Get the current queue depth (static version).
	 *
	 * @return int Number of pending jobs in the queue.
	 */
	public static function get_depth(): int {
		return self::repo()->get_pending_count();
	}

	/**
	 * Check queue depth and fire alerting hooks if thresholds are exceeded.
	 *
	 * Uses a transient-based cooldown (5 minutes) to avoid firing hooks
	 * on every single enqueue call. The check is lightweight: a single
	 * COUNT(*) query (or cached result from the repository).
	 *
	 * @return void
	 */
	private function check_queue_depth(): void {
		// Cooldown: only check every 5 minutes to avoid per-enqueue overhead.
		// Blog-scoped to prevent multisite sites from sharing cooldown state.
		$cooldown_key = 'wp4odoo_depth_cooldown_' . get_current_blog_id();
		if ( get_transient( $cooldown_key ) ) {
			return;
		}

		$depth = $this->get_queue_depth();

		if ( $depth >= self::QUEUE_DEPTH_CRITICAL ) {
			set_transient( $cooldown_key, 1, 300 );

			$logger = $this->logger ?? Logger::for_channel( 'queue_manager' );
			$logger->critical(
				'Queue depth critical threshold exceeded.',
				[
					'depth'     => $depth,
					'threshold' => self::QUEUE_DEPTH_CRITICAL,
				]
			);

			/**
			 * Fires when the sync queue depth exceeds the critical threshold.
			 *
			 * Indicates a severe backlog — possible stuck cron, connection
			 * failure, or misconfigured module. Consumers can use this to
			 * send alerts or pause enqueuing.
			 *
			 * @since 3.4.0
			 *
			 * @param int $depth Current number of pending jobs.
			 */
			do_action( 'wp4odoo_queue_depth_critical', $depth );
		} elseif ( $depth >= self::QUEUE_DEPTH_WARNING ) {
			set_transient( $cooldown_key, 1, 300 );

			/**
			 * Fires when the sync queue depth exceeds the warning threshold.
			 *
			 * Indicates the queue is growing faster than it is being processed.
			 * Consumers can use this to trigger admin notices or monitoring alerts.
			 *
			 * @since 3.4.0
			 *
			 * @param int $depth Current number of pending jobs.
			 */
			do_action( 'wp4odoo_queue_depth_warning', $depth );
		}
	}

	// -------------------------------------------------------------------------
	// Static API (backward compatible)
	// -------------------------------------------------------------------------

	/**
	 * Enqueue a WordPress-to-Odoo sync job.
	 *
	 * Uses a short debounce delay so rapid-fire updates to the same
	 * entity coalesce via the dedup mechanism instead of racing.
	 *
	 * @param string   $module       Module identifier.
	 * @param string   $entity_type  Entity type.
	 * @param string   $action       'create', 'update', or 'delete'.
	 * @param int      $wp_id        WordPress entity ID.
	 * @param int|null $odoo_id      Odoo entity ID (if known).
	 * @param array    $payload      Additional data.
	 * @param int      $priority     Priority (1-10).
	 * @param int      $debounce     Debounce delay in seconds (0 = immediate).
	 * @return int|false Job ID or false.
	 */
	public static function push(
		string $module,
		string $entity_type,
		string $action,
		int $wp_id,
		?int $odoo_id = null,
		array $payload = [],
		int $priority = 5,
		int $debounce = self::DEBOUNCE_SECONDS
	): int|false {
		$args = [
			'module'      => $module,
			'direction'   => 'wp_to_odoo',
			'entity_type' => $entity_type,
			'action'      => $action,
			'wp_id'       => $wp_id,
			'odoo_id'     => $odoo_id,
			'payload'     => $payload,
			'priority'    => $priority,
		];

		if ( $debounce > 0 ) {
			$args['scheduled_at'] = gmdate( 'Y-m-d H:i:s', time() + $debounce );
		}

		return self::repo()->enqueue( $args );
	}

	/**
	 * Enqueue an Odoo-to-WordPress sync job.
	 *
	 * @param string   $module       Module identifier.
	 * @param string   $entity_type  Entity type.
	 * @param string   $action       'create', 'update', or 'delete'.
	 * @param int      $odoo_id      Odoo entity ID.
	 * @param int|null $wp_id        WordPress entity ID (if known).
	 * @param array    $payload      Additional data.
	 * @param int      $priority     Priority (1-10).
	 * @param int      $debounce     Debounce delay in seconds (0 = immediate).
	 * @return int|false Job ID or false.
	 */
	public static function pull(
		string $module,
		string $entity_type,
		string $action,
		int $odoo_id,
		?int $wp_id = null,
		array $payload = [],
		int $priority = 5,
		int $debounce = 0
	): int|false {
		$args = [
			'module'      => $module,
			'direction'   => 'odoo_to_wp',
			'entity_type' => $entity_type,
			'action'      => $action,
			'wp_id'       => $wp_id,
			'odoo_id'     => $odoo_id,
			'payload'     => $payload,
			'priority'    => $priority,
		];

		if ( $debounce > 0 ) {
			$args['scheduled_at'] = gmdate( 'Y-m-d H:i:s', time() + $debounce );
		}

		return self::repo()->enqueue( $args );
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

	/**
	 * Get queue health metrics (latency, success rate, depth by module).
	 *
	 * @return array{avg_latency_seconds: float, success_rate: float, depth_by_module: array<string, int>}
	 */
	public static function get_health_metrics(): array {
		return self::repo()->get_health_metrics();
	}

	/**
	 * Reset the static repository instance.
	 *
	 * Forces a fresh Sync_Queue_Repository on the next call.
	 * Used by PHPUnit tests to isolate static state between tests.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$repo = null;
	}
}
