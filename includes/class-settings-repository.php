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
		'url'        => '',
		'database'   => '',
		'username'   => '',
		'api_key'    => '',
		'protocol'   => 'jsonrpc',
		'timeout'    => 30,
		'company_id' => 0,
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
		$merged['protocol']   = Settings_Validator::enum( $merged['protocol'], [ 'jsonrpc', 'xmlrpc' ], 'jsonrpc' );
		$merged['timeout']    = Settings_Validator::clamp( $merged['timeout'], 5, 120 );
		$merged['company_id'] = max( 0, (int) ( $merged['company_id'] ?? 0 ) );

		$this->cache['connection'] = $merged;
		return $merged;
	}

	/**
	 * Save connection settings with write-time validation.
	 *
	 * Sanitizes and validates the data before persisting: validates protocol
	 * enum, clamps timeout to 5–120, and ensures URL and database are
	 * non-empty strings.
	 *
	 * @param array $data Connection settings.
	 * @return bool
	 */
	public function save_connection( array $data ): bool {
		unset( $this->cache['connection'] );
		$data = $this->validate_connection( $data );
		return update_option( self::OPT_CONNECTION, $data );
	}

	/**
	 * Validate and sanitize connection settings at write time.
	 *
	 * @param array $data Raw connection settings.
	 * @return array Sanitized connection settings.
	 */
	private function validate_connection( array $data ): array {
		$data = array_merge( self::DEFAULTS_CONNECTION, $data );

		$data['protocol']   = Settings_Validator::enum( $data['protocol'], [ 'jsonrpc', 'xmlrpc' ], 'jsonrpc' );
		$data['timeout']    = Settings_Validator::clamp( $data['timeout'], 5, 120 );
		$data['url']        = Settings_Validator::non_empty_string( $data['url'] );
		$data['database']   = Settings_Validator::non_empty_string( $data['database'] );
		$data['company_id'] = max( 0, (int) ( $data['company_id'] ?? 0 ) );

		return $data;
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
		$merged['direction']     = Settings_Validator::enum( $merged['direction'], [ 'bidirectional', 'wp_to_odoo', 'odoo_to_wp' ], 'bidirectional' );
		$merged['conflict_rule'] = Settings_Validator::enum( $merged['conflict_rule'], [ 'newest_wins', 'odoo_wins', 'wp_wins' ], 'newest_wins' );
		$merged['batch_size']    = Settings_Validator::clamp( $merged['batch_size'], 1, 500 );
		$merged['sync_interval'] = Settings_Validator::enum( $merged['sync_interval'], [ 'wp4odoo_five_minutes', 'wp4odoo_fifteen_minutes' ], 'wp4odoo_five_minutes' );
		$merged['stale_timeout'] = Settings_Validator::clamp( $merged['stale_timeout'], 60, 3600 );

		$this->cache['sync'] = $merged;
		return $merged;
	}

	/**
	 * Save sync settings with write-time validation.
	 *
	 * Sanitizes enums (direction, conflict_rule, sync_interval), clamps
	 * batch_size and stale_timeout to valid ranges.
	 *
	 * @param array $data Sync settings.
	 * @return bool
	 */
	public function save_sync_settings( array $data ): bool {
		unset( $this->cache['sync'] );
		$data = $this->validate_sync_settings( $data );
		return update_option( self::OPT_SYNC_SETTINGS, $data );
	}

	/**
	 * Validate and sanitize sync settings at write time.
	 *
	 * @param array $data Raw sync settings.
	 * @return array Sanitized sync settings.
	 */
	private function validate_sync_settings( array $data ): array {
		$data = array_merge( self::DEFAULTS_SYNC, $data );

		$data['direction']     = Settings_Validator::enum( $data['direction'], [ 'bidirectional', 'wp_to_odoo', 'odoo_to_wp' ], 'bidirectional' );
		$data['conflict_rule'] = Settings_Validator::enum( $data['conflict_rule'], [ 'newest_wins', 'odoo_wins', 'wp_wins' ], 'newest_wins' );
		$data['batch_size']    = Settings_Validator::clamp( $data['batch_size'], 1, 500 );
		$data['sync_interval'] = Settings_Validator::enum( $data['sync_interval'], [ 'wp4odoo_five_minutes', 'wp4odoo_fifteen_minutes' ], 'wp4odoo_five_minutes' );
		$data['stale_timeout'] = Settings_Validator::clamp( $data['stale_timeout'], 60, 3600 );

		return $data;
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
		$merged['level']          = Settings_Validator::enum( $merged['level'], [ 'debug', 'info', 'warning', 'error', 'critical' ], 'info' );
		$merged['retention_days'] = Settings_Validator::clamp( $merged['retention_days'], 1, 365 );

		$this->cache['log'] = $merged;
		return $merged;
	}

	/**
	 * Save log settings with write-time validation.
	 *
	 * Sanitizes the log level enum and clamps retention_days to 1–365.
	 *
	 * @param array $data Log settings.
	 * @return bool
	 */
	public function save_log_settings( array $data ): bool {
		unset( $this->cache['log'] );
		$data = $this->validate_log_settings( $data );
		return update_option( self::OPT_LOG_SETTINGS, $data );
	}

	/**
	 * Validate and sanitize log settings at write time.
	 *
	 * @param array $data Raw log settings.
	 * @return array Sanitized log settings.
	 */
	private function validate_log_settings( array $data ): array {
		$data = array_merge( self::DEFAULTS_LOG, $data );

		$data['level']          = Settings_Validator::enum( $data['level'], [ 'debug', 'info', 'warning', 'error', 'critical' ], 'info' );
		$data['retention_days'] = Settings_Validator::clamp( $data['retention_days'], 1, 365 );

		return $data;
	}

	// ── Typed option helpers ──────────────────────────────

	/**
	 * @since 3.4.0
	 */
	private function get_bool_option( string $key, bool $default = false ): bool {
		return (bool) get_option( $key, $default );
	}

	/**
	 * @since 3.4.0
	 */
	private function set_bool_option( string $key, bool $value, bool $autoload = false ): bool {
		return update_option( $key, $value, $autoload );
	}

	/**
	 * @since 3.4.0
	 */
	private function get_int_option( string $key, int $default = 0 ): int {
		return (int) get_option( $key, $default );
	}

	/**
	 * @since 3.4.0
	 */
	private function set_int_option( string $key, int $value, bool $autoload = false ): bool {
		return update_option( $key, $value, $autoload );
	}

	// ── Module helpers ─────────────────────────────────────

	/**
	 * Check if a module is enabled.
	 *
	 * @param string $id Module identifier.
	 * @return bool
	 */
	public function is_module_enabled( string $id ): bool {
		return $this->get_bool_option( 'wp4odoo_module_' . $id . '_enabled' );
	}

	/**
	 * Enable or disable a module.
	 *
	 * @param string $id      Module identifier.
	 * @param bool   $enabled Whether to enable.
	 * @return bool
	 */
	public function set_module_enabled( string $id, bool $enabled ): bool {
		return $this->set_bool_option( 'wp4odoo_module_' . $id . '_enabled', $enabled, true );
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
		return update_option( 'wp4odoo_module_' . $id . '_settings', $settings, false );
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
	 * Get the webhook token (decrypted).
	 *
	 * @return string
	 */
	public function get_webhook_token(): string {
		$stored = (string) get_option( self::OPT_WEBHOOK_TOKEN, '' );
		if ( '' === $stored ) {
			return '';
		}

		$decrypted = API\Odoo_Auth::decrypt( $stored );

		if ( false !== $decrypted && '' !== $decrypted ) {
			return $decrypted;
		}

		// Backward compat: token stored in plaintext (pre-encryption).
		// Auto-migrate to encrypted storage so this fallback only runs once.
		$this->save_webhook_token( $stored );

		return $stored;
	}

	/**
	 * Save the webhook token (encrypted at rest).
	 *
	 * @param string $token Token value (plaintext).
	 * @return bool
	 */
	public function save_webhook_token( string $token ): bool {
		if ( '' === $token ) {
			return update_option( self::OPT_WEBHOOK_TOKEN, '', false );
		}

		return update_option( self::OPT_WEBHOOK_TOKEN, API\Odoo_Auth::encrypt( $token ), false );
	}

	// ── Failure tracking ───────────────────────────────────

	/**
	 * Get the consecutive failure count.
	 *
	 * @return int
	 */
	public function get_consecutive_failures(): int {
		return $this->get_int_option( self::OPT_CONSECUTIVE_FAILURES );
	}

	/**
	 * Save the consecutive failure count.
	 *
	 * @param int $count Failure count.
	 * @return bool
	 */
	public function save_consecutive_failures( int $count ): bool {
		return $this->set_int_option( self::OPT_CONSECUTIVE_FAILURES, $count );
	}

	/**
	 * Get the last failure email timestamp.
	 *
	 * @return int Unix timestamp.
	 */
	public function get_last_failure_email(): int {
		return $this->get_int_option( self::OPT_LAST_FAILURE_EMAIL );
	}

	/**
	 * Save the last failure email timestamp.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return bool
	 */
	public function save_last_failure_email( int $timestamp ): bool {
		return $this->set_int_option( self::OPT_LAST_FAILURE_EMAIL, $timestamp );
	}

	// ── Onboarding / Checklist ─────────────────────────────

	/**
	 * Check if onboarding notice has been dismissed.
	 *
	 * @return bool
	 */
	public function is_onboarding_dismissed(): bool {
		return $this->get_bool_option( self::OPT_ONBOARDING_DISMISSED );
	}

	/**
	 * Dismiss the onboarding notice.
	 *
	 * @return bool
	 */
	public function dismiss_onboarding(): bool {
		return $this->set_bool_option( self::OPT_ONBOARDING_DISMISSED, true );
	}

	/**
	 * Check if the setup checklist has been dismissed.
	 *
	 * @return bool
	 */
	public function is_checklist_dismissed(): bool {
		return $this->get_bool_option( self::OPT_CHECKLIST_DISMISSED );
	}

	/**
	 * Dismiss the setup checklist.
	 *
	 * @return bool
	 */
	public function dismiss_checklist(): bool {
		return $this->set_bool_option( self::OPT_CHECKLIST_DISMISSED, true );
	}

	/**
	 * Check if webhooks have been confirmed.
	 *
	 * @return bool
	 */
	public function is_webhooks_confirmed(): bool {
		return $this->get_bool_option( self::OPT_CHECKLIST_WEBHOOKS );
	}

	/**
	 * Mark webhooks as confirmed.
	 *
	 * @return bool
	 */
	public function confirm_webhooks(): bool {
		return $this->set_bool_option( self::OPT_CHECKLIST_WEBHOOKS, true );
	}

	// ── Cron health ───────────────────────────────────────

	/**
	 * Get the last cron run Unix timestamp.
	 *
	 * @return int Unix timestamp, or 0 if never run.
	 */
	public function get_last_cron_run(): int {
		return $this->get_int_option( self::OPT_LAST_CRON_RUN );
	}

	/**
	 * Record the current time as the last cron run.
	 *
	 * @return bool
	 */
	public function touch_cron_run(): bool {
		return update_option( self::OPT_LAST_CRON_RUN, time(), false );
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

	// ── Multisite / Network ───────────────────────────────

	/**
	 * Network option key for shared connection settings.
	 */
	public const OPT_NETWORK_CONNECTION = 'wp4odoo_network_connection';

	/**
	 * Network option key for site → company_id mapping.
	 */
	public const OPT_NETWORK_SITE_COMPANIES = 'wp4odoo_network_site_companies';

	/**
	 * Get the effective connection settings.
	 *
	 * In multisite, falls back to the network-level connection if the
	 * current site has no local connection configured. Also applies
	 * the site's company_id from the network mapping.
	 *
	 * In single-site, this is equivalent to get_connection().
	 *
	 * @return array
	 */
	public function get_effective_connection(): array {
		$local = $this->get_connection();

		if ( ! is_multisite() ) {
			return $local;
		}

		// If local site has a configured URL, use the local connection.
		if ( ! empty( $local['url'] ) ) {
			// Apply network company_id if local doesn't have one.
			if ( 0 === (int) $local['company_id'] ) {
				$local['company_id'] = $this->get_site_company_id();
			}
			return $local;
		}

		// Fall back to network connection.
		$network = $this->get_network_connection();
		if ( empty( $network['url'] ) ) {
			return $local; // No network connection either.
		}

		$merged = array_merge( self::DEFAULTS_CONNECTION, $network );

		// Apply the site's company_id from the network mapping.
		$site_company = $this->get_site_company_id();
		if ( $site_company > 0 ) {
			$merged['company_id'] = $site_company;
		}

		return $merged;
	}

	/**
	 * Get network-level connection settings.
	 *
	 * @return array
	 */
	public function get_network_connection(): array {
		if ( ! is_multisite() ) {
			return [];
		}

		$stored = get_site_option( self::OPT_NETWORK_CONNECTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Save network-level connection settings.
	 *
	 * @param array $data Connection settings.
	 * @return bool
	 */
	public function save_network_connection( array $data ): bool {
		return update_site_option( self::OPT_NETWORK_CONNECTION, $data );
	}

	/**
	 * Get the Odoo company_id assigned to the current site.
	 *
	 * @return int Company ID (0 if not assigned).
	 */
	public function get_site_company_id(): int {
		if ( ! is_multisite() ) {
			$conn = $this->get_connection();
			return (int) ( $conn['company_id'] ?? 0 );
		}

		$mapping = get_site_option( self::OPT_NETWORK_SITE_COMPANIES, [] );
		$blog_id = (string) get_current_blog_id();

		return (int) ( $mapping[ $blog_id ] ?? 0 );
	}

	/**
	 * Get the site → company_id mapping for the network.
	 *
	 * @return array<int, int> Blog ID → Company ID.
	 */
	public function get_network_site_companies(): array {
		$stored = get_site_option( self::OPT_NETWORK_SITE_COMPANIES, [] );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Save the site → company_id mapping for the network.
	 *
	 * @param array<int, int> $mapping Blog ID → Company ID.
	 * @return bool
	 */
	public function save_network_site_companies( array $mapping ): bool {
		return update_site_option( self::OPT_NETWORK_SITE_COMPANIES, $mapping );
	}

	/**
	 * Check whether the current site uses the network connection.
	 *
	 * @return bool
	 */
	public function is_using_network_connection(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$local = $this->get_connection();
		return empty( $local['url'] ) && ! empty( $this->get_network_connection()['url'] ?? '' );
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
