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

	public function client(): \WP4Odoo\API\Odoo_Client {
		return new \WP4Odoo\API\Odoo_Client();
	}

	/** Reset singleton for test isolation. */
	public static function reset_instance(): void {
		self::$instance = null;
	}
}
