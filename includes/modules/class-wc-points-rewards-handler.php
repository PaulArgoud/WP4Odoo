<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Points & Rewards Handler — data access for point balances.
 *
 * Loads WC point balances, formats data for Odoo loyalty.card model,
 * and provides reverse parsing and save methods for pull sync.
 *
 * Called by WC_Points_Rewards_Module via its load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.0.0
 */
class WC_Points_Rewards_Handler {

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

	/**
	 * Load point balance data for a WordPress user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed> Balance data, or empty array if user not found.
	 */
	public function load_balance( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'WC Points: user not found.', [ 'user_id' => $user_id ] );
			return [];
		}

		$points = \WC_Points_Rewards_Manager::get_users_points( $user_id );

		return [
			'user_id' => $user_id,
			'email'   => $user->user_email,
			'name'    => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
			'points'  => $points,
		];
	}

	/**
	 * Format balance data for Odoo loyalty.card creation/update.
	 *
	 * @param array<string, mixed> $data       Balance data from load_balance().
	 * @param int                  $program_id Odoo loyalty.program ID.
	 * @param int                  $partner_id Odoo partner ID.
	 * @return array<string, mixed> Formatted for Odoo loyalty.card.
	 */
	public function format_balance_for_odoo( array $data, int $program_id, int $partner_id ): array {
		return [
			'partner_id' => $partner_id,
			'program_id' => $program_id,
			'points'     => (float) ( $data['points'] ?? 0 ),
		];
	}

	/**
	 * Parse balance data from Odoo loyalty.card record.
	 *
	 * @param array<string, mixed> $odoo_data Raw Odoo record.
	 * @return array<string, mixed> Parsed data for save_balance().
	 */
	public function parse_balance_from_odoo( array $odoo_data ): array {
		return [
			'points' => (int) round( (float) ( $odoo_data['points'] ?? 0 ) ),
		];
	}

	/**
	 * Save a point balance to WordPress.
	 *
	 * Sets the WC points balance for the given user. The 'odoo-sync' event
	 * type is used so that the WC Points log records the source.
	 *
	 * @param array<string, mixed> $data  Parsed data with 'points' key.
	 * @param int                  $wp_id WordPress user ID.
	 * @return int The user ID on success, 0 on failure.
	 */
	public function save_balance( array $data, int $wp_id ): int {
		if ( $wp_id <= 0 ) {
			return 0;
		}

		$points = (int) ( $data['points'] ?? 0 );

		\WC_Points_Rewards_Manager::set_points_balance( $wp_id, $points, 'odoo-sync' );

		$this->logger->info(
			'Set WC points balance from Odoo.',
			[
				'user_id' => $wp_id,
				'points'  => $points,
			]
		);

		return $wp_id;
	}
}
