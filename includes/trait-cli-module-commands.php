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
			\WP_CLI::line( 'No modules registered.' );
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
			\WP_CLI::error( 'Please provide a module ID. Usage: wp wp4odoo module enable <id>' );
		}

		$plugin  = \WP4Odoo_Plugin::instance();
		$modules = $plugin->get_modules();
		if ( ! isset( $modules[ $id ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown module: %s. Use "wp wp4odoo module list" to see available modules.', $id ) );
		}

		// When enabling, check for mutual exclusivity conflicts.
		if ( $enabled ) {
			$registry  = $plugin->module_registry();
			$conflicts = $registry->get_conflicts( $id );
			if ( ! empty( $conflicts ) ) {
				\WP_CLI::warning(
					sprintf(
						'Module "%s" conflicts with currently enabled module(s): %s. They will be disabled.',
						$id,
						implode( ', ', $conflicts )
					)
				);
				foreach ( $conflicts as $conflict_id ) {
					$plugin->settings()->set_module_enabled( $conflict_id, false );
					\WP_CLI::line( sprintf( '  Disabled conflicting module: %s', $conflict_id ) );
				}
			}
		}

		$plugin->settings()->set_module_enabled( $id, $enabled );

		if ( $enabled ) {
			\WP_CLI::success( sprintf( 'Module "%s" enabled.', $id ) );
		} else {
			\WP_CLI::success( sprintf( 'Module "%s" disabled.', $id ) );
		}
	}
}
