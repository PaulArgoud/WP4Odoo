<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;
use WP4Odoo\Queue_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Module — syncs WordPress users ↔ Odoo contacts, form submissions → leads.
 *
 * Contact sync is handled directly by this class.
 * Lead management is delegated to Lead_Manager.
 * Contact field refinement (name, country/state) is delegated to Contact_Refiner.
 *
 * @package WP4Odoo
 * @since   1.0.0
 */
class CRM_Module extends Module_Base {

	use CRM_User_Hooks;


	protected array $odoo_models = [
		'contact' => 'res.partner',
		'lead'    => 'crm.lead',
	];

	protected array $default_mappings = [
		'contact' => [
			'display_name'      => 'name',
			'user_email'        => 'email',
			'description'       => 'comment',
			'first_name'        => 'x_wp_first_name',
			'last_name'         => 'x_wp_last_name',
			'billing_phone'     => 'phone',
			'billing_company'   => 'company_name',
			'billing_address_1' => 'street',
			'billing_address_2' => 'street2',
			'billing_city'      => 'city',
			'billing_postcode'  => 'zip',
			'billing_country'   => 'country_id',
			'billing_state'     => 'state_id',
			'user_url'          => 'website',
		],
		'lead'    => [
			'name'        => 'name',
			'email'       => 'email_from',
			'phone'       => 'phone',
			'company'     => 'partner_name',
			'description' => 'description',
			'source'      => 'x_wp_source',
		],
	];

	/**
	 * Lead management delegate.
	 *
	 * @var Lead_Manager
	 */
	private Lead_Manager $lead_manager;

	/**
	 * Contact refinement delegate.
	 *
	 * @var Contact_Refiner
	 */
	private Contact_Refiner $contact_refiner;

	/**
	 * Contact data operations delegate.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push/pull on non-booted modules for residual queue jobs.
	 *
	 * @var Contact_Manager
	 */
	private Contact_Manager $contact_manager;

	/**
	 * Constructor.
	 *
	 * Handlers are initialized here (not in boot()) because Sync_Engine
	 * can call push_to_odoo / pull_from_odoo on non-booted modules for
	 * residual queue jobs.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map, \WP4Odoo\Settings_Repository $settings ) {
		parent::__construct( 'crm', 'CRM', $client_provider, $entity_map, $settings );
		$this->contact_manager = new Contact_Manager( $this->logger, fn() => $this->get_settings() );
		$this->lead_manager    = new Lead_Manager( $this->logger, fn() => $this->get_settings() );
	}

	/**
	 * Boot the module: register WordPress hooks, CPT, shortcode, filters.
	 *
	 * @return void
	 */
	public function boot(): void {
		$this->contact_refiner = new Contact_Refiner( fn() => $this->client() );

		// User hooks for contact sync.
		add_action( 'user_register', $this->safe_callback( [ $this, 'on_user_register' ] ), 10, 2 );
		add_action( 'profile_update', $this->safe_callback( [ $this, 'on_profile_update' ] ), 10, 3 );
		add_action( 'delete_user', $this->safe_callback( [ $this, 'on_delete_user' ] ), 10, 3 );

		// Lead CPT and shortcode (delegated to Lead_Manager).
		add_action( 'init', [ $this->lead_manager, 'register_lead_cpt' ] );
		add_shortcode( 'wp4odoo_lead_form', [ $this->lead_manager, 'render_lead_form' ] );
		add_action( 'wp_ajax_wp4odoo_submit_lead', [ $this->lead_manager, 'handle_lead_submission' ] );
		add_action( 'wp_ajax_nopriv_wp4odoo_submit_lead', [ $this->lead_manager, 'handle_lead_submission' ] );

		// Mapping refinement filters (delegated to Contact_Refiner).
		add_filter( "wp4odoo_map_to_odoo_{$this->id}_contact", [ $this->contact_refiner, 'refine_to_odoo' ], 10, 3 );
		add_filter( "wp4odoo_map_from_odoo_{$this->id}_contact", [ $this->contact_refiner, 'refine_from_odoo' ], 10, 3 );
	}

	/**
	 * Get default settings for the CRM module.
	 *
	 * @return array
	 */
	public function get_default_settings(): array {
		return [
			'sync_users_as_contacts' => true,
			'archive_on_delete'      => true,
			'sync_role'              => '',
			'create_users_on_pull'   => true,
			'default_user_role'      => 'subscriber',
			'lead_form_enabled'      => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array>
	 */
	public function get_settings_fields(): array {
		$roles = [];
		foreach ( wp_roles()->roles as $role_key => $role ) {
			$roles[ $role_key ] = $role['name'];
		}

		return [
			'sync_users_as_contacts' => [
				'label'       => __( 'Sync users as contacts', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push WordPress users to Odoo res.partner.', 'wp4odoo' ),
			],
			'archive_on_delete'      => [
				'label'       => __( 'Archive on delete', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Set active=false in Odoo instead of deleting the record.', 'wp4odoo' ),
			],
			'sync_role'              => [
				'label'       => __( 'Sync role', 'wp4odoo' ),
				'type'        => 'select',
				'options'     => array_merge( [ '' => __( 'All roles', 'wp4odoo' ) ], $roles ),
				'description' => __( 'Only sync users with this role. Leave empty to sync all.', 'wp4odoo' ),
			],
			'create_users_on_pull'   => [
				'label'       => __( 'Create users on pull', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Create new WordPress users when pulling contacts from Odoo.', 'wp4odoo' ),
			],
			'default_user_role'      => [
				'label'       => __( 'Default user role', 'wp4odoo' ),
				'type'        => 'select',
				'options'     => $roles,
				'description' => __( 'Role assigned to users created by Odoo pull.', 'wp4odoo' ),
			],
			'lead_form_enabled'      => [
				'label'       => __( 'Enable lead form', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Enable the [wp4odoo_lead_form] shortcode.', 'wp4odoo' ),
			],
		];
	}

	// ─── Push / Pull Overrides ───────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * Extends the base to handle archive payloads and email deduplication
	 * before creating a new contact in Odoo.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data from the queue.
	 * @return \WP4Odoo\Sync_Result
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): \WP4Odoo\Sync_Result {
		// Handle archive payload.
		if ( 'contact' === $entity_type && ! empty( $payload['_archive'] ) ) {
			if ( $odoo_id > 0 ) {
				$model = $this->get_odoo_model( $entity_type );
				$this->client()->write( $model, [ $odoo_id ], [ 'active' => false ] );
				$this->logger->info( 'Archived Odoo contact.', compact( 'wp_id', 'odoo_id' ) );
			}
			$this->remove_mapping( $entity_type, $wp_id );
			return \WP4Odoo\Sync_Result::success( $odoo_id );
		}

		// Email dedup on create: search Odoo by email before creating.
		if ( 'contact' === $entity_type && 'create' === $action && 0 === $odoo_id ) {
			$wp_data = $this->load_wp_data( $entity_type, $wp_id );
			$email   = $wp_data['user_email'] ?? '';

			if ( ! empty( $email ) ) {
				$model    = $this->get_odoo_model( $entity_type );
				$existing = $this->client()->search( $model, [ [ 'email', '=', $email ] ], 0, 1 );

				if ( ! empty( $existing ) ) {
					$odoo_id = (int) $existing[0];
					$action  = 'update';
					$this->logger->info( 'Email match found in Odoo, converting create to update.', compact( 'email', 'odoo_id' ) );
				}
			}
		}

		return parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );
	}

	// ─── Data Loading ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'contact' => $this->contact_manager->load_contact_data( $wp_id ),
			'lead'    => $this->lead_manager->load_lead_data( $wp_id ),
			default   => [],
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
			'contact' => $this->contact_manager->save_contact_data( $data, $wp_id ),
			'lead'    => $this->lead_manager->save_lead_data( $data, $wp_id ),
			default   => 0,
		};
	}

	/**
	 * Dedup domain for contacts: match by email.
	 *
	 * @param string               $entity_type Entity type.
	 * @param array<string, mixed> $odoo_values Odoo values.
	 * @return array<int, mixed>
	 */
	protected function get_dedup_domain( string $entity_type, array $odoo_values ): array {
		if ( 'contact' === $entity_type && ! empty( $odoo_values['email'] ) ) {
			return [ [ 'email', '=', $odoo_values['email'] ] ];
		}

		return [];
	}

	/**
	 * Delete a WordPress entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return bool
	 */
	protected function delete_wp_data( string $entity_type, int $wp_id ): bool {
		if ( 'contact' === $entity_type ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			return wp_delete_user( $wp_id );
		}

		if ( 'lead' === $entity_type ) {
			return $this->delete_wp_post( $wp_id );
		}

		return false;
	}
}
