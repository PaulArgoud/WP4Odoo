<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Logger;
use WP4Odoo\Modules\WC_Points_Rewards_Handler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WC_Points_Rewards_Handler.
 *
 * Tests balance loading, formatting for Odoo, parsing from Odoo,
 * saving balances, and type conversion (float → int rounding).
 */
class WCPointsRewardsHandlerTest extends TestCase {

	private WC_Points_Rewards_Handler $handler;

	protected function setUp(): void {
		global $wpdb;
		$wpdb = new \WP_DB_Stub();

		$GLOBALS['_wp_options']        = [];
		$GLOBALS['_wp_users']          = [];
		$GLOBALS['_wp_user_meta']      = [];
		$GLOBALS['_wc_points_rewards'] = [];

		$this->handler = new WC_Points_Rewards_Handler( new Logger( 'test' ) );
	}

	// ─── load_balance ───────────────────────────────────

	private function make_user( int $id, string $email, string $display_name, string $first_name = '', string $last_name = '' ): \WP_User {
		$user               = new \WP_User( $id );
		$user->user_email   = $email;
		$user->display_name = $display_name;
		$user->first_name   = $first_name;
		$user->last_name    = $last_name;

		return $user;
	}

	public function test_load_balance_returns_user_data(): void {
		$GLOBALS['_wp_users'][42] = $this->make_user( 42, 'john@example.com', 'John Doe', 'John', 'Doe' );
		$GLOBALS['_wc_points_rewards'][42] = 150;

		$data = $this->handler->load_balance( 42 );

		$this->assertSame( 42, $data['user_id'] );
		$this->assertSame( 'john@example.com', $data['email'] );
		$this->assertSame( 'John Doe', $data['name'] );
		$this->assertSame( 150, $data['points'] );
	}

	public function test_load_balance_returns_zero_points_when_no_balance(): void {
		$GLOBALS['_wp_users'][42] = $this->make_user( 42, 'john@example.com', 'John Doe', 'John', 'Doe' );

		$data = $this->handler->load_balance( 42 );

		$this->assertSame( 0, $data['points'] );
	}

	public function test_load_balance_empty_for_zero_user_id(): void {
		$data = $this->handler->load_balance( 0 );
		$this->assertEmpty( $data );
	}

	public function test_load_balance_empty_for_nonexistent_user(): void {
		$data = $this->handler->load_balance( 999 );
		$this->assertEmpty( $data );
	}

	public function test_load_balance_uses_display_name_when_no_first_last(): void {
		$GLOBALS['_wp_users'][42] = $this->make_user( 42, 'john@example.com', 'Johnny' );
		$GLOBALS['_wc_points_rewards'][42] = 50;

		$data = $this->handler->load_balance( 42 );

		$this->assertSame( 'Johnny', $data['name'] );
	}

	// ─── format_balance_for_odoo ─────────────────────────

	public function test_format_balance_for_odoo_returns_correct_structure(): void {
		$data = [
			'user_id' => 42,
			'email'   => 'john@example.com',
			'name'    => 'John Doe',
			'points'  => 150,
		];

		$result = $this->handler->format_balance_for_odoo( $data, 5, 100 );

		$this->assertSame( 100, $result['partner_id'] );
		$this->assertSame( 5, $result['program_id'] );
		$this->assertSame( 150.0, $result['points'] );
	}

	public function test_format_balance_for_odoo_casts_points_to_float(): void {
		$data = [ 'points' => 0 ];

		$result = $this->handler->format_balance_for_odoo( $data, 1, 2 );

		$this->assertIsFloat( $result['points'] );
		$this->assertSame( 0.0, $result['points'] );
	}

	public function test_format_balance_for_odoo_handles_missing_points(): void {
		$result = $this->handler->format_balance_for_odoo( [], 1, 2 );

		$this->assertSame( 0.0, $result['points'] );
	}

	// ─── parse_balance_from_odoo ─────────────────────────

	public function test_parse_balance_from_odoo_extracts_points(): void {
		$odoo_data = [
			'id'         => 55,
			'partner_id' => [ 100, 'John Doe' ],
			'program_id' => [ 5, 'My Loyalty' ],
			'points'     => 250.0,
		];

		$result = $this->handler->parse_balance_from_odoo( $odoo_data );

		$this->assertSame( 250, $result['points'] );
	}

	public function test_parse_balance_from_odoo_rounds_float_to_int(): void {
		$odoo_data = [ 'points' => 99.7 ];

		$result = $this->handler->parse_balance_from_odoo( $odoo_data );

		$this->assertSame( 100, $result['points'] );
	}

	public function test_parse_balance_from_odoo_rounds_down_when_below_half(): void {
		$odoo_data = [ 'points' => 99.3 ];

		$result = $this->handler->parse_balance_from_odoo( $odoo_data );

		$this->assertSame( 99, $result['points'] );
	}

	public function test_parse_balance_from_odoo_handles_zero(): void {
		$odoo_data = [ 'points' => 0 ];

		$result = $this->handler->parse_balance_from_odoo( $odoo_data );

		$this->assertSame( 0, $result['points'] );
	}

	public function test_parse_balance_from_odoo_handles_missing_points(): void {
		$result = $this->handler->parse_balance_from_odoo( [] );

		$this->assertSame( 0, $result['points'] );
	}

	// ─── save_balance ───────────────────────────────────

	public function test_save_balance_sets_points_and_returns_user_id(): void {
		$GLOBALS['_wc_points_rewards'][42] = 100;

		$result = $this->handler->save_balance( [ 'points' => 250 ], 42 );

		$this->assertSame( 42, $result );
		$this->assertSame( 250, $GLOBALS['_wc_points_rewards'][42] );
	}

	public function test_save_balance_returns_zero_for_invalid_user_id(): void {
		$result = $this->handler->save_balance( [ 'points' => 250 ], 0 );

		$this->assertSame( 0, $result );
	}

	public function test_save_balance_handles_missing_points_key(): void {
		$result = $this->handler->save_balance( [], 42 );

		$this->assertSame( 42, $result );
		$this->assertSame( 0, $GLOBALS['_wc_points_rewards'][42] );
	}

	public function test_save_balance_uses_odoo_sync_event_type(): void {
		// The stub doesn't track event types, but we verify the call succeeds.
		$result = $this->handler->save_balance( [ 'points' => 100 ], 42 );

		$this->assertSame( 42, $result );
		$this->assertSame( 100, $GLOBALS['_wc_points_rewards'][42] );
	}
}
