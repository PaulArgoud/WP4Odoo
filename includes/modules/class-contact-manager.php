<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contact data operations: load, save, and sync-check for WordPress users.
 *
 * Extracted from CRM_Module for single responsibility.
 * Handles WooCommerce billing field fallbacks and email deduplication on pull.
 *
 * @package WP4Odoo
 * @since   1.5.0
 */
class Contact_Manager {

	/**
	 * Billing/address meta fields with WooCommerce fallback keys.
	 *
	 * Keys are the canonical data keys; values are arrays of user meta
	 * keys to try (first match wins on load).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const CONTACT_META_FIELDS = [
		'billing_phone'     => [ 'billing_phone', 'phone' ],
		'billing_company'   => [ 'billing_company', 'company' ],
		'billing_address_1' => [ 'billing_address_1' ],
		'billing_address_2' => [ 'billing_address_2' ],
		'billing_city'      => [ 'billing_city' ],
		'billing_postcode'  => [ 'billing_postcode' ],
		'billing_country'   => [ 'billing_country' ],
		'billing_state'     => [ 'billing_state' ],
	];

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Closure returning the module's current settings array.
	 *
	 * @var \Closure
	 */
	private \Closure $settings_provider;

	/**
	 * Constructor.
	 *
	 * @param Logger   $logger            Logger instance.
	 * @param \Closure $settings_provider Returns the module settings array.
	 */
	public function __construct( Logger $logger, \Closure $settings_provider ) {
		$this->logger            = $logger;
		$this->settings_provider = $settings_provider;
	}

	/**
	 * Load contact data from a WordPress user.
	 *
	 * @param int $wp_id User ID.
	 * @return array<string, mixed>
	 */
	public function load_contact_data( int $wp_id ): array {
		$user = get_userdata( $wp_id );
		if ( ! $user ) {
			return [];
		}

		$data = [
			'display_name' => $user->display_name,
			'user_email'   => $user->user_email,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'description'  => $user->description,
			'user_url'     => $user->user_url,
		];

		// WooCommerce billing fields (fallback to generic meta).
		foreach ( self::CONTACT_META_FIELDS as $key => $meta_keys ) {
			$value = '';
			foreach ( $meta_keys as $meta_key ) {
				$value = get_user_meta( $wp_id, $meta_key, true );
				if ( '' !== $value ) {
					break;
				}
			}
			$data[ $key ] = $value;
		}

		return $data;
	}

	/**
	 * Save contact data as a WordPress user.
	 *
	 * Handles email deduplication and user meta updates.
	 *
	 * @param array<string, mixed> $data  Mapped contact data.
	 * @param int                  $wp_id Existing user ID (0 to create).
	 * @return int User ID or 0 on failure.
	 */
	public function save_contact_data( array $data, int $wp_id = 0 ): int {
		$email = $data['user_email'] ?? '';

		if ( empty( $email ) || ! is_email( $email ) ) {
			$this->logger->warning( 'Cannot save contact without valid email.', compact( 'data', 'wp_id' ) );
			return 0;
		}

		// Email dedup: check if a user with this email already exists.
		if ( 0 === $wp_id ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				$wp_id = $existing->ID;
				$this->logger->info(
					'Pull dedup: matched existing WP user by email.',
					[
						'email' => $email,
						'wp_id' => $wp_id,
					]
				);
			}
		}

		$settings = ( $this->settings_provider )();

		if ( $wp_id > 0 ) {
			// Update existing user.
			$userdata = [
				'ID'           => $wp_id,
				'display_name' => $data['display_name'] ?? '',
				'first_name'   => $data['first_name'] ?? '',
				'last_name'    => $data['last_name'] ?? '',
				'description'  => $data['description'] ?? '',
				'user_url'     => $data['user_url'] ?? '',
			];

			$userdata       = array_filter( $userdata, fn( $v ) => '' !== $v );
			$userdata['ID'] = $wp_id;

			$result = wp_update_user( $userdata );
			if ( is_wp_error( $result ) ) {
				$this->logger->error(
					'Failed to update WP user.',
					[
						'wp_id' => $wp_id,
						'error' => $result->get_error_message(),
					]
				);
				return 0;
			}
		} else {
			// Create new user.
			if ( empty( $settings['create_users_on_pull'] ) ) {
				$this->logger->info( 'User creation on pull is disabled.', compact( 'email' ) );
				return 0;
			}

			$username = strstr( $email, '@', true );
			if ( username_exists( $username ) ) {
				$username .= '_' . wp_rand( 100, 999 );
			}

			$userdata = [
				'user_login'   => $username,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password(),
				'display_name' => $data['display_name'] ?? $username,
				'first_name'   => $data['first_name'] ?? '',
				'last_name'    => $data['last_name'] ?? '',
				'description'  => $data['description'] ?? '',
				'user_url'     => $data['user_url'] ?? '',
				'role'         => $settings['default_user_role'] ?: 'subscriber',
			];

			$wp_id = wp_insert_user( $userdata );
			if ( is_wp_error( $wp_id ) ) {
				$this->logger->error(
					'Failed to create WP user.',
					[
						'email' => $email,
						'error' => $wp_id->get_error_message(),
					]
				);
				return 0;
			}
		}

		// Save billing / meta fields.
		foreach ( self::CONTACT_META_FIELDS as $key => $meta_keys ) {
			if ( isset( $data[ $key ] ) && '' !== $data[ $key ] ) {
				update_user_meta( $wp_id, $meta_keys[0], $data[ $key ] );
			}
		}

		return $wp_id;
	}

	/**
	 * Check whether a user should be synced based on role settings.
	 *
	 * @param int $user_id The user ID to check.
	 * @return bool True if the user should be synced.
	 */
	public function should_sync_user( int $user_id ): bool {
		$settings = ( $this->settings_provider )();

		if ( empty( $settings['sync_users_as_contacts'] ) ) {
			return false;
		}

		$sync_role = $settings['sync_role'] ?? '';

		// Empty = sync all roles.
		if ( '' === $sync_role ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return in_array( $sync_role, $user->roles, true );
	}
}
