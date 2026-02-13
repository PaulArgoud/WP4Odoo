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
	 * When enabling a module, checks whether the Odoo models it requires
	 * are available on the connected instance. Returns a warning (non-blocking)
	 * if any required models are missing.
	 *
	 * @return void
	 */
	public function toggle_module(): void {
		$this->verify_request();

		$module_id = $this->get_post_field( 'module_id', 'key' );
		$enabled   = $this->get_post_field( 'enabled', 'bool' );

		if ( empty( $module_id ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Missing module identifier.', 'wp4odoo' ),
				]
			);
		}

		wp4odoo()->settings()->set_module_enabled( $module_id, $enabled );

		$response = [
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
		];

		// When enabling, auto-disable conflicting modules and check Odoo models.
		if ( $enabled ) {
			$module = \WP4Odoo_Plugin::instance()->get_module( $module_id );

			if ( $module ) {
				// Exclusive group: auto-disable conflicting modules.
				$group = $module->get_exclusive_group();
				if ( '' !== $group ) {
					$registry  = \WP4Odoo_Plugin::instance()->module_registry();
					$conflicts = $registry->get_conflicts( $module_id );

					if ( ! empty( $conflicts ) ) {
						$disabled_names = [];
						foreach ( $conflicts as $conflict_id ) {
							wp4odoo()->settings()->set_module_enabled( $conflict_id, false );
							$conflict_module  = $registry->get( $conflict_id );
							$disabled_names[] = $conflict_module ? $conflict_module->get_name() : $conflict_id;
						}

						$response['auto_disabled'] = $conflicts;
						$response['warning']       = sprintf(
							/* translators: 1: enabled module name, 2: comma-separated disabled module names */
							__( '"%1$s" is mutually exclusive with %2$s. The conflicting module(s) have been disabled.', 'wp4odoo' ),
							$module->get_name(),
							implode( ', ', $disabled_names )
						);
					}
				}

				// Check required Odoo models.
				$required = array_values( array_unique( $module->get_odoo_models() ) );

				if ( ! empty( $required ) ) {
					try {
						$client    = \WP4Odoo_Plugin::instance()->client();
						$records   = $client->search_read(
							'ir.model',
							[ [ 'model', 'in', $required ] ],
							[ 'model' ]
						);
						$available = array_column( $records, 'model' );
						$missing   = array_values( array_diff( $required, $available ) );

						if ( ! empty( $missing ) ) {
							$model_warning = $this->format_missing_model_warning( $missing );
							// Append to existing warning if conflicts were found.
							if ( isset( $response['warning'] ) ) {
								$response['warning'] .= ' ' . $model_warning;
							} else {
								$response['warning'] = $model_warning;
							}
						}
					} catch ( \Throwable $e ) {
						// Connection not configured or failed — skip model check.
					}
				}
			}
		}

		wp_send_json_success( $response );
	}

	/**
	 * Save a module's settings.
	 *
	 * @return void
	 */
	public function save_module_settings(): void {
		$this->verify_request();

		$module_id = $this->get_post_field( 'module_id', 'key' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_request() above.
		$settings = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];

		if ( empty( $module_id ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Missing module identifier.', 'wp4odoo' ),
				]
			);
		}

		// Validate module exists.
		$modules = \WP4Odoo_Plugin::instance()->get_modules();
		if ( ! isset( $modules[ $module_id ] ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Unknown module.', 'wp4odoo' ),
				]
			);
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
						$allowed       = array_keys( $field['options'] ?? [] );
						$val           = sanitize_text_field( $settings[ $key ] );
						$clean[ $key ] = in_array( $val, $allowed, true ) ? $val : ( $defaults[ $key ] ?? '' );
						break;
					case 'languages':
						$raw           = sanitize_text_field( $settings[ $key ] );
						$clean[ $key ] = '' === $raw ? [] : array_map( 'sanitize_key', explode( ',', $raw ) );
						break;
					default:
						$clean[ $key ] = sanitize_text_field( $settings[ $key ] );
						break;
				}
			} elseif ( 'checkbox' === $field['type'] ) {
				// Checkbox not sent = unchecked.
				$clean[ $key ] = false;
			}
		}

		wp4odoo()->settings()->save_module_settings( $module_id, $clean );

		wp_send_json_success(
			[
				'message' => __( 'Settings saved.', 'wp4odoo' ),
			]
		);
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
			wp_send_json_error(
				[
					'message' => __( 'WooCommerce module is not registered.', 'wp4odoo' ),
				]
			);
		}

		$handler = new Bulk_Handler( $plugin->client(), new \WP4Odoo\Entity_Map_Repository() );
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
			wp_send_json_error(
				[
					'message' => __( 'WooCommerce module is not registered.', 'wp4odoo' ),
				]
			);
		}

		$handler = new Bulk_Handler( $plugin->client(), new \WP4Odoo\Entity_Map_Repository() );
		wp_send_json_success( $handler->export_products() );
	}

	/**
	 * Detect translation languages and Odoo availability.
	 *
	 * Probes the active translation plugin (WPML/Polylang) for available
	 * languages, then checks which ones are installed in Odoo via res.lang.
	 *
	 * @since 3.0.5
	 *
	 * @return void
	 */
	public function detect_languages(): void {
		$this->verify_request();

		$ts = new \WP4Odoo\I18n\Translation_Service(
			static fn() => \WP4Odoo_Plugin::instance()->client()
		);

		$result = $ts->detect_languages();

		if ( null === $result ) {
			wp_send_json_success(
				[
					'available' => false,
					'message'   => __( 'No translation plugin detected (WPML or Polylang required).', 'wp4odoo' ),
				]
			);
		}

		wp_send_json_success(
			[
				'available' => true,
				'plugin'    => $result['plugin'],
				'default'   => $result['default_language'],
				'languages' => $result['languages'],
			]
		);
	}
}
