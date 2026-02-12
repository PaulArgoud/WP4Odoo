<?php
declare( strict_types=1 );

namespace WP4Odoo\API;

use WP4Odoo\Logger;
use WP4Odoo\Settings_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Odoo API authentication and credential encryption.
 *
 * Uses libsodium (sodium_crypto_secretbox) for encryption with OpenSSL fallback.
 * Key derivation from WordPress auth salts or WP4ODOO_ENCRYPTION_KEY constant.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class Odoo_Auth {

	/**
	 * OpenSSL cipher method for fallback encryption.
	 */
	private const OPENSSL_METHOD = 'aes-256-cbc';

	/**
	 * Encrypt a plaintext value for safe storage in wp_options.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @return string Base64-encoded encrypted string (nonce/IV prepended).
	 */
	public static function encrypt( string $plaintext ): string {
		if ( empty( $plaintext ) ) {
			return '';
		}

		$key = self::get_encryption_key();

		if ( self::has_sodium() ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			sodium_memzero( $key );

			return base64_encode( $nonce . $cipher );
		}

		return self::openssl_encrypt_value( $plaintext, $key );
	}

	/**
	 * Decrypt a value previously encrypted with encrypt().
	 *
	 * @param string $encrypted Base64-encoded encrypted string.
	 * @return string|false The decrypted plaintext, or false on failure.
	 */
	public static function decrypt( string $encrypted ): string|false {
		if ( empty( $encrypted ) ) {
			return '';
		}

		$key     = self::get_encryption_key();
		$decoded = base64_decode( $encrypted, true );

		if ( false === $decoded ) {
			return false;
		}

		if ( self::has_sodium() ) {
			if ( strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return false;
			}

			$nonce  = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$result = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			sodium_memzero( $key );

			return $result;
		}

		return self::openssl_decrypt_value( $decoded, $key );
	}

	/**
	 * Per-request credential cache to avoid repeated decryption.
	 *
	 * @var array|null
	 */
	private static ?array $credentials_cache = null;

	/**
	 * Get decrypted Odoo credentials from wp_options.
	 *
	 * Caches the result within the current request to avoid repeated
	 * option reads and sodium/OpenSSL decryption on batch operations.
	 *
	 * @return array{url: string, database: string, username: string, api_key: string, protocol: string, timeout: int}
	 */
	public static function get_credentials(): array {
		if ( null !== self::$credentials_cache ) {
			return self::$credentials_cache;
		}

		$connection = get_option( Settings_Repository::OPT_CONNECTION, [] );

		$credentials = [
			'url'      => $connection['url'] ?? '',
			'database' => $connection['database'] ?? '',
			'username' => $connection['username'] ?? '',
			'api_key'  => '',
			'protocol' => $connection['protocol'] ?? 'jsonrpc',
			'timeout'  => (int) ( $connection['timeout'] ?? 30 ),
		];

		if ( ! empty( $connection['api_key'] ) ) {
			$decrypted = self::decrypt( $connection['api_key'] );
			if ( false !== $decrypted ) {
				$credentials['api_key'] = $decrypted;
			}
		}

		self::$credentials_cache = $credentials;

		return $credentials;
	}

	/**
	 * Clear the credential cache (e.g. after saving new credentials).
	 *
	 * @return void
	 */
	public static function flush_credentials_cache(): void {
		self::$credentials_cache = null;
	}

	/**
	 * Save credentials to wp_options with API key encrypted.
	 *
	 * @param array<string, mixed> $credentials Array with url, database, username, api_key, protocol, timeout.
	 * @return bool True on success.
	 */
	public static function save_credentials( array $credentials ): bool {
		$sanitized = [
			'url'      => esc_url_raw( $credentials['url'] ?? '' ),
			'database' => sanitize_text_field( $credentials['database'] ?? '' ),
			'username' => sanitize_text_field( $credentials['username'] ?? '' ),
			'api_key'  => '',
			'protocol' => in_array( $credentials['protocol'] ?? '', [ 'jsonrpc', 'xmlrpc' ], true )
				? $credentials['protocol']
				: 'jsonrpc',
			'timeout'  => absint( $credentials['timeout'] ?? 30 ),
		];

		if ( ! empty( $credentials['api_key'] ) ) {
			$sanitized['api_key'] = self::encrypt( $credentials['api_key'] );
		}

		self::flush_credentials_cache();

		return update_option( Settings_Repository::OPT_CONNECTION, $sanitized );
	}

	/**
	 * Test connection to an Odoo instance.
	 *
	 * When $check_models is provided and authentication succeeds, queries
	 * the Odoo ir.model registry to verify which models are available.
	 *
	 * @param string|null        $url          Odoo URL (uses stored if null).
	 * @param string|null        $database     Database name (uses stored if null).
	 * @param string|null        $username     Username (uses stored if null).
	 * @param string|null        $api_key      API key in plaintext (uses stored if null).
	 * @param string             $protocol     'jsonrpc' or 'xmlrpc'.
	 * @param array<int, string> $check_models Optional Odoo model names to verify after auth.
	 * @return array{success: bool, uid: int|null, version: string|null, message: string, models?: array{available: string[], missing: string[]}}
	 */
	public static function test_connection(
		?string $url = null,
		?string $database = null,
		?string $username = null,
		?string $api_key = null,
		string $protocol = 'jsonrpc',
		array $check_models = []
	): array {
		$logger = new Logger( 'auth' );

		$result = [
			'success' => false,
			'uid'     => null,
			'version' => null,
			'message' => '',
		];

		if ( null === $url || null === $database || null === $username || null === $api_key ) {
			$stored   = self::get_credentials();
			$url      = $url ?? $stored['url'];
			$database = $database ?? $stored['database'];
			$username = $username ?? $stored['username'];
			$api_key  = $api_key ?? $stored['api_key'];
			$protocol = $protocol ?: ( $stored['protocol'] ?? 'jsonrpc' );
		}

		if ( empty( $url ) || empty( $database ) || empty( $username ) || empty( $api_key ) ) {
			$result['message'] = __( 'Missing connection credentials.', 'wp4odoo' );
			return $result;
		}

		try {
			$timeout = 15;

			if ( 'xmlrpc' === $protocol ) {
				$transport = new Odoo_XmlRPC( $url, $database, $api_key, $timeout );
			} else {
				$transport = new Odoo_JsonRPC( $url, $database, $api_key, $timeout );
			}

			$uid = $transport->authenticate( $username );

			$result['success'] = true;
			$result['uid']     = $uid;
			$result['message'] = __( 'Connection successful.', 'wp4odoo' );

			// Probe model availability if requested.
			if ( ! empty( $check_models ) ) {
				$result['models'] = self::probe_models( $transport, $check_models );
			}

			$logger->info(
				'Connection test successful.',
				[
					'url'      => $url,
					'database' => $database,
					'uid'      => $uid,
				]
			);
		} catch ( \Throwable $e ) {
			$result['message'] = $e->getMessage();

			$logger->error(
				'Connection test failed.',
				[
					'url'      => $url,
					'database' => $database,
					'error'    => $e->getMessage(),
				]
			);
		}

		return $result;
	}

	/**
	 * Check which Odoo models are available on a connected instance.
	 *
	 * Queries the ir.model registry (always available in Odoo) to determine
	 * which of the given model names exist. Used to detect missing Odoo modules.
	 *
	 * @param Transport          $transport Authenticated transport instance.
	 * @param array<int, string> $models    Model names to check (e.g., ['crm.lead', 'sale.order']).
	 * @return array{available: string[], missing: string[]}
	 */
	public static function probe_models( Transport $transport, array $models ): array {
		if ( empty( $models ) ) {
			return [
				'available' => [],
				'missing'   => [],
			];
		}

		try {
			$records = $transport->execute_kw(
				'ir.model',
				'search_read',
				[ [ [ 'model', 'in', $models ] ] ],
				[ 'fields' => [ 'model' ] ]
			);

			$found   = is_array( $records ) ? array_column( $records, 'model' ) : [];
			$missing = array_values( array_diff( $models, $found ) );

			return [
				'available' => $found,
				'missing'   => $missing,
			];
		} catch ( \Throwable $e ) {
			// Probe failed — don't report false missing models.
			$logger = new Logger( 'auth' );
			$logger->warning(
				'Model availability check failed.',
				[
					'error' => $e->getMessage(),
				]
			);

			return [
				'available' => [],
				'missing'   => [],
				'error'     => $e->getMessage(),
			];
		}
	}

	/**
	 * Re-encrypt stored credentials after an encryption key change.
	 *
	 * Call this after updating WP4ODOO_ENCRYPTION_KEY or the WordPress
	 * auth salts to re-encrypt credentials with the new key.
	 *
	 * @param string $old_key_material The previous key material (constant value or salt concatenation).
	 * @return bool True if re-encryption succeeded.
	 */
	public static function rotate_encryption_key( string $old_key_material ): bool {
		$connection = get_option( Settings_Repository::OPT_CONNECTION, [] );

		if ( empty( $connection['api_key'] ) ) {
			return true;
		}

		// Decrypt with the old key.
		$old_key = hash( 'sha256', $old_key_material, true );
		$decoded = base64_decode( $connection['api_key'], true );

		if ( false === $decoded ) {
			return false;
		}

		if ( self::has_sodium() ) {
			if ( strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
				return false;
			}
			$nonce     = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher    = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plaintext = sodium_crypto_secretbox_open( $cipher, $nonce, $old_key );
			sodium_memzero( $old_key );
		} else {
			$plaintext = self::openssl_decrypt_value( $decoded, $old_key );
		}

		if ( false === $plaintext ) {
			return false;
		}

		// Re-encrypt with the current (new) key.
		$connection['api_key'] = self::encrypt( $plaintext );

		return update_option( Settings_Repository::OPT_CONNECTION, $connection );
	}

	/**
	 * Derive the encryption key.
	 *
	 * Uses WP4ODOO_ENCRYPTION_KEY constant if defined,
	 * otherwise derives from AUTH_KEY + SECURE_AUTH_KEY via SHA-256.
	 *
	 * @return string 32-byte binary key.
	 */
	private static function get_encryption_key(): string {
		if ( defined( 'WP4ODOO_ENCRYPTION_KEY' ) && WP4ODOO_ENCRYPTION_KEY ) {
			return hash( 'sha256', WP4ODOO_ENCRYPTION_KEY, true );
		}

		$salt = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' );

		return hash( 'sha256', $salt, true );
	}

	/**
	 * Check if sodium extension is available.
	 *
	 * @return bool
	 */
	private static function has_sodium(): bool {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Encrypt using OpenSSL as fallback.
	 *
	 * @param string $plaintext The value to encrypt.
	 * @param string $key       The encryption key (32 bytes).
	 * @return string Base64-encoded IV + ciphertext.
	 */
	private static function openssl_encrypt_value( string $plaintext, string $key ): string {
		$iv_length = openssl_cipher_iv_length( self::OPENSSL_METHOD );
		$iv        = openssl_random_pseudo_bytes( $iv_length );
		$cipher    = openssl_encrypt( $plaintext, self::OPENSSL_METHOD, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		return base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt using OpenSSL as fallback.
	 *
	 * @param string $decoded The raw decoded bytes (IV + ciphertext).
	 * @param string $key     The encryption key (32 bytes).
	 * @return string|false
	 */
	private static function openssl_decrypt_value( string $decoded, string $key ): string|false {
		$iv_length = openssl_cipher_iv_length( self::OPENSSL_METHOD );

		if ( strlen( $decoded ) <= $iv_length ) {
			return false;
		}

		$iv     = substr( $decoded, 0, $iv_length );
		$cipher = substr( $decoded, $iv_length );

		return openssl_decrypt( $cipher, self::OPENSSL_METHOD, $key, OPENSSL_RAW_DATA, $iv );
	}
}
