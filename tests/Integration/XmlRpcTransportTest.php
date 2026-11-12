<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Integration;

use WP4Odoo\API\Odoo_XmlRPC;

/**
 * XML-RPC transport fault handling, parsed by WordPress' real IXR classes.
 */
class XmlRpcTransportTest extends WP4Odoo_TestCase {

	/** @var array<int, string> Queued XML response bodies. */
	private array $queue = [];

	public function set_up(): void {
		parent::set_up();
		$this->queue = [];
		add_filter( 'wp4odoo_enable_connection_pool', '__return_false' );
		add_filter(
			'pre_http_request',
			function () {
				return [
					'headers'  => [],
					'body'     => array_shift( $this->queue ),
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			}
		);
	}

	private function response( string $inner ): string {
		return '<?xml version="1.0"?><methodResponse>' . $inner . '</methodResponse>';
	}

	private function fault( string $message ): string {
		return $this->response(
			'<fault><value><struct><member><name>faultCode</name><value><int>1</int></value></member>'
			. '<member><name>faultString</name><value><string>' . $message . '</string></value></member></struct></value></fault>'
		);
	}

	private function authenticated(): Odoo_XmlRPC {
		$this->queue[] = $this->response( '<params><param><value><int>7</int></value></param></params>' );
		$this->queue[] = $this->response( '<params><param><value><struct><member><name>server_version</name><value><string>19.0</string></value></member></struct></value></param></params>' );

		$t = new Odoo_XmlRPC( 'https://odoo.example.com', 'db', 'secret', 10 );
		$t->authenticate( 'admin' );
		return $t;
	}

	public function test_none_result_that_odoo_cannot_marshal_is_a_success(): void {
		$t             = $this->authenticated();
		$this->queue[] = $this->fault( 'Traceback ... TypeError: cannot marshal None unless allow_none is enabled' );

		$this->assertNull( $t->execute_kw( 'stock.quant', 'action_apply_inventory', [ [ 9 ] ] ) );
	}

	public function test_other_faults_still_throw(): void {
		$t             = $this->authenticated();
		$this->queue[] = $this->fault( 'Record does not exist or has been deleted.' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Record does not exist' );
		$t->execute_kw( 'stock.quant', 'read', [ [ 99999 ] ] );
	}

	public function test_normal_results_are_returned(): void {
		$t             = $this->authenticated();
		$this->queue[] = $this->response( '<params><param><value><int>42</int></value></param></params>' );

		$this->assertSame( 42, $t->execute_kw( 'res.partner', 'create', [ [ 'name' => 'X' ] ] ) );
	}
}
