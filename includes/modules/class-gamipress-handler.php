<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GamiPress Handler — data access for points, achievements, and ranks.
 *
 * Loads GamiPress entity data, formats for Odoo, and provides save
 * methods for pull sync. Called by GamiPress_Module via its
 * load_wp_data / save_wp_data dispatch.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
class GamiPress_Handler {

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

	// ─── Points ────────────────────────────────────────────

	/**
	 * Load point balance data for a WordPress user.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $points_type GamiPress points type slug.
	 * @return array<string, mixed> Points data, or empty array if user not found.
	 */
	public function load_points( int $user_id, string $points_type ): array {
		if ( $user_id <= 0 ) {
			return [];
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			$this->logger->warning( 'GamiPress: user not found.', [ 'user_id' => $user_id ] );
			return [];
		}

		$points = gamipress_get_user_points( $user_id, $points_type );

		return [
			'user_id' => $user_id,
			'email'   => $user->user_email,
			'name'    => $user->display_name,
			'points'  => $points,
		];
	}

	/**
	 * Save a point balance from Odoo to WordPress.
	 *
	 * Sets the GamiPress points balance for the given user using
	 * award/deduct to reach the target balance.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $points      Target point balance.
	 * @param string $points_type GamiPress points type slug.
	 * @return int The user ID on success, 0 on failure.
	 */
	public function save_points( int $user_id, int $points, string $points_type ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$current = gamipress_get_user_points( $user_id, $points_type );
		$diff    = $points - $current;

		if ( $diff > 0 ) {
			gamipress_award_points_to_user( $user_id, $diff, $points_type, [ 'reason' => 'Odoo sync' ] );
		} elseif ( $diff < 0 ) {
			gamipress_deduct_points_to_user( $user_id, abs( $diff ), $points_type, [ 'reason' => 'Odoo sync' ] );
		}

		$this->logger->info(
			'Set GamiPress points from Odoo.',
			[
				'user_id' => $user_id,
				'points'  => $points,
			]
		);

		return $user_id;
	}

	// ─── Achievements ──────────────────────────────────────

	/**
	 * Load achievement data.
	 *
	 * @param int $achievement_id WordPress post ID.
	 * @return array<string, mixed> Achievement data, or empty array if not found.
	 */
	public function load_achievement( int $achievement_id ): array {
		if ( $achievement_id <= 0 ) {
			return [];
		}

		$post = get_post( $achievement_id );
		if ( ! $post ) {
			$this->logger->warning( 'GamiPress: achievement not found.', [ 'id' => $achievement_id ] );
			return [];
		}

		$post_type = get_post_type( $achievement_id );
		if ( ! is_string( $post_type ) || false === strpos( $post_type, 'achievement' ) ) {
			$this->logger->warning(
				'GamiPress: post is not an achievement type.',
				[
					'id'        => $achievement_id,
					'post_type' => $post_type,
				]
			);
			return [];
		}

		$points = get_post_meta( $achievement_id, '_gamipress_points', true );

		return [
			'id'          => $achievement_id,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'points'      => (int) ( $points ?: 0 ),
		];
	}

	// ─── Ranks ─────────────────────────────────────────────

	/**
	 * Load rank data.
	 *
	 * @param int $rank_id WordPress post ID.
	 * @return array<string, mixed> Rank data, or empty array if not found.
	 */
	public function load_rank( int $rank_id ): array {
		if ( $rank_id <= 0 ) {
			return [];
		}

		$post = get_post( $rank_id );
		if ( ! $post ) {
			$this->logger->warning( 'GamiPress: rank not found.', [ 'id' => $rank_id ] );
			return [];
		}

		$post_type = get_post_type( $rank_id );
		if ( ! is_string( $post_type ) || false === strpos( $post_type, 'rank' ) ) {
			$this->logger->warning(
				'GamiPress: post is not a rank type.',
				[
					'id'        => $rank_id,
					'post_type' => $post_type,
				]
			);
			return [];
		}

		$priority = get_post_meta( $rank_id, '_gamipress_priority', true );

		return [
			'id'          => $rank_id,
			'title'       => $post->post_title,
			'description' => $post->post_content,
			'priority'    => (int) ( $priority ?: 0 ),
		];
	}

	// ─── Formatting ────────────────────────────────────────

	/**
	 * Format loyalty card data for Odoo.
	 *
	 * @param int $points     Point balance.
	 * @param int $partner_id Odoo partner ID.
	 * @param int $program_id Odoo loyalty.program ID.
	 * @return array<string, mixed> Formatted for Odoo loyalty.card.
	 */
	public function format_loyalty_card( int $points, int $partner_id, int $program_id ): array {
		return [
			'partner_id' => $partner_id,
			'program_id' => $program_id,
			'points'     => (float) $points,
		];
	}

	/**
	 * Format achievement data as an Odoo product.
	 *
	 * @param array<string, mixed> $data Achievement data from load_achievement().
	 * @return array<string, mixed> Formatted for Odoo product.template.
	 */
	public function format_achievement_product( array $data ): array {
		return [
			'name'             => $data['title'] ?? '',
			'description_sale' => $data['description'] ?? '',
			'type'             => 'service',
			'sale_ok'          => false,
			'purchase_ok'      => false,
		];
	}

	/**
	 * Format rank data as an Odoo product.
	 *
	 * @param array<string, mixed> $data Rank data from load_rank().
	 * @return array<string, mixed> Formatted for Odoo product.template.
	 */
	public function format_rank_product( array $data ): array {
		return [
			'name'                 => $data['title'] ?? '',
			'description_sale'     => $data['description'] ?? '',
			'type'                 => 'service',
			'sale_ok'              => false,
			'purchase_ok'          => false,
			'x_gamipress_priority' => $data['priority'] ?? 0,
		];
	}
}
