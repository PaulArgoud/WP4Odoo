<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use WP4Odoo\Odoo_Model;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Odoo_Model backed enum.
 */
class OdooModelTest extends TestCase {

	public function test_partner_value(): void {
		$this->assertSame( 'res.partner', Odoo_Model::Partner->value );
	}

	public function test_account_move_value(): void {
		$this->assertSame( 'account.move', Odoo_Model::AccountMove->value );
	}

	public function test_sale_order_value(): void {
		$this->assertSame( 'sale.order', Odoo_Model::SaleOrder->value );
	}

	public function test_product_template_value(): void {
		$this->assertSame( 'product.template', Odoo_Model::ProductTemplate->value );
	}

	public function test_product_product_value(): void {
		$this->assertSame( 'product.product', Odoo_Model::ProductProduct->value );
	}

	public function test_donation_value(): void {
		$this->assertSame( 'donation.donation', Odoo_Model::Donation->value );
	}

	public function test_event_event_value(): void {
		$this->assertSame( 'event.event', Odoo_Model::EventEvent->value );
	}

	public function test_calendar_event_value(): void {
		$this->assertSame( 'calendar.event', Odoo_Model::CalendarEvent->value );
	}

	public function test_ir_model_value(): void {
		$this->assertSame( 'ir.model', Odoo_Model::IrModel->value );
	}

	public function test_try_from_valid_string(): void {
		$model = Odoo_Model::tryFrom( 'res.partner' );
		$this->assertSame( Odoo_Model::Partner, $model );
	}

	public function test_try_from_invalid_string(): void {
		$model = Odoo_Model::tryFrom( 'nonexistent.model' );
		$this->assertNull( $model );
	}

	public function test_all_cases_have_dot_notation(): void {
		foreach ( Odoo_Model::cases() as $case ) {
			$this->assertStringContainsString( '.', $case->value, "Enum case {$case->name} should use Odoo dot notation" );
		}
	}
}
