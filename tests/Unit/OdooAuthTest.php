<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\API\Odoo_Auth;
use WP4Odoo\API\Transport;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Odoo_Auth class.
 *
 * Tests encryption/decryption, credential storage, and sanitization.
 *
 * @package WP4Odoo\Tests\Unit
 */
class OdooAuthTest extends TestCase {

	/**
	 * Reset global state before each test.
	 */
	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();
		$GLOBALS['_wp_options'] = [];
		\WP4Odoo\Tests\Module_Test_Case::reset_static_caches();
	}

	/**
	 * Test encrypt returns empty string for empty input.
	 */
	public function test_encrypt_returns_empty_string_for_empty_input(): void {
		$result = Odoo_Auth::encrypt( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test encrypt returns non-empty base64 string for valid input.
	 */
	public function test_encrypt_returns_non_empty_base64_string_for_valid_input(): void {
		$result = Odoo_Auth::encrypt( 'test-api-key' );

		$this->assertNotEmpty( $result );
		$this->assertIsString( $result );

		// Verify it's valid base64.
		$decoded = base64_decode( $result, true );
		$this->assertNotFalse( $decoded );
		$this->assertNotEmpty( $decoded );
	}

	/**
	 * Test encrypt/decrypt round-trip preserves original value.
	 */
	public function test_encrypt_decrypt_round_trip(): void {
		$original = 'my-secret-api-key-12345';
		$encrypted = Odoo_Auth::encrypt( $original );
		$decrypted = Odoo_Auth::decrypt( $encrypted );

		$this->assertSame( $original, $decrypted );
	}

	/**
	 * Test decrypt returns empty string for empty input.
	 */
	public function test_decrypt_returns_empty_string_for_empty_input(): void {
		$result = Odoo_Auth::decrypt( '' );
		$this->assertSame( '', $result );
	}

	/**
	 * Test decrypt returns false for invalid base64.
	 */
	public function test_decrypt_returns_false_for_invalid_base64(): void {
		$result = Odoo_Auth::decrypt( 'not-valid-base64!@#$%^&*()' );
		$this->assertFalse( $result );
	}

	/**
	 * Test decrypt returns false for too-short data.
	 */
	public function test_decrypt_returns_false_for_too_short_data(): void {
		// Valid base64 but too short to contain nonce+cipher.
		$short_base64 = base64_encode( 'x' );
		$result = Odoo_Auth::decrypt( $short_base64 );

		$this->assertFalse( $result );
	}

	/**
	 * Test get_credentials returns defaults when no option stored.
	 */
	public function test_get_credentials_returns_defaults_when_no_option_stored(): void {
		// No option stored, so get_option returns [].
		$credentials = Odoo_Auth::get_credentials();

		$this->assertIsArray( $credentials );
		$this->assertArrayHasKey( 'url', $credentials );
		$this->assertArrayHasKey( 'database', $credentials );
		$this->assertArrayHasKey( 'username', $credentials );
		$this->assertArrayHasKey( 'api_key', $credentials );
		$this->assertArrayHasKey( 'protocol', $credentials );
		$this->assertArrayHasKey( 'timeout', $credentials );

		$this->assertSame( '', $credentials['url'] );
		$this->assertSame( '', $credentials['database'] );
		$this->assertSame( '', $credentials['username'] );
		$this->assertSame( '', $credentials['api_key'] );
		$this->assertSame( 'jsonrpc', $credentials['protocol'] );
		$this->assertSame( 30, $credentials['timeout'] );
	}

	/**
	 * Test get_credentials decrypts stored api_key.
	 */
	public function test_get_credentials_decrypts_stored_api_key(): void {
		$plaintext_key = 'secret-api-key';
		$encrypted_key = Odoo_Auth::encrypt( $plaintext_key );

		// Store encrypted credentials.
		$GLOBALS['_wp_options']['wp4odoo_connection'] = [
			'url'      => 'https://example.odoo.com',
			'database' => 'test_db',
			'username' => 'admin',
			'api_key'  => $encrypted_key,
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];

		$credentials = Odoo_Auth::get_credentials();

		$this->assertSame( 'https://example.odoo.com', $credentials['url'] );
		$this->assertSame( 'test_db', $credentials['database'] );
		$this->assertSame( 'admin', $credentials['username'] );
		$this->assertSame( $plaintext_key, $credentials['api_key'] );
		$this->assertSame( 'jsonrpc', $credentials['protocol'] );
		$this->assertSame( 30, $credentials['timeout'] );
	}

	/**
	 * Test get_credentials returns empty api_key when decryption fails.
	 */
	public function test_get_credentials_returns_empty_api_key_when_decryption_fails(): void {
		// Store garbage that can't be decrypted.
		$GLOBALS['_wp_options']['wp4odoo_connection'] = [
			'url'      => 'https://example.odoo.com',
			'database' => 'test_db',
			'username' => 'admin',
			'api_key'  => 'garbage-not-encrypted',
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];

		$credentials = Odoo_Auth::get_credentials();

		// Decryption fails, so api_key should be empty.
		$this->assertSame( '', $credentials['api_key'] );
	}

	/**
	 * Test save_credentials encrypts api_key.
	 */
	public function test_save_credentials_encrypts_api_key(): void {
		$plaintext_key = 'my-plaintext-key';

		$credentials = [
			'url'      => 'https://example.odoo.com',
			'database' => 'test_db',
			'username' => 'admin',
			'api_key'  => $plaintext_key,
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];

		$result = Odoo_Auth::save_credentials( $credentials );
		$this->assertTrue( $result );

		// Check stored value.
		$stored = $GLOBALS['_wp_options']['wp4odoo_connection'];
		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'api_key', $stored );

		// Stored api_key should NOT be plaintext.
		$this->assertNotSame( $plaintext_key, $stored['api_key'] );

		// Stored api_key should be valid base64.
		$decoded = base64_decode( $stored['api_key'], true );
		$this->assertNotFalse( $decoded );

		// Decrypt and verify it matches original.
		$decrypted = Odoo_Auth::decrypt( $stored['api_key'] );
		$this->assertSame( $plaintext_key, $decrypted );
	}

	/**
	 * Test save_credentials sanitizes url.
	 */
	public function test_save_credentials_sanitizes_url(): void {
		$credentials = [
			'url'      => 'https://example.odoo.com',
			'database' => 'test_db',
			'username' => 'admin',
			'api_key'  => 'key123',
			'protocol' => 'jsonrpc',
			'timeout'  => 30,
		];

		Odoo_Auth::save_credentials( $credentials );

		$stored = $GLOBALS['_wp_options']['wp4odoo_connection'];

		// Verify the stored url was passed through esc_url_raw.
		$this->assertSame( 'https://example.odoo.com', $stored['url'] );
	}

	/**
	 * Test save_credentials defaults protocol to jsonrpc.
	 */
	public function test_save_credentials_defaults_protocol_to_jsonrpc(): void {
		$credentials = [
			'url'      => 'https://example.odoo.com',
			'database' => 'test_db',
			'username' => 'admin',
			'api_key'  => 'key123',
			'protocol' => 'invalid-protocol',
			'timeout'  => 30,
		];

		Odoo_Auth::save_credentials( $credentials );

		$stored = $GLOBALS['_wp_options']['wp4odoo_connection'];
		$this->assertSame( 'jsonrpc', $stored['protocol'] );
	}

	/**
	 * Test save_credentials accepts xmlrpc protocol.
	 */
	public function test_save_credentials_accepts_xmlrpc_protocol(): void {
		$credentials = [
			'url'      => 'https://example.odoo.com',
			'database' => 'test_db',
			'username' => 'admin',
			'api_key'  => 'key123',
			'protocol' => 'xmlrpc',
			'timeout'  => 30,
		];

		Odoo_Auth::save_credentials( $credentials );

		$stored = $GLOBALS['_wp_options']['wp4odoo_connection'];
		$this->assertSame( 'xmlrpc', $stored['protocol'] );
	}

	/**
	 * Test test_connection returns failure for missing credentials.
	 */
	public function test_test_connection_returns_failure_for_missing_credentials(): void {
		// Call test_connection with empty values.
		$result = Odoo_Auth::test_connection( '', '', '', '' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'uid', $result );
		$this->assertArrayHasKey( 'version', $result );
		$this->assertArrayHasKey( 'message', $result );

		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['uid'] );
		$this->assertNull( $result['version'] );
		$this->assertNotEmpty( $result['message'] );
		$this->assertStringContainsString( 'Missing', $result['message'] );
	}

	/**
	 * Test test_connection does not include models key when check_models is empty.
	 */
	public function test_test_connection_without_check_models_has_no_models_key(): void {
		$result = Odoo_Auth::test_connection( '', '', '', '' );

		$this->assertArrayNotHasKey( 'models', $result );
	}

	// ─── probe_models ───────────────────────────────────

	/**
	 * Test probe_models returns available and missing models.
	 */
	public function test_probe_models_identifies_available_and_missing(): void {
		$transport = $this->createMockTransport( [
			[ 'id' => 1, 'model' => 'res.partner' ],
			[ 'id' => 2, 'model' => 'account.move' ],
		] );

		$result = Odoo_Auth::probe_models( $transport, [
			'res.partner',
			'crm.lead',
			'account.move',
			'sale.order',
		] );

		$this->assertSame( [ 'res.partner', 'account.move' ], $result['available'] );
		$this->assertSame( [ 'crm.lead', 'sale.order' ], $result['missing'] );
	}

	/**
	 * Test probe_models returns all models as available when all exist.
	 */
	public function test_probe_models_all_available(): void {
		$transport = $this->createMockTransport( [
			[ 'id' => 1, 'model' => 'res.partner' ],
			[ 'id' => 2, 'model' => 'crm.lead' ],
		] );

		$result = Odoo_Auth::probe_models( $transport, [ 'res.partner', 'crm.lead' ] );

		$this->assertSame( [ 'res.partner', 'crm.lead' ], $result['available'] );
		$this->assertSame( [], $result['missing'] );
	}

	/**
	 * Test probe_models returns empty arrays for empty input.
	 */
	public function test_probe_models_empty_list(): void {
		$transport = $this->createMockTransport( [] );

		$result = Odoo_Auth::probe_models( $transport, [] );

		$this->assertSame( [], $result['available'] );
		$this->assertSame( [], $result['missing'] );
	}

	/**
	 * Test probe_models handles transport exception gracefully.
	 */
	public function test_probe_models_handles_transport_error(): void {
		$transport = new class implements Transport {
			public function authenticate( string $username ): int {
				return 1;
			}
			public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
				throw new \RuntimeException( 'Network timeout' );
			}
			public function get_uid(): ?int {
				return 1;
			}
			public function get_server_version(): ?string {
				return null;
			}
		};

		$result = Odoo_Auth::probe_models( $transport, [ 'crm.lead', 'sale.order' ] );

		// On error, both arrays are empty — no false warnings.
		$this->assertSame( [], $result['available'] );
		$this->assertSame( [], $result['missing'] );
		$this->assertArrayHasKey( 'error', $result );
		$this->assertStringContainsString( 'Network timeout', $result['error'] );
	}

	/**
	 * Test probe_models correctly queries ir.model with 'in' domain.
	 */
	public function test_probe_models_queries_ir_model(): void {
		$transport = new class implements Transport {
			public array $last_call = [];
			public function authenticate( string $username ): int {
				return 1;
			}
			public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
				$this->last_call = [
					'model'  => $model,
					'method' => $method,
					'args'   => $args,
					'kwargs' => $kwargs,
				];
				return [];
			}
			public function get_uid(): ?int {
				return 1;
			}
			public function get_server_version(): ?string {
				return null;
			}
		};

		Odoo_Auth::probe_models( $transport, [ 'crm.lead' ] );

		$this->assertSame( 'ir.model', $transport->last_call['model'] );
		$this->assertSame( 'search_read', $transport->last_call['method'] );
		$this->assertSame( [ [ [ 'model', 'in', [ 'crm.lead' ] ] ] ], $transport->last_call['args'] );
		$this->assertSame( [ 'fields' => [ 'model' ] ], $transport->last_call['kwargs'] );
	}

	// ─── Helpers ────────────────────────────────────────

	/**
	 * Create a mock Transport that returns fixed ir.model records.
	 *
	 * @param array<int, array{id: int, model: string}> $records
	 * @return Transport
	 */
	private function createMockTransport( array $records ): Transport {
		return new class( $records ) implements Transport {
			/** @var array<int, array{id: int, model: string}> */
			private array $records;

			public function __construct( array $records ) {
				$this->records = $records;
			}

			public function authenticate( string $username ): int {
				return 1;
			}

			public function execute_kw( string $model, string $method, array $args = [], array $kwargs = [] ): mixed {
				return $this->records;
			}

			public function get_uid(): ?int {
				return 1;
			}

			public function get_server_version(): ?string {
				return null;
			}
		};
	}
}
