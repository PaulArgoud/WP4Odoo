<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP ERP Accounting Module — bidirectional sync for journal entries,
 * chart of accounts, and journals.
 *
 * Completes the WP ERP triptyque (HR + CRM + Accounting). Syncs WP ERP
 * Accounting journal entries as Odoo account.move, chart of accounts as
 * account.account, and journals as account.journal.
 *
 * All entity types are bidirectional.
 *
 * WP ERP stores accounting data in custom database tables — the handler
 * queries them directly via $wpdb.
 *
 * Requires the WP ERP plugin with Accounting component to be active.
 *
 * @package WP4Odoo
 * @since   3.6.0
 */
class WPERP_Accounting_Module extends Module_Base {

	use WPERP_Accounting_Hooks;

	protected const PLUGIN_MIN_VERSION  = '1.6';
	protected const PLUGIN_TESTED_UP_TO = '1.14';

	/**
	 * Sync direction: bidirectional.
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'journal_entry' => 'account.move',
		'chart_account' => 'account.account',
		'journal'       => 'account.journal',
	];

	/**
	 * Default field mappings.
	 *
	 * All data is pre-formatted by the handler (identity pass-through
	 * in map_to_odoo).
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'journal_entry' => [
			'move_type'        => 'move_type',
			'partner_id'       => 'partner_id',
			'ref'              => 'ref',
			'date'             => 'date',
			'journal_id'       => 'journal_id',
			'invoice_line_ids' => 'invoice_line_ids',
		],
		'chart_account' => [
			'name'         => 'name',
			'code'         => 'code',
			'account_type' => 'account_type',
		],
		'journal'       => [
			'name' => 'name',
			'type' => 'type',
			'code' => 'code',
		],
	];

	/**
	 * WP ERP Accounting data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var WPERP_Accounting_Handler
	 */
	private WPERP_Accounting_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Client provider closure.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'wperp_accounting', 'WP ERP Accounting', $client_provider, $entity_map, $settings );
		$this->handler = new WPERP_Accounting_Handler( $this->logger );
	}

	/**
	 * Boot the module: register WP ERP Accounting hooks.
	 *
	 * Checks that the Accounting sub-module is actually available
	 * (same WPERP_VERSION constant, but with accounting functions).
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'WPERP_VERSION' ) ) {
			$this->logger->warning( __( 'WP ERP Accounting module enabled but WP ERP is not active.', 'wp4odoo' ) );
			return;
		}

		if ( ! function_exists( 'erp_acct_get_dashboard_overview' ) ) {
			$this->logger->warning( __( 'WP ERP Accounting sub-module is not active.', 'wp4odoo' ) );
			return;
		}

		$this->register_hooks();
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_journal_entries' => true,
			'sync_chart_accounts'  => false,
			'sync_journals'        => false,
			'pull_journal_entries' => true,
			'pull_chart_accounts'  => true,
			'pull_journals'        => true,
		];
	}

	/**
	 * Third-party tables accessed directly via $wpdb.
	 *
	 * @return array<int, string>
	 */
	protected function get_required_tables(): array {
		return [
			'erp_acct_journals',
			'erp_acct_ledger_details',
			'erp_acct_chart_of_accounts',
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_journal_entries' => [
				'label'       => __( 'Sync journal entries', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP ERP journal entries to Odoo as account.move.', 'wp4odoo' ),
			],
			'sync_chart_accounts'  => [
				'label'       => __( 'Sync chart of accounts', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP ERP chart of accounts to Odoo.', 'wp4odoo' ),
			],
			'sync_journals'        => [
				'label'       => __( 'Sync journals', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WP ERP journals to Odoo.', 'wp4odoo' ),
			],
			'pull_journal_entries' => [
				'label'       => __( 'Pull journal entries', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull journal entry changes from Odoo.', 'wp4odoo' ),
			],
			'pull_chart_accounts'  => [
				'label'       => __( 'Pull chart of accounts', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull chart of accounts from Odoo.', 'wp4odoo' ),
			],
			'pull_journals'        => [
				'label'       => __( 'Pull journals', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull journals from Odoo.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Whether the third-party plugin(s) this module integrates with are active.
	 *
	 * Single source of truth for module activation, shared by the registry
	 * and the dependency check.
	 *
	 * @return bool
	 */
	public static function detect(): bool {
		return defined( 'WPERP_VERSION' );
	}

	/**
	 * Get external dependency status for WP ERP.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'WP ERP' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'WPERP_VERSION' ) ? WPERP_VERSION : '';
	}

	// ─── Deduplication ────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'journal_entry' === $entity_type && ! empty( $odoo_values['ref'] ) ) {
			return [ [ 'ref', '=', $odoo_values['ref'] ] ];
		}

		if ( 'chart_account' === $entity_type && ! empty( $odoo_values['code'] ) ) {
			return [ [ 'code', '=', $odoo_values['code'] ] ];
		}

		if ( 'journal' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}

	// ─── Pull override ────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Checks pull settings per entity type before delegating to parent.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		$settings = $this->get_settings();

		$pull_map = [
			'journal_entry' => 'pull_journal_entries',
			'chart_account' => 'pull_chart_accounts',
			'journal'       => 'pull_journals',
		];

		$setting_key = $pull_map[ $entity_type ] ?? '';
		if ( '' !== $setting_key && empty( $settings[ $setting_key ] ) ) {
			return \WP4Odoo\Sync_Result::success();
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	/**
	 * Map Odoo data to WordPress format for pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Raw Odoo record data.
	 * @return array<string, mixed>
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'journal_entry' => $this->handler->parse_journal_entry_from_odoo( $odoo_data ),
			'chart_account' => $this->handler->parse_chart_account_from_odoo( $odoo_data ),
			'journal'       => $this->handler->parse_journal_from_odoo( $odoo_data ),
			default         => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
	}

	/**
	 * Save pulled data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return match ( $entity_type ) {
			'journal_entry' => $this->handler->save_journal_entry( $data, $wp_id ),
			'chart_account' => $this->handler->save_chart_account( $data, $wp_id ),
			'journal'       => $this->handler->save_journal( $data, $wp_id ),
			default         => 0,
		};
	}

	/**
	 * Delete a WordPress entity during pull.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress entity ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		return match ( $entity_type ) {
			'journal_entry' => $this->handler->delete_journal_entry( $wp_id ),
			'chart_account' => $this->handler->delete_chart_account( $wp_id ),
			'journal'       => $this->handler->delete_journal( $wp_id ),
			default         => false,
		};
	}

	// ─── Push override ────────────────────────────────────

	/**
	 * Map WP data to Odoo values.
	 *
	 * Data is pre-formatted by the handler — identity pass-through.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		return $wp_data;
	}

	// ─── Data access ──────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'journal_entry' => $this->handler->load_journal_entry( $wp_id ),
			'chart_account' => $this->handler->load_chart_account( $wp_id ),
			'journal'       => $this->handler->load_journal( $wp_id ),
			default         => [],
		};
	}
}
