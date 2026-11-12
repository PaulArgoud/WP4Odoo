<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Modules\Crowdfunding_Handler;
use WP4Odoo\Logger;
use WP4Odoo\Tests\Module_Test_Case;

/**
 * Unit tests for Crowdfunding_Handler.
 *
 * Tests campaign loading, description building, crowdfunding detection,
 * and edge cases.
 *
 */
#[CoversClass(\WP4Odoo\Modules\Crowdfunding_Handler::class)]
class CrowdfundingHandlerTest extends Module_Test_Case {

	private Crowdfunding_Handler $handler;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wc_products'] = [];
		$this->handler           = new Crowdfunding_Handler( new Logger( 'test' ) );
	}

	// ─── Helpers ────────────────────────────────────────────

	/**
	 * Create a WC product stub in the global store.
	 *
	 * @param int    $id          Product ID.
	 * @param string $name        Product name.
	 * @param string $price       Product price.
	 * @param string $description Product description.
	 */
	private function create_product( int $id, string $name = 'Test Campaign', string $price = '100.00', string $description = '' ): void {
		$product = new \WC_Product( $id );
		$product->set_data( [
			'name'        => $name,
			'price'       => $price,
			'description' => $description,
		] );

		$GLOBALS['_wc_products'][ $id ] = $product;
	}

	/**
	 * Set crowdfunding meta for a product.
	 *
	 * @param int    $id          Product ID.
	 * @param float  $goal        Funding goal.
	 * @param string $end_date    End date.
	 * @param float  $min_amount  Minimum pledge amount.
	 */
	private function set_crowdfunding_meta( int $id, float $goal = 5000.0, string $end_date = '2026-12-31', float $min_amount = 10.0 ): void {
		$GLOBALS['_wp_post_meta'][ $id ] = [
			'_nf_funding_goal'           => (string) $goal,
			'_nf_duration_end'       => $end_date,
			'wpneo_funding_minimum_price' => (string) $min_amount,
		];
	}

	// ─── load_campaign ──────────────────────────────────────

	public function test_load_campaign_returns_data(): void {
		$this->create_product( 10, 'Save the Ocean' );
		$this->set_crowdfunding_meta( 10, 5000.0, '2026-12-31', 10.0 );

		$data = $this->handler->load_campaign( 10 );

		$this->assertSame( 'Save the Ocean', $data['campaign_name'] );
		$this->assertSame( 'service', $data['type'] );
	}

	public function test_load_campaign_uses_funding_goal_as_price(): void {
		$this->create_product( 10, 'Campaign', '50.00' );
		$this->set_crowdfunding_meta( 10, 5000.0 );

		$data = $this->handler->load_campaign( 10 );

		$this->assertSame( 5000.0, $data['list_price'] );
	}

	public function test_load_campaign_uses_product_price_when_goal_zero(): void {
		$this->create_product( 10, 'Campaign', '75.00' );
		$this->set_crowdfunding_meta( 10, 0.0, '', 0.0 );

		$data = $this->handler->load_campaign( 10 );

		$this->assertSame( 75.0, $data['list_price'] );
	}

	public function test_load_campaign_empty_for_nonexistent(): void {
		$this->assertSame( [], $this->handler->load_campaign( 999 ) );
	}

	public function test_load_campaign_includes_description_with_funding_info(): void {
		$this->create_product( 10, 'Campaign', '100.00', 'Help us build something great' );
		$this->set_crowdfunding_meta( 10, 5000.0, '2026-12-31', 25.0 );

		$data = $this->handler->load_campaign( 10 );

		$this->assertStringContainsString( 'Help us build something great', $data['description'] );
		$this->assertStringContainsString( '5,000.00', $data['description'] );
		$this->assertStringContainsString( '2026-12-31', $data['description'] );
		$this->assertStringContainsString( '25.00', $data['description'] );
	}

	public function test_load_campaign_description_only_funding_info_when_no_product_desc(): void {
		$this->create_product( 10, 'Campaign', '100.00', '' );
		$this->set_crowdfunding_meta( 10, 5000.0, '2026-12-31', 0.0 );

		$data = $this->handler->load_campaign( 10 );

		$this->assertStringContainsString( '5,000.00', $data['description'] );
		$this->assertStringContainsString( '2026-12-31', $data['description'] );
	}

	public function test_load_campaign_description_only_product_desc_when_no_funding_info(): void {
		$this->create_product( 10, 'Campaign', '100.00', 'Just a description' );
		$this->set_crowdfunding_meta( 10, 0.0, '', 0.0 );

		$data = $this->handler->load_campaign( 10 );

		$this->assertSame( 'Just a description', $data['description'] );
	}

	public function test_load_campaign_strips_html_from_description(): void {
		$this->create_product( 10, 'Campaign', '100.00', '<p>Bold <strong>text</strong></p>' );
		$this->set_crowdfunding_meta( 10, 0.0, '', 0.0 );

		$data = $this->handler->load_campaign( 10 );

		$this->assertStringNotContainsString( '<p>', $data['description'] );
		$this->assertStringNotContainsString( '<strong>', $data['description'] );
	}

	// ─── is_crowdfunding ────────────────────────────────────

	public function test_is_crowdfunding_true_when_goal_set(): void {
		$GLOBALS['_wp_post_meta'][10] = [
			'_nf_funding_goal' => '5000',
		];

		$this->assertTrue( $this->handler->is_crowdfunding( 10 ) );
	}

	public function test_is_crowdfunding_false_when_no_meta(): void {
		$this->assertFalse( $this->handler->is_crowdfunding( 99 ) );
	}

	public function test_is_crowdfunding_false_when_goal_empty(): void {
		$GLOBALS['_wp_post_meta'][10] = [
			'_nf_funding_goal' => '',
		];

		$this->assertFalse( $this->handler->is_crowdfunding( 10 ) );
	}

	public function test_is_crowdfunding_true_when_goal_zero_string(): void {
		$GLOBALS['_wp_post_meta'][10] = [
			'_nf_funding_goal' => '0',
		];

		$this->assertTrue( $this->handler->is_crowdfunding( 10 ) );
	}

	// ─── Edge cases ─────────────────────────────────────────

	public function test_load_campaign_with_no_meta_returns_product_price(): void {
		$this->create_product( 10, 'No Meta Campaign', '200.00' );
		// No crowdfunding meta set — defaults to zero.

		$data = $this->handler->load_campaign( 10 );

		$this->assertSame( 200.0, $data['list_price'] );
	}
}
