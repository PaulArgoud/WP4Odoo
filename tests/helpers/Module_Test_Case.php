<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests;

use WP4Odoo\Module_Base;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for module tests.
 *
 * Provides common setUp() boilerplate: $wpdb stub, global store
 * initialization, and reusable assertion helpers. Module tests
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

	// ─── Assertion Helpers ────────────────────────────────

	/**
	 * Assert a module's identity properties in one call.
	 *
	 * Replaces the 4–5 individual identity tests that every module
	 * test file repeats (get_id, get_name, exclusive_group, etc.).
	 *
	 * @param Module_Base $module    The module instance.
	 * @param string      $id        Expected module ID.
	 * @param string      $name      Expected module name.
	 * @param string      $group     Expected exclusive group ('' if none).
	 * @param int         $priority  Expected exclusive priority (0 if none).
	 * @param string      $direction Expected sync direction.
	 */
	protected function assertModuleIdentity(
		Module_Base $module,
		string $id,
		string $name,
		string $group = '',
		int $priority = 0,
		string $direction = 'bidirectional'
	): void {
		$this->assertSame( $id, $module->get_id(), 'Module ID mismatch.' );
		$this->assertSame( $name, $module->get_name(), 'Module name mismatch.' );
		$this->assertSame( $group, $module->get_exclusive_group(), 'Exclusive group mismatch.' );
		$this->assertSame( $priority, $module->get_exclusive_priority(), 'Exclusive priority mismatch.' );
		$this->assertSame( $direction, $module->get_sync_direction(), 'Sync direction mismatch.' );
	}

	/**
	 * Assert a module declares the expected Odoo models.
	 *
	 * @param Module_Base                $module   The module instance.
	 * @param array<string, string> $expected Entity type → Odoo model map.
	 */
	protected function assertOdooModels( Module_Base $module, array $expected ): void {
		$models = $module->get_odoo_models();
		$this->assertCount( count( $expected ), $models, 'Odoo models count mismatch.' );
		foreach ( $expected as $entity_type => $odoo_model ) {
			$this->assertSame(
				$odoo_model,
				$models[ $entity_type ] ?? null,
				"Odoo model for entity type '{$entity_type}'."
			);
		}
	}

	/**
	 * Assert a module's default settings match expected values.
	 *
	 * @param Module_Base            $module   The module instance.
	 * @param array<string, mixed> $expected Key → value map.
	 */
	protected function assertDefaultSettings( Module_Base $module, array $expected ): void {
		$settings = $module->get_default_settings();
		$this->assertCount( count( $expected ), $settings, 'Default settings count mismatch.' );
		foreach ( $expected as $key => $value ) {
			$this->assertSame(
				$value,
				$settings[ $key ] ?? null,
				"Default setting '{$key}'."
			);
		}
	}

	/**
	 * Assert all settings fields are checkboxes with labels.
	 *
	 * Most modules only expose checkbox fields. For modules with mixed
	 * field types, use direct assertions instead.
	 *
	 * @param Module_Base       $module        The module instance.
	 * @param array<string> $expected_keys Expected field keys.
	 */
	protected function assertSettingsFieldsAreCheckboxes( Module_Base $module, array $expected_keys ): void {
		$fields = $module->get_settings_fields();
		$this->assertCount( count( $expected_keys ), $fields, 'Settings fields count mismatch.' );
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $fields, "Settings field '{$key}' should exist." );
			$this->assertSame( 'checkbox', $fields[ $key ]['type'], "Settings field '{$key}' should be checkbox." );
			$this->assertNotEmpty( $fields[ $key ]['label'], "Settings field '{$key}' should have a label." );
		}
	}

	/**
	 * Assert the sync queue contains a specific job.
	 *
	 * @param string $module Module ID.
	 * @param string $entity Entity type.
	 * @param string $action Queue action (create, update, delete).
	 * @param int    $wp_id  WordPress entity ID.
	 */
	protected function assertQueueContains( string $module, string $entity, string $action, int $wp_id ): void {
		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => 'insert' === $c['method'] );
		foreach ( $inserts as $call ) {
			$data = $call['args'][1] ?? [];
			if ( ( $data['module'] ?? '' ) === $module
				&& ( $data['entity_type'] ?? '' ) === $entity
				&& ( $data['action'] ?? '' ) === $action
				&& ( $data['wp_id'] ?? 0 ) === $wp_id ) {
				$this->assertTrue( true );
				return;
			}
		}
		$this->fail( "Queue does not contain [{$module}, {$entity}, {$action}, {$wp_id}]." );
	}

	/**
	 * Assert the sync queue has no enqueued jobs.
	 */
	protected function assertQueueEmpty(): void {
		$inserts = array_filter(
			$this->wpdb->calls,
			fn( $c ) => 'insert' === $c['method'] && str_contains( $c['args'][0] ?? '', 'sync_queue' )
		);
		$this->assertEmpty( $inserts, 'Queue should be empty.' );
	}
}
