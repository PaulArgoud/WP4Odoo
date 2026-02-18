<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Validates the module health manifest against the test environment stubs.
 *
 * Ensures all declared third-party symbols (classes, functions, constants)
 * exist in the stub-loaded test environment, and that the manifest covers
 * all registered modules.
 *
 * @package WP4Odoo\Tests\Unit
 * @since   3.6.0
 */
class ModuleHealthManifestTest extends TestCase {

	/**
	 * Manifest data.
	 *
	 * @var array<string, array{classes?: string[], functions?: string[], constants?: string[]}>
	 */
	private static array $manifest;

	public static function setUpBeforeClass(): void {
		self::$manifest = require dirname( __DIR__ ) . '/module-health-manifest.php';
	}

	public function test_manifest_is_non_empty_array(): void {
		$this->assertNotEmpty( self::$manifest );
		$this->assertIsArray( self::$manifest );
	}

	/**
	 * @dataProvider functionProvider
	 */
	public function test_manifest_function_exists( string $module_id, string $function_name ): void {
		$this->assertTrue(
			function_exists( $function_name ),
			"Module '{$module_id}' declares function '{$function_name}' but it is not defined in stubs."
		);
	}

	/**
	 * @dataProvider classProvider
	 */
	public function test_manifest_class_exists( string $module_id, string $class_name ): void {
		$this->assertTrue(
			class_exists( $class_name ),
			"Module '{$module_id}' declares class '{$class_name}' but it is not defined in stubs."
		);
	}

	/**
	 * @dataProvider constantProvider
	 */
	public function test_manifest_constant_defined( string $module_id, string $constant_name ): void {
		$this->assertTrue(
			defined( $constant_name ),
			"Module '{$module_id}' declares constant '{$constant_name}' but it is not defined in stubs."
		);
	}

	public function test_manifest_covers_all_registry_modules(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$registry = new \WP4Odoo\Module_Registry( \WP4Odoo_Plugin::instance(), wp4odoo_test_settings() );
		$registry->register_all();

		$registered_ids = array_keys( $registry->all() );
		$manifest_ids   = array_keys( self::$manifest );

		$missing = array_diff( $registered_ids, $manifest_ids );
		$this->assertEmpty(
			$missing,
			'Modules registered but missing from health manifest: ' . implode( ', ', $missing )
		);

		\WP4Odoo_Plugin::reset_instance();
	}

	public function test_no_orphan_manifest_entries(): void {
		// Map module IDs whose file name differs from the simple slug convention.
		$slug_overrides = [
			'wpai' => 'wp-all-import',
		];

		$modules_dir  = dirname( __DIR__, 2 ) . '/includes/modules';
		$manifest_ids = array_keys( self::$manifest );
		$orphans      = [];

		foreach ( $manifest_ids as $id ) {
			$slug = $slug_overrides[ $id ] ?? str_replace( '_', '-', $id );
			$file = $modules_dir . '/class-' . $slug . '-module.php';
			if ( ! file_exists( $file ) ) {
				$orphans[] = $id;
			}
		}

		$this->assertEmpty(
			$orphans,
			'Manifest entries with no matching module file: ' . implode( ', ', $orphans )
		);
	}

	// ─── Data Providers ──────────────────────────────────

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function functionProvider(): array {
		$manifest = require dirname( __DIR__ ) . '/module-health-manifest.php';
		$cases    = [];
		foreach ( $manifest as $module_id => $symbols ) {
			foreach ( $symbols['functions'] ?? [] as $fn ) {
				$cases["{$module_id}::{$fn}"] = [ $module_id, $fn ];
			}
		}
		return $cases;
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function classProvider(): array {
		$manifest = require dirname( __DIR__ ) . '/module-health-manifest.php';
		$cases    = [];
		foreach ( $manifest as $module_id => $symbols ) {
			foreach ( $symbols['classes'] ?? [] as $class ) {
				$cases["{$module_id}::{$class}"] = [ $module_id, $class ];
			}
		}
		return $cases;
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function constantProvider(): array {
		$manifest = require dirname( __DIR__ ) . '/module-health-manifest.php';
		$cases    = [];
		foreach ( $manifest as $module_id => $symbols ) {
			foreach ( $symbols['constants'] ?? [] as $const ) {
				$cases["{$module_id}::{$const}"] = [ $module_id, $const ];
			}
		}
		return $cases;
	}
}
