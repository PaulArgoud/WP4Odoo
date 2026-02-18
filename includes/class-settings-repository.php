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

	use Failure_Tracking_Settings;
	use UI_State_Settings;
	use Network_Settings;

	/**
	 * Instance-level cache to avoid repeated get_option() calls.
	 *
	 * Keyed by blog ID to prevent stale data when switch_to_blog()
	 * changes the active site in multisite environments.
	 *
	 * @var array<int, array<string, array>>
	 */
	private array $cache = [];

	// ── Option key constants ───────────────────────────────

	public const OPT_CONNECTION    = 'wp4odoo_connection';
	public const OPT_SYNC_SETTINGS = 'wp4odoo_sync_settings';
	public const OPT_LOG_SETTINGS  = 'wp4odoo_log_settings';
	public const OPT_WEBHOOK_TOKEN = 'wp4odoo_webhook_token';
	public const OPT_DB_VERSION    = 'wp4odoo_db_version';

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
		$blog_id = (int) get_current_blog_id();

		if ( isset( $this->cache[ $blog_id ]['connection'] ) ) {
			return $this->cache[ $blog_id ]['connection'];
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

		$this->cache[ $blog_id ]['connection'] = $merged;
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
		$blog_id = (int) get_current_blog_id();
		unset( $this->cache[ $blog_id ]['connection'] );
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
		$blog_id = (int) get_current_blog_id();

		if ( isset( $this->cache[ $blog_id ]['sync'] ) ) {
			return $this->cache[ $blog_id ]['sync'];
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

		$this->cache[ $blog_id ]['sync'] = $merged;
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
		$blog_id = (int) get_current_blog_id();
		unset( $this->cache[ $blog_id ]['sync'] );
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
		$blog_id = (int) get_current_blog_id();

		if ( isset( $this->cache[ $blog_id ]['log'] ) ) {
			return $this->cache[ $blog_id ]['log'];
		}

		$stored = get_option( self::OPT_LOG_SETTINGS, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$merged = array_merge( self::DEFAULTS_LOG, $stored );

		// Defense-in-depth: validate log level and clamp retention.
		$merged['level']          = Settings_Validator::enum( $merged['level'], [ 'debug', 'info', 'warning', 'error', 'critical' ], 'info' );
		$merged['retention_days'] = Settings_Validator::clamp( $merged['retention_days'], 1, 365 );

		$this->cache[ $blog_id ]['log'] = $merged;
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
		$blog_id = (int) get_current_blog_id();
		unset( $this->cache[ $blog_id ]['log'] );
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

	// ── Cache management ─────────────────────────────────

	/**
	 * Flush the instance-level settings cache.
	 *
	 * Forces the next get_*() call to re-read from wp_options.
	 * Call this after external modifications (WP-CLI, concurrent cron,
	 * or another process updating settings via update_option() directly).
	 *
	 * @since 3.6.0
	 *
	 * @return void
	 */
	public function flush_cache(): void {
		$this->cache = [];
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
