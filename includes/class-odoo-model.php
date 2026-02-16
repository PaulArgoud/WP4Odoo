<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Odoo model name constants.
 *
 * String-backed enum listing every Odoo model used by WP4Odoo modules.
 * Eliminates typo risk in hardcoded model name strings and provides
 * IDE autocompletion across all modules.
 *
 * Usage: `Odoo_Model::Partner->value` → `'res.partner'`
 *
 * @package WP4Odoo
 * @since   2.9.5
 */
enum Odoo_Model: string {

	// ─── Core ──────────────────────────────────────────────

	/** Contacts / Partners. */
	case Partner = 'res.partner';

	/** Currency. */
	case Currency = 'res.currency';

	/** Introspection model (used for probing). */
	case IrModel = 'ir.model';

	// ─── CRM ───────────────────────────────────────────────

	/** CRM leads / opportunities. */
	case Lead = 'crm.lead';

	/** CRM stages (pipeline). */
	case CrmStage = 'crm.stage';

	// ─── Sales ─────────────────────────────────────────────

	/** Sale orders. */
	case SaleOrder = 'sale.order';

	/** Sale subscriptions (Odoo Enterprise). */
	case SaleSubscription = 'sale.subscription';

	// ─── Products ──────────────────────────────────────────

	/** Product templates (parent product). */
	case ProductTemplate = 'product.template';

	/** Product variants. */
	case ProductProduct = 'product.product';

	/** Product pricelists. */
	case ProductPricelist = 'product.pricelist';

	/** Product pricelist items (rules). */
	case ProductPricelistItem = 'product.pricelist.item';

	// ─── Accounting ────────────────────────────────────────

	/** Invoices / Bills / Journal entries. */
	case AccountMove = 'account.move';

	/** Payments. */
	case AccountPayment = 'account.payment';

	/** Tax definitions (customer/vendor). */
	case AccountTax = 'account.tax';

	// ─── Delivery ──────────────────────────────────────────

	/** Delivery carriers (shipping methods). */
	case DeliveryCarrier = 'delivery.carrier';

	// ─── Donations (OCA) ───────────────────────────────────

	/** OCA donation model. */
	case Donation = 'donation.donation';

	// ─── Memberships ───────────────────────────────────────

	/** Membership lines. */
	case MembershipLine = 'membership.membership_line';

	// ─── Calendar / Events ─────────────────────────────────

	/** Calendar events. */
	case CalendarEvent = 'calendar.event';

	/** Events (event module). */
	case EventEvent = 'event.event';

	/** Event registrations / attendees. */
	case EventRegistration = 'event.registration';

	// ─── HR ────────────────────────────────────────────────

	/** Job positions. */
	case HrJob = 'hr.job';

	/** HR departments. */
	case HrDepartment = 'hr.department';

	/** HR employees. */
	case HrEmployee = 'hr.employee';

	/** HR leave requests (time off). */
	case HrLeave = 'hr.leave';

	// ─── Loyalty ───────────────────────────────────────────

	/** Loyalty programs. */
	case LoyaltyProgram = 'loyalty.program';

	/** Loyalty cards (customer point balances). */
	case LoyaltyCard = 'loyalty.card';

	// ─── Helpdesk ──────────────────────────────────────────

	/** Helpdesk tickets (Enterprise). */
	case HelpdeskTicket = 'helpdesk.ticket';

	/** Helpdesk stages (Enterprise). */
	case HelpdeskStage = 'helpdesk.stage';

	// ─── Project ───────────────────────────────────────────

	/** Project tasks (Community fallback for tickets). */
	case ProjectTask = 'project.task';

	/** Project task stages (Community). */
	case ProjectTaskType = 'project.task.type';

	// ─── Manufacturing ─────────────────────────────────────

	/** Bills of Materials. */
	case MrpBom = 'mrp.bom';

	/** BOM lines (components). */
	case MrpBomLine = 'mrp.bom.line';

	// ─── Inventory ─────────────────────────────────────────

	/** Stock pickings (delivery orders). */
	case StockPicking = 'stock.picking';

	// ─── Knowledge (Enterprise) ────────────────────────────

	/** Knowledge articles (Odoo Enterprise 16+). */
	case KnowledgeArticle = 'knowledge.article';

	// ─── Email Marketing ───────────────────────────────────

	/** Mailing contacts (subscribers). @since 3.5.0 */
	case MailingContact = 'mailing.contact';

	/** Mailing lists. @since 3.5.0 */
	case MailingList = 'mailing.list';

	// ─── Purchasing ───────────────────────────────────────

	/** Purchase orders. @since 3.5.0 */
	case PurchaseOrder = 'purchase.order';

	// ─── B2B ──────────────────────────────────────────────

	/** Payment terms. @since 3.5.0 */
	case AccountPaymentTerm = 'account.payment.term';

	/** Partner categories (tags). @since 3.5.0 */
	case PartnerCategory = 'res.partner.category';

	/** Product categories. @since 3.5.0 */
	case ProductCategory = 'product.category';
}
