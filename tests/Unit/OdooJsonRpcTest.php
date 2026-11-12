<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WP4Odoo\API\Odoo_JsonRPC;

/**
 * Unit tests for the JSON-RPC transport authentication.
 */
#[CoversClass( Odoo_JsonRPC::class )]
class OdooJsonRpcTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_wp_remote_responses'] = [];
		$GLOBALS['_wp_remote_response']  = null;
		$GLOBALS['_wp_remote_requests']  = [];
		$GLOBALS['_wp_filters']['wp4odoo_enable_connection_pool'] = static fn() => false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_filters']['wp4odoo_enable_connection_pool'] );
		$GLOBALS['_wp_remote_responses'] = [];
		$GLOBALS['_wp_remote_requests']  = [];
	}

	private function rpc_result( mixed $result ): array {
		return [
			'body'     => json_encode( [ 'jsonrpc' => '2.0', 'id' => 'x', 'result' => $result ] ),
			'response' => [ 'code' => 200 ],
		];
	}

	private function error( string $message ): array {
		return [
			'body'     => json_encode( [ 'jsonrpc' => '2.0', 'id' => 'x', 'error' => [ 'code' => 200, 'message' => 'Odoo Server Error', 'data' => [ 'message' => $message ] ] ] ),
			'response' => [ 'code' => 200 ],
		];
	}

	private function transport(): Odoo_JsonRPC {
		return new Odoo_JsonRPC( 'https://odoo.example.com', 'mydb', 'api-key-123', 10 );
	}

	private function request_body( int $index ): array {
		return json_decode( $GLOBALS['_wp_remote_requests'][ $index ]['args']['body'], true );
	}

	/** GitHub issues #2 and #4: an API key is rejected by /web/session/authenticate. */
	public function test_api_key_authenticates_through_the_common_service(): void {
		$GLOBALS['_wp_remote_responses'][] = $this->rpc_result( 7 );
		$GLOBALS['_wp_remote_responses'][] = $this->rpc_result( [ 'server_version' => '19.2+e' ] );

		$t = $this->transport();

		$this->assertSame( 7, $t->authenticate( 'admin' ) );
		$this->assertSame( '19.2+e', $t->get_server_version() );

		$first = $GLOBALS['_wp_remote_requests'][0];
		$this->assertStringEndsWith( '/jsonrpc', $first['url'] );
		$params = $this->request_body( 0 )['params'];
		$this->assertSame( 'common', $params['service'] );
		$this->assertSame( 'authenticate', $params['method'] );
		$this->assertSame( [ 'mydb', 'admin', 'api-key-123' ], array_slice( $params['args'], 0, 3 ) );
		$this->assertCount( 2, $GLOBALS['_wp_remote_requests'], 'No session login is attempted when common works.' );
	}

	public function test_rejected_credentials_fail_without_falling_back(): void {
		$GLOBALS['_wp_remote_responses'][] = $this->rpc_result( false );

		try {
			$this->transport()->authenticate( 'admin' );
			$this->fail( 'Expected exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Authentication failed', $e->getMessage() );
		}

		$this->assertCount( 1, $GLOBALS['_wp_remote_requests'] );
	}

	public function test_falls_back_to_session_login_when_common_is_unavailable(): void {
		$GLOBALS['_wp_remote_responses'][] = $this->error( 'Service common is not available' );
		$GLOBALS['_wp_remote_responses'][] = $this->rpc_result( [ 'uid' => 9, 'server_version' => '22.0' ] );

		$t = $this->transport();

		$this->assertSame( 9, $t->authenticate( 'admin' ) );
		$this->assertSame( '22.0', $t->get_server_version() );
		$this->assertStringEndsWith( '/web/session/authenticate', $GLOBALS['_wp_remote_requests'][1]['url'] );
	}

	public function test_authenticated_calls_use_the_uid_and_key(): void {
		$GLOBALS['_wp_remote_responses'][] = $this->rpc_result( 7 );
		$GLOBALS['_wp_remote_responses'][] = $this->rpc_result( [ 'server_version' => '19.0' ] );
		$GLOBALS['_wp_remote_responses'][] = $this->rpc_result( [ 1, 2 ] );

		$t = $this->transport();
		$t->authenticate( 'admin' );
		$this->assertSame( [ 1, 2 ], $t->execute_kw( 'res.partner', 'search', [ [] ] ) );

		$args = $this->request_body( 2 )['params']['args'];
		$this->assertSame( [ 'mydb', 7, 'api-key-123', 'res.partner', 'search' ], array_slice( $args, 0, 5 ) );
	}
}
