<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ultimate Member Module — bidirectional sync between UM profiles and Odoo.
 *
 * Syncs Ultimate Member community profiles to Odoo res.partner (bidirectional,
 * enriched contacts with UM custom fields) and UM roles to
 * res.partner.category (push-only, contact tags).
 *
 * Similar to the BuddyBoss module but built on Ultimate Member's
 * user meta API (`um_user()`, `um_get_user_meta()`).
 *
 * Requires the Ultimate Member plugin to be active.
 *
 * @package WP4Odoo
 * @since   3.7.0
 */
class Ultimate_Member_Module extends Module_Base {

	use Ultimate_Member_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.6';
	protected const PLUGIN_TESTED_UP_TO = '2.9';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'profile' => 'res.partner',
		'role'    => 'res.partner.category',
	];

	/**
	 * Default field mappings.
	 *
	 * Profile mappings are handled by the handler's format_partner() method
	 * because of name composition and UM custom field resolution.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'profile' => [
			'user_email'  => 'email',
			'first_name'  => 'name',
			'phone'       => 'phone',
			'description' => 'comment',
			'user_url'    => 'website',
		],
		'role'    => [
			'name' => 'name',
		],
	];

	/**
	 * Ultimate Member data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var Ultimate_Member_Handler
	 */
	private Ultimate_Member_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'ultimate_member', 'Ultimate Member', $client_provider, $entity_map, $settings );
		$this->handler = new Ultimate_Member_Handler( $this->logger );
	}

	/**
	 * Sync direction: bidirectional (profiles bidi, roles push-only).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Boot the module: register Ultimate Member hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! class_exists( 'UM' ) ) {
			$this->logger->warning( __( 'Ultimate Member module enabled but Ultimate Member is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_profiles'] ) ) {
			add_action( 'um_after_user_updated', $this->safe_callback( [ $this, 'on_profile_updated' ] ), 10, 1 );
			add_action( 'um_registration_complete', $this->safe_callback( [ $this, 'on_registration_complete' ] ), 10, 2 );
			add_action( 'delete_user', $this->safe_callback( [ $this, 'on_user_delete' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_roles'] ) ) {
			add_action( 'um_member_role_upgrade', $this->safe_callback( [ $this, 'on_role_changed' ] ), 10, 2 );
			add_action( 'set_user_role', $this->safe_callback( [ $this, 'on_role_changed' ] ), 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_profiles' => true,
			'pull_profiles' => true,
			'sync_roles'    => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_profiles' => [
				'label'       => __( 'Sync profiles', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Ultimate Member profiles to Odoo as contacts.', 'wp4odoo' ),
			],
			'pull_profiles' => [
				'label'       => __( 'Pull profiles from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull contact changes from Odoo back to Ultimate Member profiles.', 'wp4odoo' ),
			],
			'sync_roles'    => [
				'label'       => __( 'Sync roles', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push Ultimate Member roles to Odoo as partner categories.', 'wp4odoo' ),
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
		return class_exists( 'UM' );
	}

	/**
	 * Get external dependency status for Ultimate Member.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( static::detect(), 'Ultimate Member' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'UM_VERSION' ) ? UM_VERSION : '';
	}

	// ─── Data Loading ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'profile' => $this->handler->load_profile( $wp_id ),
			'role'    => $this->handler->load_role( $wp_id ),
			default   => [],
		};
	}

	// ─── Odoo Mapping ────────────────────────────────────────

	/**
	 * Transform WordPress data to Odoo field values.
	 *
	 * For profiles, uses the handler's format_partner() to compose the name.
	 * When sync_roles is enabled, resolves the user's UM role to an Odoo
	 * category ID and includes category_id as a Many2many [(6, 0, [ids])].
	 *
	 * For roles, uses the handler's format_category().
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array Odoo-compatible field values.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'profile' === $entity_type ) {
			$role_odoo_ids = [];

			$settings = $this->get_settings();
			$user_id  = (int) ( $wp_data['_wp_entity_id'] ?? $wp_data['user_id'] ?? 0 );

			if ( ! empty( $settings['sync_roles'] ) && $user_id > 0 ) {
				$role_slug = $this->handler->get_user_role( $user_id );
				if ( '' !== $role_slug ) {
					$role_wp_id  = absint( crc32( $role_slug ) );
					$odoo_cat_id = $this->get_mapping( 'role', $role_wp_id );
					if ( $odoo_cat_id ) {
						$role_odoo_ids[] = $odoo_cat_id;
					}
				}
			}

			$odoo_values = $this->handler->format_partner( $wp_data, $role_odoo_ids );

			/** This filter is documented in includes/class-module-base.php */
			return apply_filters( "wp4odoo_map_to_odoo_{$this->id}_profile", $odoo_values, $wp_data, $entity_type );
		}

		if ( 'role' === $entity_type ) {
			$odoo_values = $this->handler->format_category( $wp_data );

			/** This filter is documented in includes/class-module-base.php */
			return apply_filters( "wp4odoo_map_to_odoo_{$this->id}_role", $odoo_values, $wp_data, $entity_type );
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	/**
	 * Transform Odoo data to WordPress-compatible format.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_data   Odoo record data.
	 * @return array WordPress-compatible data.
	 */
	public function map_from_odoo( string $entity_type, array $odoo_data ): array {
		return match ( $entity_type ) {
			'profile' => $this->handler->parse_profile_from_odoo( $odoo_data ),
			default   => parent::map_from_odoo( $entity_type, $odoo_data ),
		};
	}

	// ─── Data Saving ─────────────────────────────────────────

	/**
	 * Save data to WordPress.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $data        Mapped data.
	 * @param int    $wp_id       Existing WP ID (0 if new).
	 * @return int The WordPress entity ID (0 on failure).
	 */
	protected function save_wp_data( string $entity_type, array $data, int $wp_id = 0 ): int {
		return match ( $entity_type ) {
			'profile' => $this->handler->save_profile( $data, $wp_id ),
			default   => 0,
		};
	}

	// ─── Pull override ───────────────────────────────────────

	/**
	 * Pull an Odoo entity to WordPress.
	 *
	 * Roles are push-only and cannot be pulled.
	 * Profiles respect the pull_profiles setting.
	 *
	 * @param string $entity_type Entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $odoo_id     Odoo record ID.
	 * @param int    $wp_id       WordPress entity ID (0 if unknown).
	 * @param array  $payload     Additional data.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function pull_from_odoo( string $entity_type, string $action, int $odoo_id, int $wp_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		if ( 'role' === $entity_type ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		$settings = $this->get_settings();

		if ( 'profile' === $entity_type && empty( $settings['pull_profiles'] ) ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		return parent::pull_from_odoo( $entity_type, $action, $odoo_id, $wp_id, $payload );
	}

	// ─── Deduplication ───────────────────────────────────────

	/**
	 * Deduplication domain for search-before-create.
	 *
	 * Profiles dedup by email, roles dedup by name.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'profile' === $entity_type && ! empty( $odoo_values['email'] ) ) {
			return [ [ 'email', '=', $odoo_values['email'] ] ];
		}

		if ( 'role' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}
}
