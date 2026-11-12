<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\ApiSurface;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * API Surface Tests — verifies third-party plugin APIs still exist.
 *
 * Downloads plugin source from WordPress.org, scans PHP files, and asserts
 * that classes, functions, constants, and hooks WP4Odoo depends on are present.
 *
 * Runs in CI via: php vendor/bin/phpunit -c phpunit-api-surface.xml
 * Requires: WP4ODOO_PLUGIN_DIR env var pointing to extracted plugins.
 */
class PluginContractTest extends TestCase {

	/** @var array<string, PluginSourceIndex> */
	private static array $indices = [];

	/** @var array<string, array> */
	private static array $contracts = [];

	/** @var string */
	private static string $plugin_dir = '';

	public static function setUpBeforeClass(): void {
		self::$plugin_dir = getenv( 'WP4ODOO_PLUGIN_DIR' ) ?: '/tmp/wp-plugins';
		self::$contracts  = require __DIR__ . '/contracts.php';

		foreach ( self::$contracts as $key => $contract ) {
			$dir = self::$plugin_dir . '/' . $contract['dir'];
			if ( is_dir( $dir ) ) {
				self::$indices[ $key ] = PluginSourceIndex::build( $dir );
			}
		}
	}

	#[DataProvider( 'classProvider' )]
	public function test_class_exists_in_plugin( string $plugin_key, string $class ): void {
		$this->assert_plugin_available( $plugin_key );
		$index = self::$indices[ $plugin_key ];

		$this->assertTrue(
			$index->has_class( $class ),
			sprintf(
				'Class "%s" not found in %s source. WP4Odoo module "%s" depends on it.',
				$class,
				$plugin_key,
				self::$contracts[ $plugin_key ]['module']
			)
		);
	}

	#[DataProvider( 'functionProvider' )]
	public function test_function_exists_in_plugin( string $plugin_key, string $function ): void {
		$this->assert_plugin_available( $plugin_key );
		$index = self::$indices[ $plugin_key ];

		$this->assertTrue(
			$index->has_function( $function ),
			sprintf(
				'Function "%s" not found in %s source. WP4Odoo module "%s" depends on it.',
				$function,
				$plugin_key,
				self::$contracts[ $plugin_key ]['module']
			)
		);
	}

	#[DataProvider( 'constantProvider' )]
	public function test_constant_defined_in_plugin( string $plugin_key, string $constant ): void {
		$this->assert_plugin_available( $plugin_key );
		$index = self::$indices[ $plugin_key ];

		$this->assertTrue(
			$index->has_constant( $constant ),
			sprintf(
				'Constant "%s" not found in %s source. WP4Odoo module "%s" depends on it.',
				$constant,
				$plugin_key,
				self::$contracts[ $plugin_key ]['module']
			)
		);
	}

	#[DataProvider( 'actionProvider' )]
	public function test_action_fired_in_plugin( string $plugin_key, string $action ): void {
		$this->assert_plugin_available( $plugin_key );
		$index = self::$indices[ $plugin_key ];

		$this->assertTrue(
			$index->has_action( $action ),
			sprintf(
				'Action "%s" not fired (do_action) in %s source. WP4Odoo module "%s" listens to it.',
				$action,
				$plugin_key,
				self::$contracts[ $plugin_key ]['module']
			)
		);
	}

	// ─── Data Providers ─────────────────────────────────────

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function classProvider(): array {
		return self::build_provider( 'classes' );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function functionProvider(): array {
		return self::build_provider( 'functions' );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function constantProvider(): array {
		return self::build_provider( 'constants' );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function actionProvider(): array {
		return self::build_provider( 'actions' );
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Build a data provider from a specific contract field.
	 *
	 * @param string $field Contract field name (classes, functions, constants, actions).
	 * @return array<string, array{string, string}>
	 */
	private static function build_provider( string $field ): array {
		$contracts = require __DIR__ . '/contracts.php';
		$cases     = [];

		foreach ( $contracts as $key => $contract ) {
			foreach ( $contract[ $field ] as $item ) {
				$label           = "{$key}::{$item}";
				$cases[ $label ] = [ $key, $item ];
			}
		}

		return $cases;
	}

	/**
	 * Skip test if plugin source is not available.
	 *
	 * @param string $plugin_key Contract key.
	 */
	private function assert_plugin_available( string $plugin_key ): void {
		if ( ! isset( self::$indices[ $plugin_key ] ) ) {
			$slug = self::$contracts[ $plugin_key ]['slug'] ?? $plugin_key;
			$this->markTestSkipped(
				sprintf(
					'Plugin "%s" not downloaded. Set WP4ODOO_PLUGIN_DIR or run the download script.',
					$slug
				)
			);
		}
	}
}
