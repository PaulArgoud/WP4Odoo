<?php
/**
 * BuddyBoss/BuddyPress class and function stubs for PHPUnit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── Global constants ───────────────────────────────────

if ( ! defined( 'BP_VERSION' ) ) {
	define( 'BP_VERSION', '2.6.0' );
}

// ─── BuddyPress function stubs ─────────────────────────

if ( ! function_exists( 'buddypress' ) ) {
	/**
	 * BuddyPress main instance stub.
	 *
	 * @return stdClass
	 */
	function buddypress(): stdClass {
		return new stdClass();
	}
}

if ( ! function_exists( 'bp_get_profile_field_data' ) ) {
	/**
	 * Retrieve xprofile field data for a user.
	 *
	 * @param array $args Arguments including 'field' and 'user_id'.
	 * @return string Field value.
	 */
	function bp_get_profile_field_data( array $args = [] ): string {
		$user_id = $args['user_id'] ?? 0;
		$field   = $args['field'] ?? '';
		return (string) ( $GLOBALS['_bp_xprofile'][ $user_id ][ $field ] ?? '' );
	}
}

if ( ! function_exists( 'xprofile_set_field_data' ) ) {
	/**
	 * Set xprofile field data for a user.
	 *
	 * @param string $field   Field name.
	 * @param int    $user_id User ID.
	 * @param mixed  $value   Field value.
	 * @return bool
	 */
	function xprofile_set_field_data( string $field, int $user_id, $value ): bool {
		$GLOBALS['_bp_xprofile'][ $user_id ][ $field ] = $value;
		return true;
	}
}

if ( ! function_exists( 'groups_get_group' ) ) {
	/**
	 * Retrieve a BuddyPress group by ID.
	 *
	 * @param int $group_id Group ID.
	 * @return object|null Group object or null.
	 */
	function groups_get_group( int $group_id ) {
		return $GLOBALS['_bp_groups'][ $group_id ] ?? null;
	}
}

if ( ! function_exists( 'groups_get_user_groups' ) ) {
	/**
	 * Get groups a user belongs to.
	 *
	 * @param int $user_id User ID.
	 * @return array{groups: array<int>, total: int}
	 */
	function groups_get_user_groups( int $user_id ): array {
		return $GLOBALS['_bp_user_groups'][ $user_id ] ?? [ 'groups' => [], 'total' => 0 ];
	}
}
