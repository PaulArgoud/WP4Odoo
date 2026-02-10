<?php
declare( strict_types=1 );

namespace WP4Odoo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX handlers for module management and bulk operations.
 *
 * Used by Admin_Ajax via trait composition.
 *
 * @package WP4Odoo
 * @since   1.9.6
 */
trait Ajax_Module_Handlers {

	/**
	 * Toggle a module's enabled state.
	 *
	 * @return void
	 */
	public function toggle_module(): void {
		$this->verify_request();

		$module_id = $this->get_post_field( 'module_id', 'key' );
		$enabled   = $this->get_post_field( 'enabled', 'bool' );

		if ( empty( $module_id ) ) {
			wp_send_json_error( [
				'message' => __( 'Missing module identifier.', 'wp4odoo' ),
			] );
		}

		update_option( 'wp4odoo_module_' . $module_id . '_enabled', $enabled );

		wp_send_json_success( [
			'module_id' => $module_id,
			'enabled'   => $enabled,
			'message'   => $enabled
				? sprintf(
					/* translators: %s: module identifier */
					__( 'Module "%s" enabled.', 'wp4odoo' ),
					$module_id
				)
				: sprintf(
					/* translators: %s: module identifier */
					__( 'Module "%s" disabled.', 'wp4odoo' ),
					$module_id
				),
		] );
	}

	/**
	 * Save a module's settings.
	 *
	 * @return void
	 */
	public function save_module_settings(): void {
		$this->verify_request();

		$module_id = $this->get_post_field( 'module_id', 'key' );
		$settings  = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];

		if ( empty( $module_id ) ) {
			wp_send_json_error( [
				'message' => __( 'Missing module identifier.', 'wp4odoo' ),
			] );
		}

		// Validate module exists.
		$modules = \WP4Odoo_Plugin::instance()->get_modules();
		if ( ! isset( $modules[ $module_id ] ) ) {
			wp_send_json_error( [
				'message' => __( 'Unknown module.', 'wp4odoo' ),
			] );
		}

		$module   = $modules[ $module_id ];
		$fields   = $module->get_settings_fields();
		$defaults = $module->get_default_settings();
		$clean    = [];

		foreach ( $fields as $key => $field ) {
			if ( isset( $settings[ $key ] ) ) {
				switch ( $field['type'] ) {
					case 'checkbox':
						$clean[ $key ] = ! empty( $settings[ $key ] );
						break;
					case 'number':
						$clean[ $key ] = absint( $settings[ $key ] );
						break;
					case 'select':
						$allowed = array_keys( $field['options'] ?? [] );
						$val     = sanitize_text_field( $settings[ $key ] );
						$clean[ $key ] = in_array( $val, $allowed, true ) ? $val : ( $defaults[ $key ] ?? '' );
						break;
					default:
						$clean[ $key ] = sanitize_text_field( $settings[ $key ] );
						break;
				}
			} else {
				// Checkbox not sent = unchecked.
				if ( 'checkbox' === $field['type'] ) {
					$clean[ $key ] = false;
				}
			}
		}

		update_option( 'wp4odoo_module_' . $module_id . '_settings', $clean );

		wp_send_json_success( [
			'message' => __( 'Settings saved.', 'wp4odoo' ),
		] );
	}

	/**
	 * Bulk import all products from Odoo into WooCommerce.
	 *
	 * @return void
	 */
	public function bulk_import_products(): void {
		$this->verify_request();

		$plugin = \WP4Odoo_Plugin::instance();
		if ( null === $plugin->get_module( 'woocommerce' ) ) {
			wp_send_json_error( [
				'message' => __( 'WooCommerce module is not registered.', 'wp4odoo' ),
			] );
		}

		$handler = new Bulk_Handler( $plugin->client() );
		wp_send_json_success( $handler->import_products() );
	}

	/**
	 * Bulk export all WooCommerce products to Odoo.
	 *
	 * @return void
	 */
	public function bulk_export_products(): void {
		$this->verify_request();

		$plugin = \WP4Odoo_Plugin::instance();
		if ( null === $plugin->get_module( 'woocommerce' ) ) {
			wp_send_json_error( [
				'message' => __( 'WooCommerce module is not registered.', 'wp4odoo' ),
			] );
		}

		$handler = new Bulk_Handler( $plugin->client() );
		wp_send_json_success( $handler->export_products() );
	}
}
