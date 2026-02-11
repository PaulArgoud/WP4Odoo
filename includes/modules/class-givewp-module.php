<?php
declare( strict_types=1 );

namespace WP4Odoo\Modules;

use WP4Odoo\Module_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GiveWP Module — push donations to Odoo accounting.
 *
 * Syncs GiveWP donation forms as Odoo service products (product.product)
 * and donations as either OCA donation records (donation.donation) or
 * core invoices (account.move), with automatic runtime detection.
 *
 * Fully supports recurring donations: each GiveWP recurring payment
 * fires the same status hook, so every instalment is pushed to Odoo
 * automatically through the standard donation pipeline.
 *
 * Push-only (WP → Odoo). No mutual exclusivity with other modules.
 *
 * Requires the GiveWP plugin to be active.
 *
 * @package WP4Odoo
 * @since   2.0.0
 */
class GiveWP_Module extends Module_Base {

	use GiveWP_Hooks;
	use Dual_Accounting_Model;

	/**
	 * Module identifier.
	 *
	 * @var string
	 */
	protected string $id = 'givewp';

	/**
	 * Human-readable module name.
	 *
	 * @var string
	 */
	protected string $name = 'GiveWP';

	/**
	 * Sync direction: push-only (WP → Odoo).
	 *
	 * @return string
	 */
	public function get_sync_direction(): string {
		return 'wp_to_odoo';
	}

	/**
	 * Odoo models by entity type.
	 *
	 * The donation model is resolved dynamically at push time:
	 * donation.donation if OCA module is detected, account.move otherwise.
	 *
	 * @var array<string, string>
	 */
	protected array $odoo_models = [
		'form'     => 'product.product',
		'donation' => 'account.move',
	];

	/**
	 * Default field mappings.
	 *
	 * Donation mappings are minimal because map_to_odoo() is overridden
	 * to pass handler-formatted data directly to Odoo.
	 *
	 * @var array<string, array<string, string>>
	 */
	protected array $default_mappings = [
		'form'     => [
			'form_name'  => 'name',
			'list_price' => 'list_price',
			'type'       => 'type',
		],
		'donation' => [
			'partner_id' => 'partner_id',
		],
	];

	/**
	 * GiveWP data handler.
	 *
	 * Initialized in __construct() (not boot()) because Sync_Engine can
	 * call push_to_odoo on non-booted modules for residual queue jobs.
	 *
	 * @var GiveWP_Handler
	 */
	private GiveWP_Handler $handler;

	/**
	 * Constructor.
	 */
	public function __construct( \Closure $client_provider, \WP4Odoo\Entity_Map_Repository $entity_map ) {
		parent::__construct( $client_provider, $entity_map );
		$this->handler = new GiveWP_Handler( $this->logger );
	}

	/**
	 * Boot the module: register GiveWP hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! defined( 'GIVE_VERSION' ) ) {
			$this->logger->warning( __( 'GiveWP module enabled but GiveWP is not active.', 'wp4odoo' ) );
			return;
		}

		$settings = $this->get_settings();

		if ( ! empty( $settings['sync_forms'] ) ) {
			add_action( 'save_post_give_forms', [ $this, 'on_form_save' ], 10, 1 );
		}

		if ( ! empty( $settings['sync_donations'] ) ) {
			add_action( 'give_update_payment_status', [ $this, 'on_donation_status_change' ], 10, 3 );
		}

		if ( class_exists( 'Give_Recurring' ) ) {
			$this->logger->info( 'GiveWP Recurring Donations add-on detected. Recurring payments will be synced automatically.' );
		}
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, bool>
	 */
	public function get_default_settings(): array {
		return [
			'sync_forms'              => true,
			'sync_donations'          => true,
			'auto_validate_donations' => true,
		];
	}

	/**
	 * Get settings field definitions for the admin UI.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_settings_fields(): array {
		return [
			'sync_forms'              => [
				'label'       => __( 'Sync donation forms', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push GiveWP donation forms to Odoo as service products.', 'wp4odoo' ),
			],
			'sync_donations'          => [
				'label'       => __( 'Sync donations', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Push completed donations to Odoo (includes recurring payments).', 'wp4odoo' ),
			],
			'auto_validate_donations' => [
				'label'       => __( 'Auto-validate donations', 'wp4odoo' ),
				'type'        => 'checkbox',
				'description' => __( 'Automatically validate donations in Odoo after creation.', 'wp4odoo' ),
			],
		];
	}

	/**
	 * Get external dependency status for GiveWP.
	 *
	 * @return array{available: bool, notices: array<array{type: string, message: string}>}
	 */
	public function get_dependency_status(): array {
		if ( ! defined( 'GIVE_VERSION' ) ) {
			return [
				'available' => false,
				'notices'   => [
					[
						'type'    => 'warning',
						'message' => __( 'GiveWP must be installed and activated to use this module.', 'wp4odoo' ),
					],
				],
			];
		}

		return [
			'available' => true,
			'notices'   => [],
		];
	}

	// ─── Push override ──────────────────────────────────────

	/**
	 * Push a WordPress entity to Odoo.
	 *
	 * For donations: resolves the Odoo model dynamically (OCA donation
	 * vs core invoice), ensures the form is synced, and auto-validates.
	 *
	 * @param string $entity_type The entity type.
	 * @param string $action      'create', 'update', or 'delete'.
	 * @param int    $wp_id       WordPress entity ID.
	 * @param int    $odoo_id     Odoo ID (0 if creating).
	 * @param array  $payload     Additional data.
	 * @return bool True on success.
	 */
	public function push_to_odoo( string $entity_type, string $action, int $wp_id, int $odoo_id = 0, array $payload = [] ): bool {
		if ( 'donation' === $entity_type && 'delete' !== $action ) {
			$this->resolve_accounting_model( 'donation' );
			$this->ensure_parent_synced( $wp_id, '_give_payment_form_id', 'form' );
		}

		$result = parent::push_to_odoo( $entity_type, $action, $wp_id, $odoo_id, $payload );

		if ( $result && 'donation' === $entity_type && 'create' === $action ) {
			$this->auto_validate( 'donation', $wp_id, 'auto_validate_donations', 'publish' );
		}

		return $result;
	}

	/**
	 * Map WP data to Odoo values.
	 *
	 * Donations bypass standard mapping — handler pre-formats for
	 * the target Odoo model. Forms use standard field mapping.
	 *
	 * @param string $entity_type Entity type.
	 * @param array  $wp_data     WordPress data from load_wp_data().
	 * @return array<string, mixed> Odoo-ready data.
	 */
	public function map_to_odoo( string $entity_type, array $wp_data ): array {
		if ( 'donation' === $entity_type ) {
			return $wp_data;
		}

		return parent::map_to_odoo( $entity_type, $wp_data );
	}

	// ─── Data access ────────────────────────────────────────

	/**
	 * Load WordPress data for an entity.
	 *
	 * @param string $entity_type Entity type.
	 * @param int    $wp_id       WordPress ID.
	 * @return array<string, mixed>
	 */
	protected function load_wp_data( string $entity_type, int $wp_id ): array {
		return match ( $entity_type ) {
			'form'     => $this->handler->load_form( $wp_id ),
			'donation' => $this->load_donation_data( $wp_id ),
			default    => [],
		};
	}

	/**
	 * Load and resolve a donation with Odoo references.
	 *
	 * Resolves donor email → partner and form → Odoo product ID.
	 * Supports guest donors (no WP user account).
	 *
	 * @param int $payment_id GiveWP payment ID.
	 * @return array<string, mixed>
	 */
	private function load_donation_data( int $payment_id ): array {
		$post = get_post( $payment_id );
		if ( ! $post || 'give_payment' !== $post->post_type ) {
			return [];
		}

		// Resolve donor → partner via email.
		$donor_email = (string) get_post_meta( $payment_id, '_give_payment_donor_email', true );
		if ( empty( $donor_email ) ) {
			$this->logger->warning( 'Donation has no donor email.', [ 'payment_id' => $payment_id ] );
			return [];
		}

		$donor_name = (string) get_post_meta( $payment_id, '_give_payment_donor_name', true );

		$partner_id = $this->partner_service()->get_or_create(
			$donor_email,
			[ 'name' => $donor_name ?: $donor_email ],
			0
		);

		if ( ! $partner_id ) {
			$this->logger->warning( 'Cannot resolve partner for donation.', [ 'payment_id' => $payment_id ] );
			return [];
		}

		// Resolve form → Odoo product ID.
		$form_id      = (int) get_post_meta( $payment_id, '_give_payment_form_id', true );
		$form_odoo_id = 0;
		if ( $form_id > 0 ) {
			$form_odoo_id = $this->get_mapping( 'form', $form_id ) ?? 0;
		}

		if ( ! $form_odoo_id ) {
			$this->logger->warning(
				'Cannot resolve Odoo product for donation form.',
				[ 'form_id' => $form_id ]
			);
			return [];
		}

		$use_donation_model = 'donation.donation' === $this->odoo_models['donation'];

		return $this->handler->load_donation( $payment_id, $partner_id, $form_odoo_id, $use_donation_model );
	}
}
