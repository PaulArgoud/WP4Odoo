<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Module_Base;
use WP4Odoo\Module_Registry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the defensive version bounds feature.
 *
 * Covers:
 * - Module_Base default version constants and get_plugin_version().
 * - check_dependency() version bounds logic (MIN_VERSION, TESTED_UP_TO).
 * - Patch-version normalization (e.g. '10.5.0' within '10.5' range).
 * - Module_Registry dependency gating and warning collection.
 *
 * @package WP4Odoo\Tests\Unit
 */
class VersionBoundsTest extends TestCase {

	protected function setUp(): void {
		global $wpdb;
		$wpdb                   = new \WP_DB_Stub();
		$GLOBALS['_wp_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_options'] = [];
		\WP4Odoo_Plugin::reset_instance();
	}

	// ─── Module_Base defaults ─────────────────────────────

	public function test_default_plugin_version_returns_empty_string(): void {
		$module = new VersionBounds_NoVersion_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		// No version constants set → available, no notices.
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── check_dependency() with version OK ───────────────

	public function test_version_within_range_returns_available_no_notices(): void {
		$module = new VersionBounds_InRange_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	public function test_version_exactly_at_min_returns_available(): void {
		$module = new VersionBounds_AtMin_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	public function test_version_exactly_at_tested_returns_available_no_warning(): void {
		$module = new VersionBounds_AtTested_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── check_dependency() with version < MIN ────────────

	public function test_version_below_min_returns_unavailable_with_error(): void {
		$module = new VersionBounds_BelowMin_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertFalse( $status['available'] );
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'error', $status['notices'][0]['type'] );
		$this->assertStringContainsString( '2.5', $status['notices'][0]['message'] );
		$this->assertStringContainsString( '3.0', $status['notices'][0]['message'] );
	}

	// ─── check_dependency() with version > TESTED_UP_TO ───

	public function test_version_above_tested_returns_available_with_warning(): void {
		$module = new VersionBounds_AboveTested_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
		$this->assertStringContainsString( '6.0', $status['notices'][0]['message'] );
		$this->assertStringContainsString( '5.0', $status['notices'][0]['message'] );
	}

	// ─── Patch-version normalization ──────────────────────

	public function test_patch_version_within_tested_minor_does_not_warn(): void {
		// '10.5.0' should be within '10.5' range (no warning).
		$module = new VersionBounds_PatchOK_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	public function test_patch_version_above_tested_minor_warns(): void {
		// '10.6.0' is above '10.5' range (should warn).
		$module = new VersionBounds_PatchAbove_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
	}

	public function test_high_patch_version_within_tested_does_not_warn(): void {
		// '1.11.99' should be within '1.11' range.
		$module = new VersionBounds_HighPatch_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Empty version / bounds skip ──────────────────────

	public function test_empty_plugin_version_skips_all_checks(): void {
		$module = new VersionBounds_EmptyVersion_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	public function test_empty_min_version_skips_min_check(): void {
		$module = new VersionBounds_EmptyMin_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	public function test_empty_tested_up_to_skips_tested_check(): void {
		$module = new VersionBounds_EmptyTested_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Table existence checks ──────────────────────────

	public function test_missing_tables_returns_unavailable(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		// get_var returns null → table does not exist.
		$wpdb->get_var_return = null;

		$module = new VersionBounds_WithTables_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertFalse( $status['available'] );
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
		$this->assertStringContainsString( 'fake_table', $status['notices'][0]['message'] );
	}

	public function test_existing_tables_returns_available(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		// get_var returns the table name → table exists.
		$wpdb->get_var_return = 'wp_fake_table';

		$module = new VersionBounds_WithTables_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_no_required_tables_skips_check(): void {
		$module = new VersionBounds_NoVersion_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	// ─── Cron polling notice ─────────────────────────────

	public function test_cron_polling_module_shows_info_notice(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$module = new VersionBounds_CronPolling_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertTrue( $status['available'] );

		$info_notices = array_filter( $status['notices'], fn( $n ) => 'info' === $n['type'] );
		$this->assertNotEmpty( $info_notices );
		$this->assertStringContainsString( 'system cron', array_values( $info_notices )[0]['message'] );
	}

	public function test_non_polling_module_has_no_cron_notice(): void {
		$module = new VersionBounds_NoVersion_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$info_notices = array_filter( $status['notices'], fn( $n ) => 'info' === $n['type'] );
		$this->assertEmpty( $info_notices );
	}

	// ─── Plugin not available ─────────────────────────────

	public function test_plugin_not_available_returns_unavailable(): void {
		$module = new VersionBounds_Unavailable_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$status = $module->get_dependency_status();
		$this->assertFalse( $status['available'] );
		$this->assertNotEmpty( $status['notices'] );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
	}

	// ─── Module_Registry dependency gating ────────────────

	public function test_registry_does_not_boot_unavailable_module(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_vb_old_enabled'] = true;

		$registry = new Module_Registry( \WP4Odoo_Plugin::instance(), wp4odoo_test_settings() );
		$module   = new VersionBounds_BelowMin_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$registry->register( 'vb_old', $module );
		$this->assertSame( 0, $registry->get_booted_count() );
	}

	public function test_registry_boots_module_with_untested_version(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_vb_new_enabled'] = true;

		$registry = new Module_Registry( \WP4Odoo_Plugin::instance(), wp4odoo_test_settings() );
		$module   = new VersionBounds_AboveTested_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$registry->register( 'vb_new', $module );
		$this->assertSame( 1, $registry->get_booted_count() );
	}

	public function test_registry_collects_version_warnings(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_vb_warn_enabled'] = true;

		$registry = new Module_Registry( \WP4Odoo_Plugin::instance(), wp4odoo_test_settings() );
		$module   = new VersionBounds_AboveTested_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$registry->register( 'vb_warn', $module );
		$warnings = $registry->get_version_warnings();
		$this->assertArrayHasKey( 'vb_warn', $warnings );
		$this->assertSame( 'warning', $warnings['vb_warn'][0]['type'] );
	}

	public function test_registry_no_warnings_when_version_in_range(): void {
		$GLOBALS['_wp_options']['wp4odoo_module_vb_ok_enabled'] = true;

		$registry = new Module_Registry( \WP4Odoo_Plugin::instance(), wp4odoo_test_settings() );
		$module   = new VersionBounds_InRange_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );

		$registry->register( 'vb_ok', $module );
		$this->assertSame( 1, $registry->get_booted_count() );
		$this->assertEmpty( $registry->get_version_warnings() );
	}
}

// ─── Test stub modules ──────────────────────────────────────────
//
// Each stub hardcodes its version constants. This avoids the PHP
// limitation that class constants cannot be set dynamically.
//
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Module with no version bounds (default Module_Base behavior).
 */
class VersionBounds_NoVersion_Module extends Module_Base {
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_noversion', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
}

/** Version in range: MIN=2.0, TESTED=5.0, detected=3.5. */
class VersionBounds_InRange_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '5.0';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_inrange', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '3.5'; }
}

/** At exact MIN boundary: MIN=2.0, detected=2.0. */
class VersionBounds_AtMin_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '5.0';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_atmin', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '2.0'; }
}

/** At exact TESTED boundary: TESTED=5.0, detected=5.0. */
class VersionBounds_AtTested_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '5.0';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_attested', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '5.0'; }
}

/** Below MIN: MIN=3.0, detected=2.5 → unavailable + error. */
class VersionBounds_BelowMin_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '3.0';
	protected const PLUGIN_TESTED_UP_TO = '5.0';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_belowmin', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '2.5'; }
}

/** Above TESTED: TESTED=5.0, detected=6.0 → available + warning. */
class VersionBounds_AboveTested_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '5.0';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_abovetested', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '6.0'; }
}

/** Patch OK: TESTED=10.5, detected=10.5.0 → no warning. */
class VersionBounds_PatchOK_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '7.0';
	protected const PLUGIN_TESTED_UP_TO = '10.5';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_patchok', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '10.5.0'; }
}

/** Patch above: TESTED=10.5, detected=10.6.0 → warning. */
class VersionBounds_PatchAbove_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '7.0';
	protected const PLUGIN_TESTED_UP_TO = '10.5';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_patchabove', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '10.6.0'; }
}

/** High patch: TESTED=1.11, detected=1.11.99 → no warning. */
class VersionBounds_HighPatch_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '1.0';
	protected const PLUGIN_TESTED_UP_TO = '1.11';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_highpatch', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '1.11.99'; }
}

/** Bounds set but detected version empty → skip all checks. */
class VersionBounds_EmptyVersion_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '5.0';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_emptyver', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return ''; }
}

/** Empty MIN → skip MIN check (very old version should pass). */
class VersionBounds_EmptyMin_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '';
	protected const PLUGIN_TESTED_UP_TO = '5.0';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_emptymin', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '1.0'; }
}

/** Empty TESTED → skip TESTED check (very new version should pass). */
class VersionBounds_EmptyTested_Module extends Module_Base {
	protected const PLUGIN_MIN_VERSION  = '2.0';
	protected const PLUGIN_TESTED_UP_TO = '';
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_emptytested', 'TestPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TestPlugin' );
	}
	protected function get_plugin_version(): string { return '99.0'; }
}

/** Plugin not available → unavailable. */
class VersionBounds_Unavailable_Module extends Module_Base {
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_unavailable', 'MissingPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( false, 'MissingPlugin' );
	}
}

/** Module with required tables. */
class VersionBounds_WithTables_Module extends Module_Base {
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_tables', 'TablePlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'TablePlugin' );
	}
	protected function get_required_tables(): array {
		return [ 'fake_table' ];
	}
}

/** Module with cron polling. */
class VersionBounds_CronPolling_Module extends Module_Base {
	public function __construct( \Closure $cp, \WP4Odoo\Entity_Map_Repository $em, \WP4Odoo\Settings_Repository $s ) {
		parent::__construct( 'vb_cron', 'CronPlugin', $cp, $em, $s );
	}
	public function boot(): void {}
	public function get_default_settings(): array { return []; }
	public function get_dependency_status(): array {
		return $this->check_dependency( true, 'CronPlugin' );
	}
	protected function uses_cron_polling(): bool {
		return true;
	}
}

// phpcs:enable
