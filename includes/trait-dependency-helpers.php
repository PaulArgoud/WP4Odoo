<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependency resolution helpers for Module_Base.
 *
 * Provides entity dependency resolution (ensure parent synced before
 * child), external plugin dependency checks (version bounds, table
 * existence, cron polling notices), and Odoo model probing.
 *
 * Mixed into Module_Base via Module_Helpers and accesses its
 * protected properties/methods through $this.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
trait Dependency_Helpers {

	/**
	 * In-memory cache for has_odoo_model() results.
	 *
	 * Keyed by transient name → bool.
	 *
	 * @var array<string, bool>
	 */
	private array $odoo_model_cache = [];

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
				'type'           => 'warning',
				'message'        => sprintf(
					/* translators: %1$s: plugin name, %2$s: detected version, %3$s: last tested version */
					__( '%1$s %2$s has not been tested (tested up to %3$s). Incompatibilities may occur.', 'wp4odoo' ),
					$plugin_name,
					$version,
					$tested
				),
				'plugin_name'    => $plugin_name,
				'plugin_version' => $version,
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
