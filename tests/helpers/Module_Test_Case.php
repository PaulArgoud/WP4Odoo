<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for module tests.
 *
 * Provides common setUp() boilerplate: $wpdb stub, global store
 * initialization, and the 3 standard test helpers. Module tests
 * extending this class only need to add module-specific globals
 * and instantiate their module.
 *
 * @package WP4Odoo\Tests
 */
abstract class Module_Test_Case extends TestCase {

	/**
	 * WP_DB_Stub instance for database call tracking.
	 *
	 * @var \WP_DB_Stub
	 */
	protected \WP_DB_Stub $wpdb;

	/**
	 * All global stores that should be reset between tests.
	 *
	 * Centralizes the list so that adding a new global only requires
	 * updating this array instead of every test setUp().
	 *
	 * @var array<int, string>
	 */
	private const GLOBAL_STORES = [
		'_wp_options',
		'_wp_transients',
		'_wp_cache',
		'_wp_mail_calls',
		'_wp_posts',
		'_wp_post_meta',
		'_wp_users',
		'_wp_user_meta',
		'_wc_memberships',
		'_wc_membership_plans',
		'_edd_orders',
		'_mepr_transactions',
		'_mepr_subscriptions',
		'_pmpro_levels',
		'_pmpro_orders',
		'_rcp_levels',
		'_rcp_payments',
		'_rcp_memberships',
		'_llms_orders',
		'_llms_enrollments',
		'_wc_subscriptions',
		'_wc_points_rewards',
		'_tribe_events',
		'_tribe_tickets',
		'_tribe_attendees',
		'_wpas_tickets',
		'_supportcandy_tickets',
		'_supportcandy_ticketmeta',
		'_wc_bundles',
		'_wc_composites',
		'_affwp_affiliates',
		'_affwp_referrals',
		'_gamipress_points',
	];

	/**
	 * Set up common test infrastructure.
	 *
	 * Initializes the $wpdb stub and clears all global stores.
	 * Subclasses should call parent::setUp() then add module-specific
	 * globals and create their module instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		global $wpdb;
		$this->wpdb = new \WP_DB_Stub();
		$wpdb       = $this->wpdb;

		self::reset_globals();
		self::reset_static_caches();
	}

	/**
	 * Reset all global stores to empty arrays.
	 *
	 * @return void
	 */
	public static function reset_globals(): void {
		foreach ( self::GLOBAL_STORES as $key ) {
			$GLOBALS[ $key ] = [];
		}
	}

	/**
	 * Reset all static caches in plugin classes.
	 *
	 * Centralizes the static reset calls so that non-module tests
	 * (OdooAuthTest, CLITest, AdminAjaxTest, LoggerTest, etc.) can
	 * reuse the same list without duplicating individual flush calls.
	 *
	 * @return void
	 */
	public static function reset_static_caches(): void {
		\WP4Odoo\Logger::reset_cache();
		\WP4Odoo\API\Odoo_Auth::flush_credentials_cache();
		\WP4Odoo\Queue_Manager::reset();
	}
}
