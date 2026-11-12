<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\Database_Migration;

/**
 * Runs the real uninstall.php against a seeded database.
 *
 * Checks that everything the plugin created is removed and that unrelated
 * site data is left alone.
 */
class UninstallTest extends WP4Odoo_TestCase {

	public function set_up(): void {
		parent::set_up();
		// WP_UnitTestCase rewrites CREATE/DROP TABLE into TEMPORARY variants; uninstall must act on real tables.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	public function tear_down(): void {
		global $wpdb;

		// The test dropped the tables (DDL cannot be rolled back) and DDL ended the test's transaction.
		$wpdb->query( 'COMMIT' );
		// Re-running the migrations inside the framework's transaction logs harmless SAVEPOINT errors.
		$wpdb->hide_errors();
		$previous = $wpdb->suppress_errors( true );
		Database_Migration::create_tables();
		$wpdb->suppress_errors( $previous );
		$wpdb->show_errors();
		parent::tear_down();
	}

	private function run_uninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wp4odoo/wp4odoo.php' );
		}

		include dirname( __DIR__, 2 ) . '/uninstall.php';

		// uninstall.php deletes with direct SQL; reread everything from the database.
		wp_cache_flush();
	}

	private function option_exists( string $name ): bool {
		global $wpdb;

		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}

	public function test_uninstall_removes_everything_the_plugin_created(): void {
		global $wpdb;

		// Options (static and dynamically named), transients, cron.
		update_option( 'wp4odoo_connection', [ 'url' => 'https://x' ] );
		update_option( 'wp4odoo_module_crm_settings', [ 'a' => 1 ] );
		update_option( 'wp4odoo_survey_12', [ 'q' => 1 ], false );
		set_transient( 'wp4odoo_tables_ok', '1', HOUR_IN_SECONDS );
		set_transient( 'wp4odoo_rl_abc', 3, HOUR_IN_SECONDS );
		set_site_transient( 'wp4odoo_site_flag', '1', HOUR_IN_SECONDS );
		wp_schedule_event( time() + 60, 'hourly', 'wp4odoo_scheduled_sync' );
		wp_schedule_event( time() + 60, 'daily', 'wp4odoo_log_cleanup' );

		// Content: the plugin's own CPT posts and meta it added to other content.
		$lead_id    = self::factory()->post->create( [ 'post_type' => 'wp4odoo_lead', 'post_status' => 'publish' ] );
		$task_id    = self::factory()->post->create( [ 'post_type' => 'wp4odoo_fs_task', 'post_status' => 'publish' ] );
		$product_id = self::factory()->post->create( [ 'post_type' => 'post', 'post_title' => 'Site content' ] );
		update_post_meta( $product_id, '_wp4odoo_image_hash', 'abc' );
		update_post_meta( $product_id, '_wp4odoo_pricelist_price', '9.9' );
		update_post_meta( $product_id, 'unrelated_meta', 'keep' );
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, 'wp4odoo_flag', '1' );
		update_user_meta( $user_id, 'billing_phone', 'keep' );

		// Unrelated site data that must survive.
		update_option( 'blogname_backup', 'keep me' );
		update_option( 'other_plugin_wp4odoo', 'not ours: suffix only' );
		set_transient( 'other_transient', '1', HOUR_IN_SECONDS );

		$this->assertSame( [], Database_Migration::missing_tables() );

		$this->run_uninstall();

		// Tables.
		$this->assertCount( 3, Database_Migration::missing_tables(), 'All three tables are dropped.' );

		// Options and transients.
		foreach ( [ 'wp4odoo_connection', 'wp4odoo_module_crm_settings', 'wp4odoo_survey_12', '_transient_wp4odoo_tables_ok', '_transient_timeout_wp4odoo_tables_ok', '_transient_wp4odoo_rl_abc' ] as $name ) {
			$this->assertFalse( $this->option_exists( $name ), "$name must be removed" );
		}
		$this->assertFalse( get_site_transient( 'wp4odoo_site_flag' ) );
		$this->assertFalse( wp_next_scheduled( 'wp4odoo_scheduled_sync' ) );
		$this->assertFalse( wp_next_scheduled( 'wp4odoo_log_cleanup' ) );

		// Own CPT posts and added meta.
		$this->assertNull( get_post( $lead_id ) );
		$this->assertNull( get_post( $task_id ) );
		$this->assertSame( '', get_post_meta( $product_id, '_wp4odoo_image_hash', true ) );
		$this->assertSame( '', get_post_meta( $product_id, '_wp4odoo_pricelist_price', true ) );
		$this->assertSame( '', get_user_meta( $user_id, 'wp4odoo_flag', true ) );

		// Site data is untouched.
		$this->assertNotNull( get_post( $product_id ), 'Site content is kept.' );
		$this->assertSame( 'keep', get_post_meta( $product_id, 'unrelated_meta', true ) );
		$this->assertSame( 'keep', get_user_meta( $user_id, 'billing_phone', true ) );
		$this->assertSame( 'keep me', get_option( 'blogname_backup' ) );
		$this->assertSame( 'not ours: suffix only', get_option( 'other_plugin_wp4odoo' ) );
		$this->assertSame( '1', get_transient( 'other_transient' ) );
	}

	public function test_uninstall_is_safe_to_run_twice(): void {
		$this->run_uninstall();
		$this->run_uninstall();

		$this->assertCount( 3, Database_Migration::missing_tables() );
	}
}
