<?php
declare( strict_types=1 );

/**
 * Known, accepted gaps for the compatibility run (see README.md).
 *
 * Anything that fails and is not listed here fails the run. Each entry carries
 * the reason, so the list doubles as documentation of what is not supported.
 *
 * @return array{
 *     plugins: array<string, string>,
 *     models_absent: array<string, string>,
 *     fields_absent: array<string, string>,
 * }
 */
return [

	// Module id => why it cannot be verified in this CI environment.
	'plugins'       => [
		'wcfm'         => 'The marketplace add-on needs the WCFM core plugin (wc-frontend-manager), which is not installed by the run.',
		'supportcandy' => 'SupportCandy creates its tables on its first admin setup, not on activation.',
		'wperp'        => 'WP ERP creates its HR tables in its setup wizard.',
		'wperp_crm'    => 'WP ERP creates its CRM tables in its setup wizard.',
	],

	// Odoo models missing from the Community test instances (Enterprise apps or removed apps).
	'models_absent' => [
		'helpdesk.ticket'              => 'Odoo Enterprise (Helpdesk).',
		'documents.document'           => 'Odoo Enterprise (Documents).',
		'documents.folder'             => 'Odoo Enterprise (Documents).',
		'knowledge.article'            => 'Odoo Enterprise (Knowledge).',
		'field_service.task'           => 'Odoo Enterprise (Field Service).',
		'sale.subscription'            => 'Odoo Enterprise (Subscriptions).',
		'membership.membership_line'   => 'The Members app does not exist in Odoo 18+.',
	],

	// "model.field" mapped by a module but absent from the standard Odoo schema.
	// The orchestrator drops such fields (or renames them via $odoo_field_renames) instead of failing the push.
	'fields_absent' => [
		'res.partner.company_name'                                  => 'Removed in Odoo 20.',
		'crm.lead.mobile'                                           => 'Removed from crm.lead in Odoo 17+.',
		'hr.job.state'                                              => 'Removed from hr.job; the publish/recruit status mapping is lost.',
		'product.product.membership'                                => 'Belongs to the Members app, removed in Odoo 18+.',
		'stock.move.name'                                           => 'Removed from stock.move in recent Odoo versions.',
		'sale.order.is_rental'                                      => 'Odoo Rental app (Enterprise).',
		'sale.order.pickup_date'                                    => 'Odoo Rental app (Enterprise).',
		'sale.order.return_date'                                    => 'Odoo Rental app (Enterprise).',
		'product.template.attribute.line.attribute_line_ids'        => 'WC Add-ons maps a product.template field onto the attribute-line model; to be reviewed.',
	],
];
