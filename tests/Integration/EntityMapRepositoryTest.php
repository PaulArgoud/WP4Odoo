<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\Entity_Map_Repository;

/**
 * Integration tests for Entity_Map_Repository.
 *
 * Validates real MySQL CRUD operations (save, lookup, batch, remove)
 * against the wp4odoo_entity_map table.
 *
 * @package WP4Odoo\Tests\Integration
 */
class EntityMapRepositoryTest extends WP4Odoo_TestCase {

	protected function setUp(): void {
		parent::setUp();
		Entity_Map_Repository::flush_cache();
	}

	protected function tearDown(): void {
		Entity_Map_Repository::flush_cache();
		parent::tearDown();
	}

	// ─── save + get_odoo_id ────────────────────────────────

	public function test_save_and_get_odoo_id(): void {
		Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner' );

		Entity_Map_Repository::flush_cache();

		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );
		$this->assertSame( 42, $result );
	}

	public function test_save_and_get_wp_id(): void {
		Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner' );

		Entity_Map_Repository::flush_cache();

		$result = Entity_Map_Repository::get_wp_id( 'crm', 'contact', 42 );
		$this->assertSame( 10, $result );
	}

	public function test_get_odoo_id_returns_null_when_not_found(): void {
		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 99999 );
		$this->assertNull( $result );
	}

	// ─── REPLACE INTO behavior ─────────────────────────────

	public function test_save_overwrites_existing_mapping(): void {
		Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner', 'hash_v1' );
		Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner', 'hash_v2' );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wp4odoo_entity_map WHERE module = %s AND entity_type = %s AND wp_id = %d",
				'crm',
				'contact',
				10
			)
		);

		$this->assertSame( '1', $count );
	}

	// ─── remove ────────────────────────────────────────────

	public function test_remove_deletes_mapping(): void {
		Entity_Map_Repository::save( 'crm', 'contact', 10, 42, 'res.partner' );
		Entity_Map_Repository::remove( 'crm', 'contact', 10 );

		Entity_Map_Repository::flush_cache();

		$result = Entity_Map_Repository::get_odoo_id( 'crm', 'contact', 10 );
		$this->assertNull( $result );
	}

	// ─── Batch methods ─────────────────────────────────────

	public function test_get_wp_ids_batch(): void {
		Entity_Map_Repository::save( 'woocommerce', 'product', 1, 101, 'product.template' );
		Entity_Map_Repository::save( 'woocommerce', 'product', 2, 102, 'product.template' );
		Entity_Map_Repository::save( 'woocommerce', 'product', 3, 103, 'product.template' );

		Entity_Map_Repository::flush_cache();

		$map = Entity_Map_Repository::get_wp_ids_batch( 'woocommerce', 'product', [ 101, 103, 999 ] );

		$this->assertSame( 1, $map[101] );
		$this->assertSame( 3, $map[103] );
		$this->assertArrayNotHasKey( 999, $map );
	}

	public function test_get_odoo_ids_batch(): void {
		Entity_Map_Repository::save( 'woocommerce', 'product', 1, 101, 'product.template' );
		Entity_Map_Repository::save( 'woocommerce', 'product', 2, 102, 'product.template' );

		Entity_Map_Repository::flush_cache();

		$map = Entity_Map_Repository::get_odoo_ids_batch( 'woocommerce', 'product', [ 1, 2, 999 ] );

		$this->assertSame( 101, $map[1] );
		$this->assertSame( 102, $map[2] );
		$this->assertArrayNotHasKey( 999, $map );
	}
}
