<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\API\Odoo_Auth;
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
}
