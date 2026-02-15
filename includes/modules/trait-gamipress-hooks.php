<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GamiPress hook callbacks for push operations.
 *
 * Extracted from GamiPress_Module for single responsibility.
 * Handles point awards/deductions, achievement earns, and rank changes.
 *
 * Expects the using class to provide:
 * - is_importing(): bool           (from Module_Base)
 * - get_mapping(): ?int            (from Module_Base)
 * - get_settings(): array          (from Module_Base)
 * - push_entity(): void            (from Module_Helpers)
 * - logger: Logger                 (from Module_Base)
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
trait GamiPress_Hooks {

	/**
	 * Handle points awarded to a user.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $points      Points awarded.
	 * @param string $points_type Points type slug.
	 * @param array  $args        Additional arguments.
	 * @return void
	 */
	public function on_points_awarded( int $user_id, int $points, string $points_type, array $args = [] ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$this->push_entity( 'points', 'sync_points', $user_id );
	}

	/**
	 * Handle points deducted from a user.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param int    $points      Points deducted.
	 * @param string $points_type Points type slug.
	 * @param array  $args        Additional arguments.
	 * @return void
	 */
	public function on_points_deducted( int $user_id, int $points, string $points_type, array $args = [] ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$this->push_entity( 'points', 'sync_points', $user_id );
	}

	/**
	 * Handle achievement earned by a user.
	 *
	 * Enqueues the achievement type (post) for push, not the user earning.
	 *
	 * @param int   $user_id        WordPress user ID.
	 * @param int   $achievement_id Achievement post ID.
	 * @param mixed $trigger        The trigger that caused the award.
	 * @param int   $site_id        Site ID.
	 * @param mixed $args           Additional arguments.
	 * @return void
	 */
	public function on_achievement_earned( int $user_id, int $achievement_id, $trigger = '', int $site_id = 0, $args = [] ): void {
		if ( $achievement_id <= 0 ) {
			return;
		}

		$this->push_entity( 'achievement', 'sync_achievements', $achievement_id );
	}

	/**
	 * Handle rank earned by a user.
	 *
	 * Enqueues the rank type (post) for push.
	 *
	 * @param int   $user_id     WordPress user ID.
	 * @param int   $new_rank_id New rank post ID.
	 * @param int   $old_rank_id Old rank post ID.
	 * @param mixed $args        Additional arguments.
	 * @return void
	 */
	public function on_rank_earned( int $user_id, int $new_rank_id, int $old_rank_id = 0, $args = [] ): void {
		if ( $new_rank_id <= 0 ) {
			return;
		}

		$this->push_entity( 'rank', 'sync_ranks', $new_rank_id );
	}
}
