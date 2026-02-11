<?php
/**
 * WP4Odoo_Plugin test stub.
 *
 * Testable singleton with module registration and reset support.
 *
 * @package WP4Odoo\Tests
 */
class WP4Odoo_Plugin {

	/** @var static|null */
	private static $instance = null;

	/** @var array<string, \WP4Odoo\Module_Base> */
	private array $modules = [];

	/** @var \WP4Odoo\Module_Registry|null */
	private ?\WP4Odoo\Module_Registry $module_registry = null;

	/** @var \WP4Odoo\Settings_Repository|null */
	private ?\WP4Odoo\Settings_Repository $settings = null;

	public static function instance(): static {
		if ( null === self::$instance ) {
			self::$instance = new static();
		}
		return self::$instance;
	}

	public function register_module( string $id, \WP4Odoo\Module_Base $module ): void {
		$this->modules[ $id ] = $module;
	}

	public function get_module( string $id ): ?\WP4Odoo\Module_Base {
		return $this->modules[ $id ] ?? null;
	}

	/** @return array<string, \WP4Odoo\Module_Base> */
	public function get_modules(): array {
		return $this->modules;
	}

	public function settings(): \WP4Odoo\Settings_Repository {
		if ( null === $this->settings ) {
			$this->settings = new \WP4Odoo\Settings_Repository();
		}
		return $this->settings;
	}

	public function module_registry(): \WP4Odoo\Module_Registry {
		if ( null === $this->module_registry ) {
			$this->module_registry = new \WP4Odoo\Module_Registry( $this, $this->settings() );
		}
		return $this->module_registry;
	}

	public function client(): \WP4Odoo\API\Odoo_Client {
		return new \WP4Odoo\API\Odoo_Client();
	}

	/** Reset singleton for test isolation. */
	public static function reset_instance(): void {
		self::$instance = null;
	}
}

/**
 * Global accessor for the plugin singleton.
 *
 * Mirrors the real wp4odoo() defined in wp4odoo.php.
 *
 * @return WP4Odoo_Plugin
 */
function wp4odoo(): WP4Odoo_Plugin {
	return WP4Odoo_Plugin::instance();
}
