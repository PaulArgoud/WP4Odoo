<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accounting/invoice helpers for Module_Base.
 *
 * Provides invoice auto-posting after creation in Odoo.
 *
 * Mixed into Module_Base via Module_Helpers and accesses its
 * protected properties/methods through $this.
 *
 * @package WP4Odoo
 * @since   3.4.0
 */
trait Accounting_Helpers {

	/**
	 * Auto-post an invoice in Odoo after successful creation.
	 *
	 * Checks the module setting, retrieves the Odoo mapping, and calls
	 * `account.move.action_post`. Logs success or failure.
	 *
	 * Setting key convention: `auto_{odoo_verb}_{entity_noun}` where the verb
	 * matches the Odoo RPC method (confirm → action_confirm, post → action_post,
	 * validate → validate). Examples: auto_confirm_orders, auto_post_invoices,
	 * auto_validate_donations, auto_validate_payments.
	 *
	 * @param string $setting_key Settings key to check (e.g., 'auto_post_invoices').
	 * @param string $entity_type Entity type for mapping lookup.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool True if posted successfully, false on skip or error.
	 */
	protected function auto_post_invoice( string $setting_key, string $entity_type, int $wp_id ): bool {
		$settings = $this->get_settings();
		if ( empty( $settings[ $setting_key ] ) ) {
			return false;
		}

		$odoo_id = $this->get_mapping( $entity_type, $wp_id );
		if ( ! $odoo_id ) {
			return false;
		}

		return Modules\Odoo_Accounting_Formatter::auto_post(
			$this->client(),
			Odoo_Model::AccountMove->value,
			$odoo_id,
			$this->logger
		);
	}
}
