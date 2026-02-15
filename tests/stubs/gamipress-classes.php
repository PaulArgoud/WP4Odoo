<?php
/**
 * GamiPress class and function stubs for PHPUnit tests.
 *
 * @package WP4Odoo\Tests
 */

// ─── Global stores ──────────────────────────────────────

$GLOBALS['_gamipress_points'] = [];

// ─── Constants ──────────────────────────────────────────

if ( ! defined( 'GAMIPRESS_VERSION' ) ) {
	define( 'GAMIPRESS_VERSION', '2.8.0' );
}

// ─── Core functions ─────────────────────────────────────

if ( ! function_exists( 'gamipress' ) ) {
	/**
	 * Return the GamiPress singleton stub.
	 *
	 * @return stdClass
	 */
	function gamipress(): stdClass {
		static $instance;
		if ( ! $instance ) {
			$instance = new stdClass();
		}
		return $instance;
	}
}

if ( ! function_exists( 'gamipress_get_user_points' ) ) {
	/**
	 * Get user points by type.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $points_type Points type slug.
	 * @return int Point balance.
	 */
	function gamipress_get_user_points( int $user_id, string $points_type = 'points' ): int {
		return (int) ( $GLOBALS['_gamipress_points'][ $user_id ][ $points_type ] ?? 0 );
	}
}

if ( ! function_exists( 'gamipress_award_points_to_user' ) ) {
	/**
	 * Award points to a user.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $points      Points to award.
	 * @param string $points_type Points type slug.
	 * @param array  $args        Additional arguments.
	 * @return void
	 */
	function gamipress_award_points_to_user( int $user_id, int $points, string $points_type = 'points', array $args = [] ): void {
		if ( ! isset( $GLOBALS['_gamipress_points'][ $user_id ] ) ) {
			$GLOBALS['_gamipress_points'][ $user_id ] = [];
		}
		$current = $GLOBALS['_gamipress_points'][ $user_id ][ $points_type ] ?? 0;
		$GLOBALS['_gamipress_points'][ $user_id ][ $points_type ] = $current + $points;
	}
}

if ( ! function_exists( 'gamipress_deduct_points_to_user' ) ) {
	/**
	 * Deduct points from a user.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $points      Points to deduct.
	 * @param string $points_type Points type slug.
	 * @param array  $args        Additional arguments.
	 * @return void
	 */
	function gamipress_deduct_points_to_user( int $user_id, int $points, string $points_type = 'points', array $args = [] ): void {
		if ( ! isset( $GLOBALS['_gamipress_points'][ $user_id ] ) ) {
			$GLOBALS['_gamipress_points'][ $user_id ] = [];
		}
		$current = $GLOBALS['_gamipress_points'][ $user_id ][ $points_type ] ?? 0;
		$GLOBALS['_gamipress_points'][ $user_id ][ $points_type ] = max( 0, $current - $points );
	}
}
