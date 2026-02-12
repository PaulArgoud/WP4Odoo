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
	 * Settings repository (optional, for DI consumers).
	 *
	 * @var Settings_Repository|null
	 */
	private ?Settings_Repository $settings;

	/**
	 * Cached log settings (fallback when no Settings_Repository injected).
	 *
	 * @var array|null
	 */
	private static ?array $settings_cache = null;

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

		global $wpdb;

		$table = $wpdb->prefix . 'wp4odoo_logs';

		$result = $wpdb->insert(
			$table,
			[
				'level'   => $level,
				'module'  => $this->module,
				'message' => $message,
				'context' => ! empty( $context ) ? self::truncate_context( $context ) : null,
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		return false !== $result;
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
	 * Encode context to JSON and truncate to MAX_CONTEXT_BYTES.
	 *
	 * @param array $context Context data.
	 * @return string|null JSON string, or null if empty.
	 */
	private static function truncate_context( array $context ): ?string {
		$json = wp_json_encode( $context );

		if ( false === $json ) {
			return null;
		}

		if ( strlen( $json ) > self::MAX_CONTEXT_BYTES ) {
			$json = mb_strcut( $json, 0, self::MAX_CONTEXT_BYTES - 14 ) . '…[truncated]';
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

		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is from $wpdb->prefix, safe.
		);
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

		if ( null === self::$settings_cache ) {
			self::$settings_cache = get_option( Settings_Repository::OPT_LOG_SETTINGS, [] );
		}
		return self::$settings_cache;
	}

	/**
	 * Reset the in-memory settings cache.
	 *
	 * Useful in tests or when options change at runtime.
	 *
	 * @return void
	 */
	public static function reset_cache(): void {
		self::$settings_cache = null;
	}
}
