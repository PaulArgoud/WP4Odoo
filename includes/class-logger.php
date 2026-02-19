<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DB-backed logger for the WordPress For Odoo.
 *
 * Writes structured logs to {prefix}wp4odoo_logs with level filtering
 * and automatic cleanup based on retention settings.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Logger {

	/**
	 * Maximum byte length for the JSON-encoded context column.
	 *
	 * Prevents unbounded log table growth on high-volume sites.
	 */
	private const MAX_CONTEXT_BYTES = 4096;

	/**
	 * Number of rows to delete per iteration during cleanup.
	 *
	 * Prevents long-running table locks on large sites by chunking
	 * the DELETE into bounded batches.
	 */
	private const CLEANUP_CHUNK_SIZE = 10000;

	/**
	 * Number of log entries to buffer before flushing to DB.
	 *
	 * Reduces DB round-trips during batch processing: log() calls
	 * become pure memory operations until the buffer is full or the
	 * request ends (via shutdown function).
	 *
	 * @since 3.8.0
	 */
	private const WRITE_BUFFER_SIZE = 50;

	/**
	 * Log levels in ascending severity order.
	 *
	 * @var array<string, int>
	 */
	private const LEVELS = [
		'debug'    => 0,
		'info'     => 1,
		'warning'  => 2,
		'error'    => 3,
		'critical' => 4,
	];

	/**
	 * Module context for log entries.
	 *
	 * @var string|null
	 */
	private ?string $module;

	/**
	 * Correlation ID for tracing a job across log entries.
	 *
	 * @var string|null
	 */
	private ?string $correlation_id = null;

	/**
	 * Settings repository (optional, for DI consumers).
	 *
	 * @var Settings_Repository|null
	 */
	private ?Settings_Repository $settings;

	/**
	 * Shared Settings_Repository for factory-created loggers.
	 *
	 * Avoids repeated get_option() calls when multiple factory loggers
	 * are created in the same request (e.g. webhook + queue_manager).
	 *
	 * @var Settings_Repository|null
	 */
	private static ?Settings_Repository $shared_settings = null;

	/**
	 * Write buffer for deferred batch inserts.
	 *
	 * Shared across all Logger instances to aggregate writes from
	 * different channels into a single flush operation.
	 *
	 * @var array<int, array{blog_id: int, correlation_id: string|null, level: string, module: string|null, message: string, context: string|null}>
	 */
	private static array $write_buffer = [];

	/**
	 * Whether the shutdown flush function has been registered.
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Constructor.
	 *
	 * @param string|null              $module   Optional module identifier to tag all log entries.
	 * @param Settings_Repository|null $settings Optional settings repository.
	 */
	public function __construct( ?string $module = null, ?Settings_Repository $settings = null ) {
		$this->module   = $module;
		$this->settings = $settings;
	}

	/**
	 * Create a Logger for a named channel.
	 *
	 * Uses a shared Settings_Repository instance so multiple loggers
	 * created in the same request don't each trigger a get_option() call.
	 *
	 * @param string                    $channel  Channel name (e.g. 'api', 'webhook', 'sync').
	 * @param Settings_Repository|null  $settings Optional explicit settings (overrides shared).
	 * @return self
	 */
	public static function for_channel( string $channel, ?Settings_Repository $settings = null ): self {
		if ( null === $settings ) {
			if ( null === self::$shared_settings ) {
				self::$shared_settings = new Settings_Repository();
			}
			$settings = self::$shared_settings;
		}

		return new self( $channel, $settings );
	}

	/**
	 * Set the correlation ID for subsequent log entries.
	 *
	 * @param string|null $correlation_id UUID or null to clear.
	 * @return void
	 */
	public function set_correlation_id( ?string $correlation_id ): void {
		$this->correlation_id = $correlation_id;
	}

	/**
	 * Get the current correlation ID.
	 *
	 * @return string|null
	 */
	public function get_correlation_id(): ?string {
		return $this->correlation_id;
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $level   One of: debug, info, warning, error, critical.
	 * @param string $message Human-readable message.
	 * @param array  $context Arbitrary context data (stored as JSON).
	 * @return bool True if the log was written.
	 */
	public function log( string $level, string $message, array $context = [] ): bool {
		if ( ! isset( self::LEVELS[ $level ] ) ) {
			return false;
		}

		if ( ! $this->should_log( $level ) ) {
			return false;
		}

		self::$write_buffer[] = [
			'blog_id'        => (int) get_current_blog_id(),
			'correlation_id' => $this->correlation_id,
			'level'          => $level,
			'module'         => $this->module,
			'message'        => $message,
			'context'        => ! empty( $context ) ? self::truncate_context( $context ) : null,
		];

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( [ self::class, 'flush_buffer' ] );
		}

		if ( count( self::$write_buffer ) >= self::WRITE_BUFFER_SIZE ) {
			self::flush_buffer();
		}

		return true;
	}

	/**
	 * Flush the write buffer to the database.
	 *
	 * Inserts all buffered log entries using individual $wpdb->insert()
	 * calls. Called automatically when the buffer reaches WRITE_BUFFER_SIZE
	 * or at request shutdown.
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public static function flush_buffer(): void {
		if ( empty( self::$write_buffer ) ) {
			return;
		}

		global $wpdb;

		// Guard: $wpdb may be unavailable during late PHP shutdown.
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			self::$write_buffer = [];
			return;
		}

		$table   = $wpdb->prefix . 'wp4odoo_logs';
		$entries = self::$write_buffer;

		// Clear buffer before writing to prevent infinite recursion
		// if insert triggers a log call (e.g., via WordPress hooks).
		self::$write_buffer = [];

		foreach ( $entries as $entry ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				$entry,
				[ '%d', '%s', '%s', '%s', '%s', '%s' ]
			);
		}
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Arbitrary context data.
	 * @return bool
	 */
	public function debug( string $message, array $context = [] ): bool {
		return $this->log( 'debug', $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Arbitrary context data.
	 * @return bool
	 */
	public function info( string $message, array $context = [] ): bool {
		return $this->log( 'info', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Arbitrary context data.
	 * @return bool
	 */
	public function warning( string $message, array $context = [] ): bool {
		return $this->log( 'warning', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Arbitrary context data.
	 * @return bool
	 */
	public function error( string $message, array $context = [] ): bool {
		return $this->log( 'error', $message, $context );
	}

	/**
	 * Log a critical message.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Arbitrary context data.
	 * @return bool
	 */
	public function critical( string $message, array $context = [] ): bool {
		return $this->log( 'critical', $message, $context );
	}

	/**
	 * Encode context to JSON, truncating at the array level to ensure valid JSON.
	 *
	 * If the context has more than 50 keys or the encoded JSON exceeds
	 * MAX_CONTEXT_BYTES, replaces it with a small summary object.
	 *
	 * @param array $context Context data.
	 * @return string|null JSON string, or null if encoding fails.
	 */
	private static function truncate_context( array $context ): ?string {
		if ( count( $context ) > 50 ) {
			return wp_json_encode(
				[
					'_truncated'    => true,
					'original_keys' => count( $context ),
				]
			) ?: null;
		}

		$json = wp_json_encode( $context );

		if ( false === $json ) {
			return null;
		}

		if ( strlen( $json ) > self::MAX_CONTEXT_BYTES ) {
			return wp_json_encode(
				[
					'_truncated'    => true,
					'original_keys' => count( $context ),
				]
			) ?: null;
		}

		return $json;
	}

	/**
	 * Delete logs older than the configured retention period.
	 *
	 * @return int Number of deleted rows.
	 */
	public function cleanup(): int {
		global $wpdb;

		$log_settings = $this->get_log_settings();
		$retention    = (int) ( $log_settings['retention_days'] ?? 30 );

		if ( $retention <= 0 ) {
			return 0;
		}

		$table  = $wpdb->prefix . 'wp4odoo_logs';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );

		// Chunk the DELETE to avoid long-running table locks on large sites.
		// Scoped to current blog so multisite cleanup only removes this site's logs.
		$blog_id       = (int) get_current_blog_id();
		$total_deleted = 0;
		do {
			$deleted        = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE blog_id = %d AND created_at < %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
					$blog_id,
					$cutoff,
					self::CLEANUP_CHUNK_SIZE
				)
			);
			$total_deleted += $deleted;
		} while ( $deleted > 0 );

		return $total_deleted;
	}

	/**
	 * Check if a given level should be logged based on settings.
	 *
	 * Critical messages are always logged regardless of settings.
	 *
	 * @param string $level The level to check.
	 * @return bool
	 */
	private function should_log( string $level ): bool {
		if ( 'critical' === $level ) {
			return true;
		}

		$log_settings = $this->get_log_settings();

		if ( empty( $log_settings['enabled'] ) ) {
			return false;
		}

		$configured_level = $log_settings['level'] ?? 'info';

		if ( ! isset( self::LEVELS[ $configured_level ] ) ) {
			$configured_level = 'info';
		}

		return self::LEVELS[ $level ] >= self::LEVELS[ $configured_level ];
	}

	/**
	 * Get log settings from Settings_Repository or fallback cache.
	 *
	 * @return array
	 */
	private function get_log_settings(): array {
		if ( null !== $this->settings ) {
			return $this->settings->get_log_settings();
		}

		// Fallback: read directly from wp_options (WP object cache handles caching).
		return get_option( Settings_Repository::OPT_LOG_SETTINGS, [] );
	}

	/**
	 * Reset the in-memory settings cache.
	 *
	 * No-op since settings are read directly from wp_options (WP object cache
	 * handles caching). Kept for backward compatibility with Module_Test_Case.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$shared_settings     = null;
		self::$write_buffer        = [];
		self::$shutdown_registered = false;
	}
}
