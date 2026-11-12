<?php
declare( strict_types=1 );

namespace WP4Odoo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI module management subcommands.
 *
 * Provides list, enable, and disable operations for plugin modules.
 * Used by the CLI class via trait composition.
 *
 * @package WP4Odoo
 * @since   3.3.0
 */
trait CLI_Module_Commands {

	/**
	 * List all modules with their status.
	 *
	 * @return void
	 */
	private function module_list(): void {
		$plugin   = \WP4Odoo_Plugin::instance();
		$modules  = $plugin->get_modules();
		$settings = $plugin->settings();

		if ( empty( $modules ) ) {
			\WP_CLI::line( __( 'No modules registered.', 'wp4odoo' ) );
			return;
		}

		$rows = [];
		foreach ( $modules as $id => $module ) {
			$rows[] = [
				'id'     => $id,
				'name'   => $module->get_name(),
				'status' => $settings->is_module_enabled( $id ) ? 'enabled' : 'disabled',
			];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'id', 'name', 'status' ] );
	}

	/**
	 * Enable or disable a module.
	 *
	 * @param string $id      Module identifier.
	 * @param bool   $enabled True to enable, false to disable.
	 * @return void
	 */
	private function module_toggle( string $id, bool $enabled ): void {
		if ( empty( $id ) ) {
			\WP_CLI::error( __( 'Please provide a module ID. Usage: wp wp4odoo module enable <id>', 'wp4odoo' ) );
		}

		$plugin  = \WP4Odoo_Plugin::instance();
		$modules = $plugin->get_modules();
		if ( ! isset( $modules[ $id ] ) ) {
			/* translators: %s: module identifier */
			\WP_CLI::error( sprintf( __( 'Unknown module: %s. Use "wp wp4odoo module list" to see available modules.', 'wp4odoo' ), $id ) );
		}

		// When enabling, check for mutual exclusivity conflicts.
		if ( $enabled ) {
			$registry  = $plugin->module_registry();
			$conflicts = $registry->get_conflicts( $id );
			if ( ! empty( $conflicts ) ) {
				\WP_CLI::warning(
					sprintf(
						/* translators: 1: module identifier, 2: conflicting module identifiers */
						__( 'Module "%1$s" conflicts with currently enabled module(s): %2$s. They will be disabled.', 'wp4odoo' ),
						$id,
						implode( ', ', $conflicts )
					)
				);
				foreach ( $conflicts as $conflict_id ) {
					$plugin->settings()->set_module_enabled( $conflict_id, false );
					/* translators: %s: conflicting module identifier */
					\WP_CLI::line( sprintf( __( '  Disabled conflicting module: %s', 'wp4odoo' ), $conflict_id ) );
				}
			}
		}

		$plugin->settings()->set_module_enabled( $id, $enabled );

		if ( $enabled ) {
			/* translators: %s: module identifier */
			\WP_CLI::success( sprintf( __( 'Module "%s" enabled.', 'wp4odoo' ), $id ) );
		} else {
			/* translators: %s: module identifier */
			\WP_CLI::success( sprintf( __( 'Module "%s" disabled.', 'wp4odoo' ), $id ) );
		}
	}

	/**
	 * Fetch a registered module or stop with an error.
	 *
	 * @param string $id Module identifier.
	 * @return Module_Base|null The module, or null after an error was reported.
	 */
	private function find_module_or_error( string $id ): ?Module_Base {
		if ( '' === $id ) {
			\WP_CLI::error( __( 'Please provide a module ID.', 'wp4odoo' ) );
			return null;
		}

		$modules = \WP4Odoo_Plugin::instance()->get_modules();
		if ( ! isset( $modules[ $id ] ) ) {
			/* translators: %s: module identifier */
			\WP_CLI::error( sprintf( __( 'Unknown module: %s. Use "wp wp4odoo module list" to see available modules.', 'wp4odoo' ), $id ) );
			return null;
		}

		return $modules[ $id ];
	}

	/**
	 * Show a module's status, dependency check, Odoo models and effective settings.
	 *
	 * @param string $id Module identifier.
	 * @return void
	 */
	private function module_info( string $id ): void {
		$module = $this->find_module_or_error( $id );
		if ( null === $module ) {
			return;
		}

		$settings   = \WP4Odoo_Plugin::instance()->settings();
		$dependency = $module->get_dependency_status();

		$rows = [
			[
				'property' => 'id',
				'value'    => $id,
			],
			[
				'property' => 'name',
				'value'    => $module->get_name(),
			],
			[
				'property' => 'status',
				'value'    => $settings->is_module_enabled( $id ) ? 'enabled' : 'disabled',
			],
			[
				'property' => 'dependency',
				'value'    => $dependency['available'] ? 'available' : 'unavailable',
			],
			[
				'property' => 'sync direction',
				'value'    => $module->get_sync_direction(),
			],
			[
				'property' => 'exclusive group',
				'value'    => '' !== $module->get_exclusive_group() ? $module->get_exclusive_group() : '-',
			],
			[
				'property' => 'odoo models',
				'value'    => implode( ', ', array_unique( array_values( $module->get_odoo_models() ) ) ),
			],
		];
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'property', 'value' ] );

		foreach ( $dependency['notices'] as $notice ) {
			if ( 'error' === $notice['type'] ) {
				\WP_CLI::warning( $notice['message'] );
			} else {
				\WP_CLI::line( '  - ' . $notice['message'] );
			}
		}
	}

	/**
	 * List a module's effective settings (defaults overridden by stored values).
	 *
	 * @param string $id Module identifier.
	 * @return void
	 */
	private function module_settings( string $id ): void {
		$module = $this->find_module_or_error( $id );
		if ( null === $module ) {
			return;
		}

		$effective = array_merge( $module->get_default_settings(), \WP4Odoo_Plugin::instance()->settings()->get_module_settings( $id ) );
		$rows      = [];
		foreach ( $effective as $key => $value ) {
			$rows[] = [
				'setting' => (string) $key,
				'value'   => is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ),
				'type'    => get_debug_type( $module->get_default_settings()[ $key ] ?? $value ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'setting', 'value', 'type' ] );
	}

	/**
	 * Change one module setting (scalar settings only; mappings are edited in the admin UI).
	 *
	 * @param string $id    Module identifier.
	 * @param string $key   Setting name.
	 * @param string $value New value.
	 * @return void
	 */
	private function module_set( string $id, string $key, string $value ): void {
		$module = $this->find_module_or_error( $id );
		if ( null === $module ) {
			return;
		}

		$defaults = $module->get_default_settings();
		if ( ! array_key_exists( $key, $defaults ) ) {
			/* translators: 1: setting name, 2: module ID, 3: available setting names */
			\WP_CLI::error( sprintf( __( 'Unknown setting "%1$s" for module "%2$s". Available: %3$s', 'wp4odoo' ), $key, $id, implode( ', ', array_keys( $defaults ) ) ) );
			return;
		}

		$default = $defaults[ $key ];
		if ( is_bool( $default ) ) {
			$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			if ( null === $parsed ) {
				\WP_CLI::error( __( 'This setting is a boolean: use true or false.', 'wp4odoo' ) );
				return;
			}
			$new = $parsed;
		} elseif ( is_int( $default ) ) {
			if ( ! is_numeric( $value ) ) {
				\WP_CLI::error( __( 'This setting is a number.', 'wp4odoo' ) );
				return;
			}
			$new = (int) $value;
		} elseif ( is_array( $default ) ) {
			\WP_CLI::error( __( 'This setting is a list or map; edit it in the Modules tab.', 'wp4odoo' ) );
			return;
		} else {
			$new = sanitize_text_field( $value );
		}

		$settings       = \WP4Odoo_Plugin::instance()->settings();
		$stored         = $settings->get_module_settings( $id );
		$stored[ $key ] = $new;
		$settings->save_module_settings( $id, array_merge( $defaults, $stored ) );

		/* translators: 1: setting name, 2: module ID */
		\WP_CLI::success( sprintf( __( 'Setting "%1$s" of module "%2$s" updated.', 'wp4odoo' ), $key, $id ) );
	}
}
