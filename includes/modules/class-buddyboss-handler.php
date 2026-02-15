<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuddyBoss data handler — loads, saves, and formats BuddyBoss entities.
 *
 * Accesses BuddyBoss/BuddyPress data via:
 * - WordPress user API (get_userdata, wp_update_user)
 * - BuddyPress xprofile API (bp_get_profile_field_data, xprofile_set_field_data)
 * - BuddyPress groups API (groups_get_group, groups_get_user_groups)
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
class BuddyBoss_Handler {

	/**
	 * XProfile fields to sync with Odoo.
	 *
	 * Keys are Odoo-friendly identifiers; values are BuddyPress field names.
	 *
	 * @var array<string, string>
	 */
	private const XPROFILE_FIELDS = [
		'phone'    => 'Phone',
		'location' => 'Location',
	];

	/**
	 * Logger instance.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger instance.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	// ─── Load methods ────────────────────────────────────────

	/**
	 * Load a BuddyBoss profile (WP user + xprofile fields) by user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed> Profile data or empty array if not found.
	 */
	public function load_profile( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'BuddyBoss profile not found.', [ 'user_id' => $user_id ] );
			return [];
		}

		$data = [
			'user_id'      => $user_id,
			'user_email'   => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'description'  => $user->description,
			'user_url'     => $user->user_url,
		];

		// Load xprofile fields.
		foreach ( self::XPROFILE_FIELDS as $key => $field_name ) {
			$data[ $key ] = bp_get_profile_field_data(
				[
					'field'   => $field_name,
					'user_id' => $user_id,
				]
			);
		}

		return $data;
	}

	/**
	 * Load a BuddyBoss group by ID.
	 *
	 * @param int $group_id BuddyPress group ID.
	 * @return array<string, mixed> Group data or empty array if not found.
	 */
	public function load_group( int $group_id ): array {
		$group = groups_get_group( $group_id );
		if ( ! $group ) {
			$this->logger->warning( 'BuddyBoss group not found.', [ 'group_id' => $group_id ] );
			return [];
		}

		return [
			'id'          => $group_id,
			'name'        => $group->name ?? '',
			'description' => $group->description ?? '',
			'status'      => $group->status ?? '',
		];
	}

	/**
	 * Get the BuddyPress group IDs a user belongs to.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<int> Array of group IDs.
	 */
	public function get_user_group_ids( int $user_id ): array {
		$result = groups_get_user_groups( $user_id );
		return array_map( 'intval', $result['groups'] );
	}

	// ─── Save methods (pull) ─────────────────────────────────

	/**
	 * Save profile data to a WordPress user + xprofile fields.
	 *
	 * @param array<string, mixed> $data    Profile data.
	 * @param int                  $user_id WordPress user ID.
	 * @return int User ID on success, 0 on failure.
	 */
	public function save_profile( array $data, int $user_id ): int {
		if ( $user_id <= 0 ) {
			$this->logger->warning( 'Cannot save BuddyBoss profile without user ID.', [ 'data' => $data ] );
			return 0;
		}

		$userdata = [
			'ID'          => $user_id,
			'first_name'  => $data['first_name'] ?? '',
			'last_name'   => $data['last_name'] ?? '',
			'description' => $data['description'] ?? '',
			'user_url'    => $data['user_url'] ?? '',
		];

		$result = wp_update_user( $userdata );
		if ( is_wp_error( $result ) ) {
			$this->logger->error(
				'Failed to update WP user for BuddyBoss profile.',
				[
					'user_id' => $user_id,
					'error'   => $result->get_error_message(),
				]
			);
			return 0;
		}

		// Save xprofile fields.
		foreach ( self::XPROFILE_FIELDS as $key => $field_name ) {
			if ( isset( $data[ $key ] ) ) {
				xprofile_set_field_data( $field_name, $user_id, $data[ $key ] );
			}
		}

		return $user_id;
	}

	// ─── Format methods (push to Odoo) ───────────────────────

	/**
	 * Format profile data as Odoo res.partner values.
	 *
	 * @param array<string, mixed> $profile        Profile data from load_profile().
	 * @param array<int>           $group_odoo_ids Odoo category IDs for the user's groups.
	 * @return array<string, mixed> Odoo-compatible field values.
	 */
	public function format_partner( array $profile, array $group_odoo_ids = [] ): array {
		$first = $profile['first_name'] ?? '';
		$last  = $profile['last_name'] ?? '';
		$name  = trim( $first . ' ' . $last );

		if ( '' === $name ) {
			$name = $profile['display_name'] ?? '';
		}

		$values = [
			'name'    => $name,
			'email'   => $profile['user_email'] ?? '',
			'phone'   => $profile['phone'] ?? '',
			'comment' => $profile['description'] ?? '',
			'website' => $profile['user_url'] ?? '',
		];

		if ( ! empty( $group_odoo_ids ) ) {
			$values['category_id'] = [ [ 6, 0, $group_odoo_ids ] ];
		}

		return $values;
	}

	/**
	 * Format group data as Odoo res.partner.category values.
	 *
	 * @param array<string, mixed> $group Group data from load_group().
	 * @return array<string, string> Odoo-compatible field values.
	 */
	public function format_category( array $group ): array {
		return [
			'name' => $group['name'] ?? '',
		];
	}

	// ─── Parse methods (pull from Odoo) ──────────────────────

	/**
	 * Parse profile data from an Odoo res.partner record.
	 *
	 * Splits the Odoo name field into first_name and last_name.
	 *
	 * @param array<string, mixed> $odoo_data Odoo record data.
	 * @return array<string, string> WordPress-compatible profile data.
	 */
	public function parse_profile_from_odoo( array $odoo_data ): array {
		$name_parts = explode( ' ', $odoo_data['name'] ?? '', 2 );

		return [
			'first_name'  => $name_parts[0],
			'last_name'   => $name_parts[1] ?? '',
			'user_email'  => $odoo_data['email'] ?? '',
			'phone'       => $odoo_data['phone'] ?? '',
			'description' => $odoo_data['comment'] ?? '',
			'user_url'    => $odoo_data['website'] ?? '',
		];
	}
}
