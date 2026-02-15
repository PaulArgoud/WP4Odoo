<?php
declare( strict_types=1 );

namespace WP4Odoo;

use WP4Odoo\API\Odoo_Client;
use WP4Odoo\I18n\Translation_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helper methods for Module_Base.
 *
 * Extracted from Module_Base to keep the base class focused on
 * push/pull orchestration, entity mapping, and field mapping.
 *
 * These helpers are mixed into Module_Base via `use Module_Helpers;`
 * and access its protected properties/methods through $this.
 *
 * @package WP4Odoo
 * @since   2.8.0
 */
trait Module_Helpers {

	/**
	 * Lazy Partner_Service instance.
	 *
	 * @var Partner_Service|null
	 */
	private ?Partner_Service $partner_service_instance = null;

	/**
	 * Lazy Translation_Service instance.
	 *
	 * @var Translation_Service|null
	 */
	private ?Translation_Service $translation_service_instance = null;

	// ─── Synthetic ID helpers ──────────────────────────────

	/**
	 * Multiplier for synthetic ID encoding (user_id * MULTIPLIER + entity_id).
	 *
	 * @var int
	 */
	private const SYNTHETIC_ID_MULTIPLIER = 1_000_000;

	/**
	 * Encode two IDs into a single synthetic ID.
	 *
	 * Used by LMS modules (LearnDash, LifterLMS) to represent enrollment
	 * as a single integer (user_id * 1M + course_id) for the entity map.
	 *
	 * @param int $primary_id   Primary ID (e.g. user_id).
	 * @param int $secondary_id Secondary ID (e.g. course_id). Must be < 1,000,000.
	 * @return int Synthetic ID.
	 * @throws \OverflowException If secondary_id >= 1,000,000.
	 */
	public static function encode_synthetic_id( int $primary_id, int $secondary_id ): int {
		if ( $secondary_id >= self::SYNTHETIC_ID_MULTIPLIER ) {
			throw new \OverflowException(
				sprintf( 'Secondary ID %d exceeds synthetic ID multiplier %d.', $secondary_id, self::SYNTHETIC_ID_MULTIPLIER )
			);
		}

		return $primary_id * self::SYNTHETIC_ID_MULTIPLIER + $secondary_id;
	}

	/**
	 * Decode a synthetic ID into its two components.
	 *
	 * @param int $synthetic_id Synthetic ID to decode.
	 * @return array{int, int} [ primary_id, secondary_id ].
	 */
	public static function decode_synthetic_id( int $synthetic_id ): array {
		return [
			intdiv( $synthetic_id, self::SYNTHETIC_ID_MULTIPLIER ),
			$synthetic_id % self::SYNTHETIC_ID_MULTIPLIER,
		];
	}

	// ─── Push helpers ──────────────────────────────────────

	/**
	 * Auto-post an invoice in Odoo after successful creation.
	 *
	 * Checks the module setting, retrieves the Odoo mapping, and calls
	 * `account.move.action_post`. Logs success or failure.
	 *
	 * Setting key convention: `auto_{odoo_verb}_{entity_noun}` where the verb
	 * matches the Odoo RPC method (confirm → action_confirm, post → action_post,
	 * validate → validate). Examples: auto_confirm_orders, auto_post_invoices,
	 * auto_validate_donations, auto_validate_payments.
	 *
	 * @param string $setting_key Settings key to check (e.g., 'auto_post_invoices').
	 * @param string $entity_type Entity type for mapping lookup.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool True if posted successfully, false on skip or error.
	 */
	protected function auto_post_invoice( string $setting_key, string $entity_type, int $wp_id ): bool {
		$settings = $this->get_settings();
		if ( empty( $settings[ $setting_key ] ) ) {
			return false;
		}

		$odoo_id = $this->get_mapping( $entity_type, $wp_id );
		if ( ! $odoo_id ) {
			return false;
		}

		return Modules\Odoo_Accounting_Formatter::auto_post(
			$this->client(),
			Odoo_Model::AccountMove->value,
			$odoo_id,
			$this->logger
		);
	}

	/**
	 * Ensure a parent entity is synced to Odoo before pushing a dependent entity.
	 *
	 * Checks the entity mapping; if the parent is not yet synced, pushes it
	 * synchronously via `Module_Base::push_to_odoo()`.
	 *
	 * PATTERN: Call in `push_to_odoo()` overrides before `parent::push_to_odoo()`
	 * for any entity that depends on a parent being already mapped in Odoo. Used by:
	 * - Booking modules: service must exist before booking push
	 * - LMS modules (LearnDash, LifterLMS): course before enrollment/transaction
	 * - Donation modules (GiveWP, Charitable, SimplePay): form/campaign before donation
	 * - Events Calendar: event before attendee
	 * - Invoice modules (Sprout Invoices): invoice before payment
	 * - Membership modules: level/plan before subscription
	 *
	 * @param string $entity_type Parent entity type (e.g., 'course', 'level').
	 * @param int    $wp_id       WordPress ID of the parent entity.
	 * @return void
	 */
	protected function ensure_entity_synced( string $entity_type, int $wp_id ): void {
		if ( $wp_id <= 0 ) {
			return;
		}

		$existing = $this->get_mapping( $entity_type, $wp_id );
		if ( $existing ) {
			return;
		}

		$this->logger->info(
			"Auto-pushing {$entity_type} before dependent entity.",
			[ 'wp_id' => $wp_id ]
		);
		$this->push_to_odoo( $entity_type, 'create', $wp_id );
	}

	/**
	 * Guard + map + push an entity to the sync queue.
	 *
	 * Common pattern used by hook traits: checks the settings toggle,
	 * resolves the existing Odoo mapping, and queues a create or update.
	 *
	 * @param string $module      Module slug (e.g., 'pmpro', 'rcp').
	 * @param string $entity_type Entity type (e.g., 'level', 'payment').
	 * @param string $setting_key Settings toggle key (e.g., 'sync_levels').
	 * @param int    $wp_id       WordPress entity ID.
	 * @return void
	 */
	protected function push_entity( string $module, string $entity_type, string $setting_key, int $wp_id ): void {
		if ( ! $this->should_sync( $setting_key ) ) {
			return;
		}

		$odoo_id = $this->get_mapping( $entity_type, $wp_id ) ?? 0;
		$action  = $odoo_id ? 'update' : 'create';

		Queue_Manager::push( $module, $entity_type, $action, $wp_id, $odoo_id );
	}

	// ─── Utility helpers ───────────────────────────────────

	/**
	 * Delete a WordPress post (force delete, bypass Trash).
	 *
	 * @param int $wp_id Post ID.
	 * @return bool True on success.
	 */
	protected function delete_wp_post( int $wp_id ): bool {
		$result = wp_delete_post( $wp_id, true );
		return false !== $result && null !== $result;
	}

	/**
	 * Log a warning for an unsupported entity type operation.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $operation   Operation attempted (load, save, delete).
	 * @return void
	 */
	protected function log_unsupported_entity( string $entity_type, string $operation ): void {
		$this->logger->warning(
			"{$this->name}: {$operation} not implemented for entity type '{$entity_type}'.",
			[ 'entity_type' => $entity_type ]
		);
	}

	/**
	 * Resolve a single field from an Odoo Many2one value.
	 *
	 * Reads the related record and returns the requested field value.
	 * Useful for converting Many2one [id, "Name"] references to scalar values
	 * (e.g., country_id → ISO code, state_id → name).
	 *
	 * @param mixed  $many2one_value The Many2one value from Odoo ([id, "Name"] or false).
	 * @param string $model          The Odoo model to read from (e.g., 'res.country').
	 * @param string $field          The field to extract (e.g., 'code').
	 * @return string|null The field value, or null if unresolvable.
	 */
	protected function resolve_many2one_field( mixed $many2one_value, string $model, string $field ): ?string {
		$id = Field_Mapper::many2one_to_id( $many2one_value );

		if ( null === $id ) {
			return null;
		}

		$records = $this->client()->read( $model, [ $id ], [ $field ] );

		if ( empty( $records ) || ! isset( $records[0] ) ) {
			return null;
		}

		if ( ! empty( $records[0][ $field ] ) ) {
			return (string) $records[0][ $field ];
		}

		return null;
	}

	/**
	 * Get or create the Partner_Service instance (lazy).
	 *
	 * Used by any module that needs to resolve WordPress users or
	 * guest emails to Odoo res.partner records.
	 *
	 * @return Partner_Service
	 */
	protected function partner_service(): Partner_Service {
		if ( null === $this->partner_service_instance ) {
			$this->partner_service_instance = new Partner_Service( fn() => $this->client(), $this->entity_map() );
		}

		return $this->partner_service_instance;
	}

	/**
	 * Get or create the Translation_Service instance (lazy).
	 *
	 * Used by any module that needs to push translated field values
	 * to Odoo (WPML/Polylang). Returns the same instance on each call.
	 *
	 * @return Translation_Service
	 */
	protected function translation_service(): Translation_Service {
		if ( null === $this->translation_service_instance ) {
			$this->translation_service_instance = new Translation_Service( fn() => $this->client() );
		}

		return $this->translation_service_instance;
	}

	/**
	 * Resolve a WordPress user ID to an Odoo partner ID.
	 *
	 * Loads the user, extracts email + display name, then delegates to
	 * Partner_Service::get_or_create(). Returns null if the user does
	 * not exist or partner resolution fails.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int|null Odoo partner ID, or null on failure.
	 */
	protected function resolve_partner_from_user( int $user_id ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning(
				'Cannot find WordPress user for partner resolution.',
				[ 'user_id' => $user_id ]
			);
			return null;
		}

		return $this->partner_service()->get_or_create(
			$user->user_email,
			[ 'name' => $user->display_name ],
			$user_id
		);
	}

	/**
	 * Resolve an email address to an Odoo partner ID.
	 *
	 * Delegates directly to Partner_Service::get_or_create().
	 * Suitable for guest users (no WP account) or when email
	 * is already available without user lookup.
	 *
	 * @param string $email Partner email address.
	 * @param string $name  Partner display name (falls back to email in Partner_Service).
	 * @param int    $wp_id Optional WordPress user ID to link (0 if guest).
	 * @return int|null Odoo partner ID, or null on failure.
	 */
	protected function resolve_partner_from_email( string $email, string $name = '', int $wp_id = 0 ): ?int {
		if ( empty( $email ) ) {
			return null;
		}

		$data = [];
		if ( '' !== $name ) {
			$data['name'] = $name;
		}

		return $this->partner_service()->get_or_create( $email, $data, $wp_id );
	}

	/**
	 * In-memory cache for has_odoo_model() results.
	 *
	 * Keyed by transient name → bool.
	 *
	 * @var array<string, bool>
	 */
	private array $odoo_model_cache = [];

	/**
	 * Check whether an Odoo model exists via ir.model probe.
	 *
	 * Shared implementation for dual-model detection (Events Calendar,
	 * WC Subscriptions, Dual_Accounting_Module_Base). The result is
	 * cached in-memory for the request and in a transient (1 hour).
	 *
	 * @param Odoo_Model|string $model_name    Odoo model to probe (e.g. Odoo_Model::EventEvent).
	 * @param string           $transient_key WP transient key (e.g. 'wp4odoo_has_event_event').
	 * @return bool True if the model exists in the connected Odoo instance.
	 */
	protected function has_odoo_model( Odoo_Model|string $model_name, string $transient_key ): bool {
		$model_name = $model_name instanceof Odoo_Model ? $model_name->value : $model_name;
		if ( isset( $this->odoo_model_cache[ $transient_key ] ) ) {
			return $this->odoo_model_cache[ $transient_key ];
		}

		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			$this->odoo_model_cache[ $transient_key ] = (bool) $cached;
			return $this->odoo_model_cache[ $transient_key ];
		}

		try {
			$count  = $this->client()->search_count(
				Odoo_Model::IrModel->value,
				[ [ 'model', '=', $model_name ] ]
			);
			$result = $count > 0;
		} catch ( \Exception $e ) {
			$result = false;
		}

		set_transient( $transient_key, $result ? 1 : 0, HOUR_IN_SECONDS );
		$this->odoo_model_cache[ $transient_key ] = $result;

		return $result;
	}

	/**
	 * Check whether an external plugin dependency is available.
	 *
	 * Helper for get_dependency_status() — returns a standard
	 * available/notices array. Modules call this with their own check.
	 *
	 * @param bool   $is_available Whether the dependency is met.
	 * @param string $plugin_name  Human-readable plugin name for the notice.
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	protected function check_dependency( bool $is_available, string $plugin_name ): array {
		if ( ! $is_available ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => sprintf(
							/* translators: %s: plugin name */
							__( '%s must be installed and activated to use this module.', 'wp4odoo' ),
							$plugin_name
						),
					],
				],
			];
		}

		// Version bounds check.
		$version = $this->get_plugin_version();
		$min     = static::PLUGIN_MIN_VERSION;
		$tested  = static::PLUGIN_TESTED_UP_TO;

		if ( '' !== $version && '' !== $min && version_compare( $version, $min, '<' ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'error',
						'message' => sprintf(
							/* translators: %1$s: plugin name, %2$s: detected version, %3$s: minimum version */
							__( '%1$s %2$s is not supported (minimum required: %3$s).', 'wp4odoo' ),
							$plugin_name,
							$version,
							$min
						),
					],
				],
			];
		}

		$notices = [];

		// Pad TESTED_UP_TO to cover patch releases (e.g. '10.5' covers '10.5.x').
		$tested_cmp = $tested;
		if ( '' !== $tested && substr_count( $version, '.' ) > substr_count( $tested, '.' ) ) {
			$tested_cmp .= '.9999';
		}

		if ( '' !== $version && '' !== $tested && version_compare( $version, $tested_cmp, '>' ) ) {
			$notices[] = [
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %1$s: plugin name, %2$s: detected version, %3$s: last tested version */
					__( '%1$s %2$s has not been tested (tested up to %3$s). Incompatibilities may occur.', 'wp4odoo' ),
					$plugin_name,
					$version,
					$tested
				),
			];
		}

		// Table existence check for modules that access third-party DB tables directly.
		$missing_tables = $this->check_required_tables();
		if ( ! empty( $missing_tables ) ) {
			$notices[] = [
				'type'    => 'warning',
				'message' => sprintf(
					/* translators: %1$s: plugin name, %2$s: comma-separated list of missing table names */
					__( '%1$s database tables missing: %2$s. The plugin may not be fully installed.', 'wp4odoo' ),
					$plugin_name,
					implode( ', ', $missing_tables )
				),
			];
		}

		// System cron recommendation for polling modules.
		if ( $this->uses_cron_polling() && ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) ) {
			$notices[] = [
				'type'    => 'info',
				'message' => __( 'This module relies on WP-Cron polling. For reliable 5-minute intervals, set up a system cron job and define DISABLE_WP_CRON in wp-config.php.', 'wp4odoo' ),
			];
		}

		return [
			'available' => empty( $missing_tables ),
			'notices'   => $notices,
		];
	}

	/**
	 * Get the list of third-party database tables required by this module.
	 *
	 * Modules that access plugin tables directly via $wpdb should override
	 * this method to declare their table dependencies. Table names should
	 * NOT include the WordPress prefix (it will be prepended automatically).
	 *
	 * @return array<int, string> Table names without prefix (e.g. ['amelia_services']).
	 */
	protected function get_required_tables(): array {
		return [];
	}

	/**
	 * Whether this module uses WP-Cron polling for change detection.
	 *
	 * Polling modules (e.g. Bookly, Ecwid) override this to return true,
	 * which triggers an informational notice recommending system cron.
	 *
	 * @return bool
	 */
	protected function uses_cron_polling(): bool {
		return false;
	}

	/**
	 * Check that all required third-party tables exist.
	 *
	 * @return array<int, string> List of missing table names (empty if all present).
	 */
	private function check_required_tables(): array {
		$tables = $this->get_required_tables();
		if ( empty( $tables ) ) {
			return [];
		}

		global $wpdb;

		$missing = [];
		foreach ( $tables as $table ) {
			$full_name = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name )
			);
			if ( null === $exists ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}
}
