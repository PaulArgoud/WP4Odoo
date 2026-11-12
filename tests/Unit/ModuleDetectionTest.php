<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP4Odoo\Module_Base;
use WP4Odoo\Module_Registry;

/**
 * Verifies the single detection point shared by the registry and the dependency checks.
 */
#[CoversClass( Module_Base::class )]
#[CoversClass( Module_Registry::class )]
class ModuleDetectionTest extends TestCase {

	/**
	 * Concrete module classes keyed by module ID, read from the registry source.
	 *
	 * @return array<string, array{0: string, 1: class-string<Module_Base>}>
	 */
	public static function moduleProvider(): array {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-module-registry.php' );
		preg_match_all( "/\[ '(\w+)', Modules\\\\(\w+)::class \]/", (string) $src, $m, PREG_SET_ORDER );

		$out = [];
		foreach ( $m as $row ) {
			$out[ $row[1] ] = [ $row[1], 'WP4Odoo\\Modules\\' . $row[2] ];
		}
		return $out;
	}

	public function test_registry_lists_every_module(): void {
		// 71 registry entries + the always-registered core CRM module.
		$this->assertCount( 71, self::moduleProvider() );
	}

	#[DataProvider( 'moduleProvider' )]
	public function test_module_detect_is_static_and_boolean( string $id, string $class ): void {
		$this->assertTrue( class_exists( $class ), "Missing class for module '{$id}'." );
		$this->assertIsBool( $class::detect() );
	}

	/**
	 * Modules whose plugin is deliberately absent from the stub environment.
	 *
	 * Their module tests rely on the plugin's version constant being undefined to
	 * exercise the "plugin not active" path, so the stubs do not define it.
	 */
	private const ABSENT_IN_STUBS = [ 'pmpro', 'learndash', 'lifterlms' ];

	#[DataProvider( 'moduleProvider' )]
	public function test_module_detection_matches_the_stub_environment( string $id, string $class ): void {
		if ( in_array( $id, self::ABSENT_IN_STUBS, true ) ) {
			$this->assertFalse( $class::detect(), "Module '{$id}' should not be detected in the stub environment." );
			return;
		}

		// The test stubs define every other third-party symbol the modules look for.
		$this->assertTrue( $class::detect(), "Module '{$id}' is not detected although its stubs are loaded." );
	}

	public function test_registry_has_no_inline_detection_closures(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-module-registry.php' );

		$this->assertStringNotContainsString( 'fn() => defined(', $src );
		$this->assertStringContainsString( '$class::detect()', $src );
	}

	public function test_default_detection_is_true_for_modules_without_plugin_dependency(): void {
		$this->assertTrue( \WP4Odoo\Modules\Sales_Module::detect() );
		$this->assertTrue( \WP4Odoo\Modules\Knowledge_Module::detect() );
		$this->assertTrue( \WP4Odoo\Modules\Field_Service_Module::detect() );
	}
}
