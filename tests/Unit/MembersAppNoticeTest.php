<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use WP4Odoo\API\Odoo_Client;
use WP4Odoo\Modules\Memberships_Module;
use WP4Odoo\Modules\MemberPress_Module;
use WP4Odoo\Modules\PMPro_Module;
use WP4Odoo\Modules\RCP_Module;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Membership modules warn when the Odoo Members app does not exist (removed in recent Odoo versions).
 */
#[CoversClass( \WP4Odoo\Modules\Membership_Module_Base::class )]
class MembersAppNoticeTest extends Module_Test_Case {

	private MockTransport $transport;

	protected function setUp(): void {
		parent::setUp();
		$this->transport           = new MockTransport();
		$GLOBALS['_wp_transients'] = [];
		$GLOBALS['_wp_options']['wp4odoo_connection'] = [ 'url' => 'https://odoo.example.com', 'database' => 'd', 'username' => 'u', 'api_key' => '', 'protocol' => 'jsonrpc', 'timeout' => 5 ];
	}

	private function provider(): \Closure {
		$transport = $this->transport;

		return function () use ( $transport ): Odoo_Client {
			$client = new Odoo_Client();
			$ref    = new \ReflectionClass( $client );
			$ref->getProperty( 'transport' )->setValue( $client, $transport );
			$ref->getProperty( 'connected' )->setValue( $client, true );
			return $client;
		};
	}

	/** @return array<string, array{0: class-string}> */
	public static function moduleProvider(): array {
		return [
			'MemberPress'            => [ MemberPress_Module::class ],
			'Paid Memberships Pro'   => [ PMPro_Module::class ],
			'Restrict Content Pro'   => [ RCP_Module::class ],
			'WooCommerce Memberships' => [ Memberships_Module::class ],
		];
	}

	private function members_status( string $class ): array {
		$instance = new $class( $this->provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
		$method   = new \ReflectionMethod( $instance, 'warn_if_odoo_model_missing' );

		return $method->invoke(
			$instance,
			[ 'available' => true, 'notices' => [] ],
			'membership.membership_line',
			'wp4odoo_has_membership_line',
			'Members app missing'
		);
	}

	#[DataProvider( 'moduleProvider' )]
	public function test_warns_when_the_members_app_is_missing( string $class ): void {
		$this->transport->return_value = 0;

		$status = $this->members_status( $class );

		$this->assertTrue( $status['available'], 'The module stays available: its other entities still work.' );
		$this->assertSame( 'warning', $status['notices'][0]['type'] );
	}

	#[DataProvider( 'moduleProvider' )]
	public function test_no_warning_when_the_members_app_exists( string $class ): void {
		$this->transport->return_value = 1;

		$this->assertSame( [], $this->members_status( $class )['notices'] );
	}

	public function test_no_warning_and_nothing_cached_when_odoo_is_unreachable(): void {
		$this->transport->throw = new \RuntimeException( 'timeout' );

		$this->assertSame( [], $this->members_status( PMPro_Module::class )['notices'] );
		$this->assertFalse( get_transient( 'wp4odoo_has_membership_line' ) );
	}

	public function test_no_probe_without_a_configured_connection(): void {
		$GLOBALS['_wp_options']['wp4odoo_connection'] = [];
		$this->transport->return_value                = 0;

		$this->assertSame( [], $this->members_status( PMPro_Module::class )['notices'] );
		$this->assertSame( [], $this->transport->calls );
	}

	public function test_the_answer_is_cached_for_an_hour(): void {
		$this->transport->return_value = 0;
		$this->members_status( PMPro_Module::class );
		$this->members_status( PMPro_Module::class );

		$this->assertCount( 1, $this->transport->calls );
	}

	public function test_get_dependency_status_wires_the_check_into_each_module(): void {
		foreach ( [ PMPro_Module::class, MemberPress_Module::class, RCP_Module::class, Memberships_Module::class ] as $class ) {
			$source = (string) file_get_contents( ( new \ReflectionClass( $class ) )->getFileName() );
			$this->assertStringContainsString( "'membership.membership_line'", $source, $class );
		}
	}
}
