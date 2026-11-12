<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP4Odoo\API\Odoo_Json2;

/**
 * Unit tests for the JSON-2 transport.
 */
class OdooJson2Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_wp_remote_responses'] = [];
		$GLOBALS['_wp_remote_response']  = null;
		$GLOBALS['_wp_remote_requests']  = [];
		// Disable the cURL pool so requests go through the wp_remote_post() stub.
		$GLOBALS['_wp_filters']['wp4odoo_enable_connection_pool'] = static fn() => false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_filters']['wp4odoo_enable_connection_pool'] );
		$GLOBALS['_wp_remote_responses'] = [];
		$GLOBALS['_wp_remote_requests']  = [];
	}

	private function ok( mixed $body, int $code = 200 ): array {
		return [
			'body'     => json_encode( $body ),
			'response' => [ 'code' => $code ],
		];
	}

	private function transport(): Odoo_Json2 {
		return new Odoo_Json2( 'https://odoo.example.com', 'mydb', 'secret-key', 10 );
	}

	/** Authenticate a transport using queued responses (uid lookup + version probe). */
	private function authenticated(): Odoo_Json2 {
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'lang' => 'en_US', 'uid' => 7 ] );
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'jsonrpc' => '2.0', 'id' => 1, 'result' => [ 'server_version' => '19.0' ] ] );
		$t = $this->transport();
		$t->authenticate( 'admin' );
		$GLOBALS['_wp_remote_requests'] = [];
		return $t;
	}

	private function last_request(): array {
		return end( $GLOBALS['_wp_remote_requests'] );
	}

	public function test_authenticate_returns_uid_and_version(): void {
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'uid' => 7 ] );
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'jsonrpc' => '2.0', 'id' => 1, 'result' => [ 'server_version' => '20.0' ] ] );

		$t = $this->transport();

		$this->assertSame( 7, $t->authenticate( 'admin' ) );
		$this->assertSame( 7, $t->get_uid() );
		$this->assertSame( '20.0', $t->get_server_version() );
	}

	public function test_authenticate_sends_bearer_and_database_headers(): void {
		$this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'uid' => 7 ] );
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'jsonrpc' => '2.0', 'result' => [] ] );
		$this->transport()->authenticate( 'admin' );

		$first = $GLOBALS['_wp_remote_requests'][0];
		$this->assertSame( 'https://odoo.example.com/json/2/res.users/context_get', $first['url'] );
		$this->assertSame( 'bearer secret-key', $first['args']['headers']['Authorization'] );
		$this->assertSame( 'mydb', $first['args']['headers']['X-Odoo-Database'] );
	}

	public function test_authenticate_fails_on_401(): void {
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'message' => 'Invalid apikey' ], 401 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid apikey' );
		$this->transport()->authenticate( 'admin' );
	}

	public function test_execute_requires_authentication(): void {
		$this->expectException( \RuntimeException::class );
		$this->transport()->execute_kw( 'res.partner', 'search', [ [] ] );
	}

	public function test_search_read_maps_positional_args_and_kwargs(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ [ 'id' => 1 ] ] );

		$result = $t->execute_kw( 'res.partner', 'search_read', [ [ [ 'email', '=', 'a@b.c' ] ] ], [ 'fields' => [ 'id' ], 'limit' => 1 ] );

		$this->assertSame( [ [ 'id' => 1 ] ], $result );
		$req  = $this->last_request();
		$body = json_decode( $req['args']['body'], true );
		$this->assertSame( 'https://odoo.example.com/json/2/res.partner/search_read', $req['url'] );
		$this->assertSame( [ [ 'email', '=', 'a@b.c' ] ], $body['domain'] );
		$this->assertSame( [ 'id' ], $body['fields'] );
		$this->assertSame( 1, $body['limit'] );
	}

	public function test_create_single_dict_returns_scalar_id(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 42 ] );

		$id = $t->execute_kw( 'res.partner', 'create', [ [ 'name' => 'X' ] ] );

		$this->assertSame( 42, $id );
		$body = json_decode( $this->last_request()['args']['body'], true );
		$this->assertSame( [ [ 'name' => 'X' ] ], $body['vals_list'] );
	}

	public function test_create_batch_returns_list_of_ids(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 1, 2 ] );

		$ids = $t->execute_kw( 'res.partner', 'create', [ [ [ 'name' => 'A' ], [ 'name' => 'B' ] ] ] );

		$this->assertSame( [ 1, 2 ], $ids );
	}

	public function test_write_maps_ids_vals_and_context(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( true );

		$t->execute_kw( 'product.template', 'write', [ [ 5 ], [ 'name' => 'P' ] ], [ 'context' => [ 'lang' => 'fr_FR' ] ] );

		$body = json_decode( $this->last_request()['args']['body'], true );
		$this->assertSame( [ 5 ], $body['ids'] );
		$this->assertSame( [ 'name' => 'P' ], $body['vals'] );
		$this->assertSame( [ 'lang' => 'fr_FR' ], $body['context'] );
	}

	public function test_record_action_maps_to_ids(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( true );

		$t->execute_kw( 'stock.quant', 'action_apply_inventory', [ [ 9 ] ] );

		$req  = $this->last_request();
		$body = json_decode( $req['args']['body'], true );
		$this->assertStringEndsWith( '/json/2/stock.quant/action_apply_inventory', $req['url'] );
		$this->assertSame( [ 9 ], $body['ids'] );
	}

	public function test_empty_body_is_encoded_as_json_object(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [] );

		$t->execute_kw( 'res.partner', 'fields_get' );

		$this->assertSame( '{}', $this->last_request()['args']['body'] );
	}

	public function test_untranslatable_positional_args_throw(): void {
		$t = $this->authenticated();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'cannot translate positional arguments' );
		$t->execute_kw( 'res.partner', 'name_search', [ 'foo', [] ] );
	}

	public function test_odoo_error_uses_message_and_status_code(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'name' => 'odoo.exceptions.UserError', 'message' => 'Quants cannot be created' ], 422 );

		try {
			$t->execute_kw( 'stock.quant', 'create', [ [ 'product_id' => 1 ] ] );
			$this->fail( 'Expected exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Quants cannot be created', $e->getMessage() );
			$this->assertSame( 422, $e->getCode() );
		}
	}

	public function test_literal_null_result_is_valid(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = [ 'body' => 'null', 'response' => [ 'code' => 200 ] ];

		$this->assertNull( $t->execute_kw( 'stock.quant', 'action_apply_inventory', [ [ 9 ] ] ) );
	}

	public function test_malformed_json_throws(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = [ 'body' => '<html>oops</html>', 'response' => [ 'code' => 200 ] ];

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid JSON response' );
		$t->execute_kw( 'res.partner', 'search', [ [] ] );
	}

	public function test_http_500_with_odoo_body_surfaces_message(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [ 'name' => 'builtins.ValueError', 'message' => 'Invalid field nope_field' ], 500 );

		try {
			$t->execute_kw( 'res.partner', 'create', [ [ 'nope_field' => 1 ] ] );
			$this->fail( 'Expected exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Invalid field nope_field', $e->getMessage() );
			$this->assertSame( 500, $e->getCode() );
		}
	}

	public function test_http_429_still_throws_server_error(): void {
		$t = $this->authenticated();
		$GLOBALS['_wp_remote_responses'][] = $this->ok( [], 429 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Server error HTTP 429' );
		$t->execute_kw( 'res.partner', 'search', [ [] ] );
	}

	public function test_json2_rejection_codes_are_classified_permanent(): void {
		$classifier = new class() {
			use \WP4Odoo\Error_Classification;

			public function classify( \RuntimeException $e ): \WP4Odoo\Error_Type {
				return static::classify_exception( $e );
			}
		};

		$this->assertSame( \WP4Odoo\Error_Type::Permanent, $classifier->classify( new \RuntimeException( 'x', 422 ) ) );
		$this->assertSame( \WP4Odoo\Error_Type::Permanent, $classifier->classify( new \RuntimeException( 'x', 401 ) ) );
		$this->assertSame( \WP4Odoo\Error_Type::Permanent, $classifier->classify( new \RuntimeException( 'x', 403 ) ) );
		$this->assertSame( \WP4Odoo\Error_Type::Permanent, $classifier->classify( new \RuntimeException( 'x', 404 ) ) );
		$this->assertSame( \WP4Odoo\Error_Type::Transient, $classifier->classify( new \RuntimeException( 'x', 408 ) ) );
		$this->assertSame( \WP4Odoo\Error_Type::Transient, $classifier->classify( new \RuntimeException( 'x', 429 ) ) );
		$this->assertSame( \WP4Odoo\Error_Type::Transient, $classifier->classify( new \RuntimeException( 'x', 502 ) ) );
	}
}
