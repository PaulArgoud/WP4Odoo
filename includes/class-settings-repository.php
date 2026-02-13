<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized access to all wp4odoo_* options.
 *
 * Single source of truth for option key names, default values,
 * and typed accessors. Injected via constructor (same DI pattern
 * as Entity_Map_Repository and Sync_Queue_Repository).
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class Settings_Repository {

	/**
	 * Instance-level cache to avoid repeated get_option() calls.
	 *
	 * @var array<string, array>
	 */
	private array $cache = [];

	// ── Option key constants ───────────────────────────────

	public const OPT_CONNECTION           = 'wp4odoo_connection';
	public const OPT_SYNC_SETTINGS        = 'wp4odoo_sync_settings';
	public const OPT_LOG_SETTINGS         = 'wp4odoo_log_settings';
	public const OPT_WEBHOOK_TOKEN        = 'wp4odoo_webhook_token';
	public const OPT_CONSECUTIVE_FAILURES = 'wp4odoo_consecutive_failures';
	public const OPT_LAST_FAILURE_EMAIL   = 'wp4odoo_last_failure_email';
	public const OPT_ONBOARDING_DISMISSED = 'wp4odoo_onboarding_dismissed';
	public const OPT_CHECKLIST_DISMISSED  = 'wp4odoo_checklist_dismissed';
	public const OPT_CHECKLIST_WEBHOOKS   = 'wp4odoo_checklist_webhooks_confirmed';
	public const OPT_DB_VERSION           = 'wp4odoo_db_version';
	public const OPT_LAST_CRON_RUN        = 'wp4odoo_last_cron_run';

	// ── Default values (single source of truth) ────────────

	private const DEFAULTS_CONNECTION = [
		'url'      => '',
		'database' => '',
		'username' => '',
		'api_key'  => '',
		'protocol' => 'jsonrpc',
		'timeout'  => 30,
	];

	private const DEFAULTS_SYNC = [
		'direction'         => 'bidirectional',
		'conflict_rule'     => 'newest_wins',
		'batch_size'        => 50,
		'sync_interval'     => 'wp4odoo_five_minutes',
		'auto_sync'         => false,
		'failure_threshold' => 5,
		'failure_cooldown'  => 3600,
		'stale_timeout'     => 600,
	];

	private const DEFAULTS_LOG = [
		'enabled'        => true,
		'level'          => 'info',
		'retention_days' => 30,
	];

	// ── Connection ─────────────────────────────────────────

	/**
	 * Get connection settings merged with defaults.
	 *
	 * @return array
	 */
	public function get_connection(): array {
		if ( isset( $this->cache['connection'] ) ) {
			return $this->cache['connection'];
		}

		$stored = get_option( self::OPT_CONNECTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$merged = array_merge( self::DEFAULTS_CONNECTION, $stored );

		// Defense-in-depth: clamp and validate even if data was saved unchecked.
		if ( ! in_array( $merged['protocol'], [ 'jsonrpc', 'xmlrpc' ], true ) ) {
			$merged['protocol'] = 'jsonrpc';
		}
		$merged['timeout'] = max( 5, min( 120, (int) $merged['timeout'] ) );

		$this->cache['connection'] = $merged;
		return $merged;
	}

	/**
	 * Save connection settings.
	 *
	 * @param array $data Connection settings.
	 * @return bool
	 */
	public function save_connection( array $data ): bool {
		unset( $this->cache['connection'] );
		return update_option( self::OPT_CONNECTION, $data );
	}

	// ── Sync settings ──────────────────────────────────────

	/**
	 * Get sync settings merged with defaults.
	 *
	 * @return array
	 */
	public function get_sync_settings(): array {
		if ( isset( $this->cache['sync'] ) ) {
			return $this->cache['sync'];
		}

		$stored = get_option( self::OPT_SYNC_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$merged = array_merge( self::DEFAULTS_SYNC, $stored );

		// Defense-in-depth: validate enum values and clamp bounds.
		$valid_directions = [ 'bidirectional', 'wp_to_odoo', 'odoo_to_wp' ];
		if ( ! in_array( $merged['direction'], $valid_directions, true ) ) {
			$merged['direction'] = 'bidirectional';
		}

		$valid_conflicts = [ 'newest_wins', 'odoo_wins', 'wp_wins' ];
		if ( ! in_array( $merged['conflict_rule'], $valid_conflicts, true ) ) {
			$merged['conflict_rule'] = 'newest_wins';
		}

		$merged['batch_size'] = max( 1, min( 500, (int) $merged['batch_size'] ) );

		$valid_intervals = [ 'wp4odoo_five_minutes', 'wp4odoo_fifteen_minutes' ];
		if ( ! in_array( $merged['sync_interval'], $valid_intervals, true ) ) {
			$merged['sync_interval'] = 'wp4odoo_five_minutes';
		}

		$merged['stale_timeout'] = max( 60, min( 3600, (int) $merged['stale_timeout'] ) );

		$this->cache['sync'] = $merged;
		return $merged;
	}

	/**
	 * Get the stale processing recovery timeout in seconds.
	 *
	 * Jobs stuck in 'processing' longer than this are reset to 'pending'.
	 *
	 * @return int Timeout in seconds (60–3600, default 600).
	 */
	public function get_stale_timeout(): int {
		$sync = $this->get_sync_settings();
		return (int) $sync['stale_timeout'];
	}

	/**
	 * Get the failure notification threshold.
	 *
	 * Number of consecutive batch failures before sending admin email.
	 *
	 * @return int Threshold (minimum 1).
	 */
	public function get_failure_threshold(): int {
		$sync = $this->get_sync_settings();
		return max( 1, (int) ( $sync['failure_threshold'] ?? 5 ) );
	}

	/**
	 * Get the failure notification cooldown in seconds.
	 *
	 * Minimum interval between admin notification emails.
	 *
	 * @return int Cooldown seconds (minimum 60).
	 */
	public function get_failure_cooldown(): int {
		$sync = $this->get_sync_settings();
		return max( 60, (int) ( $sync['failure_cooldown'] ?? 3600 ) );
	}

	// ── Log settings ───────────────────────────────────────

	/**
	 * Get log settings merged with defaults.
	 *
	 * @return array
	 */
	public function get_log_settings(): array {
		if ( isset( $this->cache['log'] ) ) {
			return $this->cache['log'];
		}

		$stored = get_option( self::OPT_LOG_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$merged = array_merge( self::DEFAULTS_LOG, $stored );

		// Defense-in-depth: validate log level and clamp retention.
		$valid_levels = [ 'debug', 'info', 'warning', 'error', 'critical' ];
		if ( ! in_array( $merged['level'], $valid_levels, true ) ) {
			$merged['level'] = 'info';
		}
		$merged['retention_days'] = max( 1, min( 365, (int) $merged['retention_days'] ) );

		$this->cache['log'] = $merged;
		return $merged;
	}

	// ── Module helpers ─────────────────────────────────────

	/**
	 * Check if a module is enabled.
	 *
	 * @param string $id Module identifier.
	 * @return bool
	 */
	public function is_module_enabled( string $id ): bool {
		return (bool) get_option( 'wp4odoo_module_' . $id . '_enabled', false );
	}

	/**
	 * Enable or disable a module.
	 *
	 * @param string $id      Module identifier.
	 * @param bool   $enabled Whether to enable.
	 * @return bool
	 */
	public function set_module_enabled( string $id, bool $enabled ): bool {
		return update_option( 'wp4odoo_module_' . $id . '_enabled', $enabled );
	}

	/**
	 * Get a module's settings (raw, no merge with defaults).
	 *
	 * @param string $id Module identifier.
	 * @return array
	 */
	public function get_module_settings( string $id ): array {
		$stored = get_option( 'wp4odoo_module_' . $id . '_settings', [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Save a module's settings.
	 *
	 * @param string $id       Module identifier.
	 * @param array  $settings Settings to save.
	 * @return bool
	 */
	public function save_module_settings( string $id, array $settings ): bool {
		return update_option( 'wp4odoo_module_' . $id . '_settings', $settings );
	}

	/**
	 * Get a module's custom field mappings.
	 *
	 * @param string $id Module identifier.
	 * @return array
	 */
	public function get_module_mappings( string $id ): array {
		$stored = get_option( 'wp4odoo_module_' . $id . '_mappings', [] );
		return is_array( $stored ) ? $stored : [];
	}

	// ── Webhook token ──────────────────────────────────────

	/**
	 * Get the webhook token.
	 *
	 * @return string
	 */
	public function get_webhook_token(): string {
		return (string) get_option( self::OPT_WEBHOOK_TOKEN, '' );
	}

	/**
	 * Save the webhook token.
	 *
	 * @param string $token Token value.
	 * @return bool
	 */
	public function save_webhook_token( string $token ): bool {
		return update_option( self::OPT_WEBHOOK_TOKEN, $token );
	}

	// ── Failure tracking ───────────────────────────────────

	/**
	 * Get the consecutive failure count.
	 *
	 * @return int
	 */
	public function get_consecutive_failures(): int {
		return (int) get_option( self::OPT_CONSECUTIVE_FAILURES, 0 );
	}

	/**
	 * Save the consecutive failure count.
	 *
	 * @param int $count Failure count.
	 * @return bool
	 */
	public function save_consecutive_failures( int $count ): bool {
		return update_option( self::OPT_CONSECUTIVE_FAILURES, $count );
	}

	/**
	 * Get the last failure email timestamp.
	 *
	 * @return int Unix timestamp.
	 */
	public function get_last_failure_email(): int {
		return (int) get_option( self::OPT_LAST_FAILURE_EMAIL, 0 );
	}

	/**
	 * Save the last failure email timestamp.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return bool
	 */
	public function save_last_failure_email( int $timestamp ): bool {
		return update_option( self::OPT_LAST_FAILURE_EMAIL, $timestamp );
	}

	// ── Onboarding / Checklist ─────────────────────────────

	/**
	 * Check if onboarding notice has been dismissed.
	 *
	 * @return bool
	 */
	public function is_onboarding_dismissed(): bool {
		return (bool) get_option( self::OPT_ONBOARDING_DISMISSED, false );
	}

	/**
	 * Dismiss the onboarding notice.
	 *
	 * @return bool
	 */
	public function dismiss_onboarding(): bool {
		return update_option( self::OPT_ONBOARDING_DISMISSED, true );
	}

	/**
	 * Check if the setup checklist has been dismissed.
	 *
	 * @return bool
	 */
	public function is_checklist_dismissed(): bool {
		return (bool) get_option( self::OPT_CHECKLIST_DISMISSED, false );
	}

	/**
	 * Dismiss the setup checklist.
	 *
	 * @return bool
	 */
	public function dismiss_checklist(): bool {
		return update_option( self::OPT_CHECKLIST_DISMISSED, true );
	}

	/**
	 * Check if webhooks have been confirmed.
	 *
	 * @return bool
	 */
	public function is_webhooks_confirmed(): bool {
		return (bool) get_option( self::OPT_CHECKLIST_WEBHOOKS, false );
	}

	/**
	 * Mark webhooks as confirmed.
	 *
	 * @return bool
	 */
	public function confirm_webhooks(): bool {
		return update_option( self::OPT_CHECKLIST_WEBHOOKS, true );
	}

	// ── Cron health ───────────────────────────────────────

	/**
	 * Get the last cron run Unix timestamp.
	 *
	 * @return int Unix timestamp, or 0 if never run.
	 */
	public function get_last_cron_run(): int {
		return (int) get_option( self::OPT_LAST_CRON_RUN, 0 );
	}

	/**
	 * Record the current time as the last cron run.
	 *
	 * @return bool
	 */
	public function touch_cron_run(): bool {
		return update_option( self::OPT_LAST_CRON_RUN, time() );
	}

	/**
	 * Check whether WP-Cron appears to be running reliably.
	 *
	 * Returns a warning message if the cron is stale (hasn't run in
	 * 3× the configured sync interval), or empty string if healthy.
	 *
	 * @return string Warning message, or empty string.
	 */
	public function get_cron_warning(): string {
		$sync     = $this->get_sync_settings();
		$last_run = $this->get_last_cron_run();

		// No run recorded yet — only warn if plugin has been active long enough.
		if ( 0 === $last_run ) {
			return '';
		}

		$interval = 'wp4odoo_fifteen_minutes' === $sync['sync_interval'] ? 900 : 300;
		$stale    = time() - $last_run;

		// Warn if more than 3× the expected interval has elapsed.
		if ( $stale > ( $interval * 3 ) ) {
			if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
				return __( 'WP-Cron is disabled (DISABLE_WP_CRON). Ensure a system cron job calls wp-cron.php regularly, or queue processing will not run.', 'wp4odoo' );
			}
			return __( 'WP-Cron has not triggered recently. On low-traffic sites, scheduled sync may be delayed. Consider setting up a system cron job.', 'wp4odoo' );
		}

		return '';
	}

	// ── DB version ─────────────────────────────────────────

	/**
	 * Save the database schema version.
	 *
	 * @param string $version Version string.
	 * @return bool
	 */
	public function save_db_version( string $version ): bool {
		return update_option( self::OPT_DB_VERSION, $version );
	}

	// ── Activation defaults ────────────────────────────────

	/**
	 * Seed default option values if not already present.
	 *
	 * Replaces Database_Migration::set_default_options().
	 *
	 * @return void
	 */
	public function seed_defaults(): void {
		$defaults = [
			self::OPT_CONNECTION    => self::DEFAULTS_CONNECTION,
			self::OPT_SYNC_SETTINGS => self::DEFAULTS_SYNC,
			self::OPT_LOG_SETTINGS  => self::DEFAULTS_LOG,
		];

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				update_option( $key, $value );
			}
		}
	}

	// ── Static default accessors ───────────────────────────

	/**
	 * Get connection defaults.
	 *
	 * @return array
	 */
	public static function connection_defaults(): array {
		return self::DEFAULTS_CONNECTION;
	}

	/**
	 * Get sync defaults.
	 *
	 * @return array
	 */
	public static function sync_defaults(): array {
		return self::DEFAULTS_SYNC;
	}

	/**
	 * Get log defaults.
	 *
	 * @return array
	 */
	public static function log_defaults(): array {
		return self::DEFAULTS_LOG;
	}
}
