<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BuddyBoss Module — bidirectional sync between BuddyBoss/BuddyPress and Odoo.
 *
 * Syncs BuddyBoss community profiles to Odoo res.partner (bidirectional,
 * enriched contacts with xprofile fields) and groups to res.partner.category
 * (push-only, contact tags).
 *
 * When group membership sync is enabled, profile pushes include a
 * category_id Many2many field linking the user's groups as Odoo
 * partner categories.
 *
 * BuddyBoss data is accessed via:
 * - WordPress user API (user table + usermeta)
 * - BuddyPress xprofile API (extended profile fields)
 * - BuddyPress groups API (community groups)
 *
 * Requires the BuddyPress or BuddyBoss plugin to be active.
 *
 * @package WP4Odoo
 * @since   3.2.0
 */
class BuddyBoss_Module extends Module_Base {

	use BuddyBoss_Hooks;

	protected const PLUGIN_MIN_VERSION  = '2.4';
	protected const PLUGIN_TESTED_UP_TO = '2.7';

	/**
	 * Odoo models by entity type.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'profile' => 'res.partner',
		'group'   => 'res.partner.category',
	];

	/**
	 * Default field mappings.
	 *
	 * Profile mappings are handled by the handler's format_partner() method
	 * rather than the default field mapping because of name composition
	 * and xprofile field resolution. Minimal mappings are declared for
	 * schema validation.
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
		'group'   => [
			'name' => 'name',
		],
	];

	/**
	 * BuddyBoss data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var BuddyBoss_Handler
	 */
	private BuddyBoss_Handler $handler;

	/**
	 * Constructor.
	 *
	 * @param \Closure                       $client_provider Returns the shared Odoo_Client instance.
	 * @param \WP4Odoo\Entity_Map_Repository $entity_map      Shared entity map repository.
	 * @param \WP4Odoo\Settings_Repository   $settings        Settings repository.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'buddyboss', 'BuddyBoss', $client_provider, $entity_map, $settings );
		$this->handler = new BuddyBoss_Handler( $this->logger );
	}

	/**
	 * Sync direction: bidirectional (profiles bidi, groups push-only).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'bidirectional';
	}

	/**
	 * Boot the module: register BuddyBoss/BuddyPress hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'BP_VERSION' ) ) {
			$this->logger->warning( __( 'BuddyBoss module enabled but BuddyPress is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_profiles'] ) ) {
			add_action( 'xprofile_updated_profile', $this->safe_callback( [ $this, 'on_profile_updated' ] ), 10, 5 );
			add_action( 'bp_core_activated_user', $this->safe_callback( [ $this, 'on_user_activated' ] ), 10, 1 );
			add_action( 'delete_user', $this->safe_callback( [ $this, 'on_user_delete' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_groups'] ) ) {
			add_action( 'groups_group_after_save', $this->safe_callback( [ $this, 'on_group_saved' ] ), 10, 1 );
		}

		if ( ! empty( $settings['sync_group_members'] ) && ! empty( $settings['sync_profiles'] ) ) {
			add_action( 'groups_member_after_save', $this->safe_callback( [ $this, 'on_group_member_changed' ] ), 10, 2 );
			add_action( 'groups_member_after_remove', $this->safe_callback( [ $this, 'on_group_member_changed' ] ), 10, 2 );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_profiles'      => true,
			'pull_profiles'      => true,
			'sync_groups'        => true,
			'sync_group_members' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_profiles'      => [
				'label'       => __( 'Sync profiles', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push BuddyBoss community profiles to Odoo as contacts.', 'wp4odoo' ),
			],
			'pull_profiles'      => [
				'label'       => __( 'Pull profiles from Odoo', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Pull contact changes from Odoo back to BuddyBoss profiles.', 'wp4odoo' ),
			],
			'sync_groups'        => [
				'label'       => __( 'Sync groups', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push BuddyBoss groups to Odoo as partner categories (tags).', 'wp4odoo' ),
			],
			'sync_group_members' => [
				'label'       => __( 'Sync group memberships', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Include group category tags on profile sync to Odoo.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for BuddyBoss/BuddyPress.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		return $this->check_dependency( defined( 'BP_VERSION' ), 'BuddyPress' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function get_plugin_version(): string {
		return defined( 'BP_VERSION' ) ? BP_VERSION : '';
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
			'group'   => $this->handler->load_group( $wp_id ),
			default   => [],
		};
	}

	// ─── Odoo Mapping ────────────────────────────────────────

	/**
	 * Transform WordPress data to Odoo field values.
	 *
	 * For profiles, uses the handler's format_partner() to compose the name
	 * and map xprofile fields. When sync_group_members is enabled, resolves
	 * the user's group memberships to Odoo category IDs and includes
	 * category_id as a Many2many [(6, 0, [ids])].
	 *
	 * For groups, uses the handler's format_category().
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data.
	 * @return array Odoo-compatible field values.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'profile' === $entity_type ) {
			$group_odoo_ids = [];

			$settings = $this->get_settings();
			$user_id  = (int) ( $wp_data['_wp_entity_id'] ?? $wp_data['user_id'] ?? 0 );

			if ( ! empty( $settings['sync_group_members'] ) && $user_id > 0 ) {
				$bp_group_ids = $this->handler->get_user_group_ids( $user_id );

				foreach ( $bp_group_ids as $bp_group_id ) {
					$odoo_cat_id = $this->get_mapping( 'group', $bp_group_id );
					if ( $odoo_cat_id ) {
						$group_odoo_ids[] = $odoo_cat_id;
					}
				}
			}

			$odoo_values = $this->handler->format_partner( $wp_data, $group_odoo_ids );

			/** This filter is documented in includes/class-module-base.php */
			return apply_filters( "wp4odoo_map_to_odoo_{$this->id}_profile", $odoo_values, $wp_data, $entity_type );
		}

		if ( 'group' === $entity_type ) {
			$odoo_values = $this->handler->format_category( $wp_data );

			/** This filter is documented in includes/class-module-base.php */
			return apply_filters( "wp4odoo_map_to_odoo_{$this->id}_group", $odoo_values, $wp_data, $entity_type );
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
	 * Groups are push-only and cannot be pulled.
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
		// Groups are push-only: skip pull.
		if ( 'group' === $entity_type ) {
			return \WP4Odoo\Sync_Result::success( null );
		}

		// Check pull settings.
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
	 * Profiles dedup by email, groups dedup by name.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $odoo_values Odoo-ready field values.
	 * @return array Odoo domain filter, or empty to skip dedup.
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'profile' === $entity_type && ! empty( $odoo_values['email'] ) ) {
			return [ [ 'email', '=', $odoo_values['email'] ] ];
		}

		if ( 'group' === $entity_type && ! empty( $odoo_values['name'] ) ) {
			return [ [ 'name', '=', $odoo_values['name'] ] ];
		}

		return [];
	}
}
