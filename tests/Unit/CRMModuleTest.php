<?php
declare( strict_types=1 );

namespace WP4Odoo\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use WP4Odoo\Tests\Module_Test_Case;
use WP4Odoo\Modules\CRM_Module;

/**
 * Unit tests for CRM_Module.
 *
 * Tests module configuration, entity type declarations, field mappings,
 * default settings, contact/lead data loading, push mapping, pull mapping,
 * and dedup domain logic.
 *
 */
#[CoversClass(\WP4Odoo\Modules\CRM_Module::class)]
#[CoversClass(\WP4Odoo\Modules\Contact_Manager::class)]
#[CoversClass(\WP4Odoo\Modules\Lead_Manager::class)]
class CRMModuleTest extends Module_Test_Case {

	private CRM_Module $module;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['_wp_posts']     = [];
		$GLOBALS['_wp_post_meta'] = [];
		$GLOBALS['_wp_users']     = [];
		$GLOBALS['_wp_user_meta'] = [];

		$this->module = new CRM_Module( wp4odoo_test_client_provider(), wp4odoo_test_entity_map(), wp4odoo_test_settings() );
	}

	// ─── Module Identity ───────────────────────────────────

	public function test_module_id_is_crm(): void {
		$this->assertSame( 'crm', $this->module->get_id() );
	}

	public function test_module_name_is_crm(): void {
		$this->assertSame( 'CRM', $this->module->get_name() );
	}

	public function test_exclusive_group_is_empty(): void {
		$this->assertSame( '', $this->module->get_exclusive_group() );
	}

	public function test_sync_direction_is_bidirectional(): void {
		$this->assertSame( 'bidirectional', $this->module->get_sync_direction() );
	}

	// ─── Odoo Models ───────────────────────────────────────

	public function test_declares_contact_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'res.partner', $models['contact'] );
	}

	public function test_declares_lead_model(): void {
		$models = $this->module->get_odoo_models();
		$this->assertSame( 'crm.lead', $models['lead'] );
	}

	public function test_declares_exactly_two_entity_types(): void {
		$models = $this->module->get_odoo_models();
		$this->assertCount( 2, $models );
	}

	// ─── Default Settings ──────────────────────────────────

	public function test_default_settings_sync_users_as_contacts(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['sync_users_as_contacts'] );
	}

	public function test_default_settings_archive_on_delete(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['archive_on_delete'] );
	}

	public function test_default_settings_sync_role_is_empty(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( '', $settings['sync_role'] );
	}

	public function test_default_settings_create_users_on_pull(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['create_users_on_pull'] );
	}

	public function test_default_settings_default_user_role(): void {
		$settings = $this->module->get_default_settings();
		$this->assertSame( 'subscriber', $settings['default_user_role'] );
	}

	public function test_default_settings_lead_form_enabled(): void {
		$settings = $this->module->get_default_settings();
		$this->assertTrue( $settings['lead_form_enabled'] );
	}

	public function test_default_settings_has_exactly_six_keys(): void {
		$settings = $this->module->get_default_settings();
		$this->assertCount( 6, $settings );
	}

	// ─── Settings Fields ───────────────────────────────────

	public function test_settings_fields_exposes_sync_users_as_contacts(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_users_as_contacts', $fields );
		$this->assertSame( 'checkbox', $fields['sync_users_as_contacts']['type'] );
	}

	public function test_settings_fields_exposes_archive_on_delete(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'archive_on_delete', $fields );
		$this->assertSame( 'checkbox', $fields['archive_on_delete']['type'] );
	}

	public function test_settings_fields_exposes_sync_role_as_select(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'sync_role', $fields );
		$this->assertSame( 'select', $fields['sync_role']['type'] );
	}

	public function test_settings_fields_exposes_create_users_on_pull(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'create_users_on_pull', $fields );
		$this->assertSame( 'checkbox', $fields['create_users_on_pull']['type'] );
	}

	public function test_settings_fields_exposes_default_user_role_as_select(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'default_user_role', $fields );
		$this->assertSame( 'select', $fields['default_user_role']['type'] );
	}

	public function test_settings_fields_exposes_lead_form_enabled(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertArrayHasKey( 'lead_form_enabled', $fields );
		$this->assertSame( 'checkbox', $fields['lead_form_enabled']['type'] );
	}

	public function test_settings_fields_has_exactly_six_keys(): void {
		$fields = $this->module->get_settings_fields();
		$this->assertCount( 6, $fields );
	}

	// ─── Field Mappings: Contact ───────────────────────────

	public function test_contact_mapping_display_name_to_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'name', $mappings['contact']['display_name'] );
	}

	public function test_contact_mapping_user_email_to_email(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'email', $mappings['contact']['user_email'] );
	}

	public function test_contact_mapping_description_to_comment(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'comment', $mappings['contact']['description'] );
	}

	public function test_contact_mapping_billing_phone_to_phone(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'phone', $mappings['contact']['billing_phone'] );
	}

	public function test_contact_mapping_billing_company_to_company_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'company_name', $mappings['contact']['billing_company'] );
	}

	public function test_contact_mapping_user_url_to_website(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'website', $mappings['contact']['user_url'] );
	}

	public function test_contact_mapping_has_fourteen_fields(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertCount( 14, $mappings['contact'] );
	}

	// ─── Field Mappings: Lead ──────────────────────────────

	public function test_lead_mapping_name_to_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'name', $mappings['lead']['name'] );
	}

	public function test_lead_mapping_email_to_email_from(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'email_from', $mappings['lead']['email'] );
	}

	public function test_lead_mapping_company_to_partner_name(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'partner_name', $mappings['lead']['company'] );
	}

	public function test_lead_mapping_source_to_x_wp_source(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertSame( 'x_wp_source', $mappings['lead']['source'] );
	}

	public function test_lead_mapping_has_six_fields(): void {
		$ref      = new \ReflectionProperty( $this->module, 'default_mappings' );
		$mappings = $ref->getValue( $this->module );
		$this->assertCount( 6, $mappings['lead'] );
	}

	// ─── map_to_odoo: Contact ──────────────────────────────

	public function test_map_to_odoo_contact_maps_email(): void {
		$wp_data = [
			'display_name' => 'Jane Smith',
			'user_email'   => 'jane@example.com',
			'description'  => 'Test contact.',
		];

		$odoo = $this->module->map_to_odoo( 'contact', $wp_data );

		$this->assertSame( 'Jane Smith', $odoo['name'] );
		$this->assertSame( 'jane@example.com', $odoo['email'] );
		$this->assertSame( 'Test contact.', $odoo['comment'] );
	}

	public function test_map_to_odoo_contact_maps_billing_fields(): void {
		$wp_data = [
			'billing_phone'     => '+33123456789',
			'billing_company'   => 'Acme Corp',
			'billing_address_1' => '10 Rue de Rivoli',
			'billing_city'      => 'Paris',
			'billing_postcode'  => '75001',
			'user_url'          => 'https://example.com',
		];

		$odoo = $this->module->map_to_odoo( 'contact', $wp_data );

		$this->assertSame( '+33123456789', $odoo['phone'] );
		$this->assertSame( 'Acme Corp', $odoo['company_name'] );
		$this->assertSame( '10 Rue de Rivoli', $odoo['street'] );
		$this->assertSame( 'Paris', $odoo['city'] );
		$this->assertSame( '75001', $odoo['zip'] );
		$this->assertSame( 'https://example.com', $odoo['website'] );
	}

	// ─── map_to_odoo: Lead ─────────────────────────────────

	public function test_map_to_odoo_lead_maps_all_fields(): void {
		$wp_data = [
			'name'        => 'New Lead',
			'email'       => 'lead@example.com',
			'phone'       => '+44987654321',
			'company'     => 'Widget Inc',
			'description' => 'Interested in our product.',
			'source'      => 'Contact Page',
		];

		$odoo = $this->module->map_to_odoo( 'lead', $wp_data );

		$this->assertSame( 'New Lead', $odoo['name'] );
		$this->assertSame( 'lead@example.com', $odoo['email_from'] );
		$this->assertSame( '+44987654321', $odoo['phone'] );
		$this->assertSame( 'Widget Inc', $odoo['partner_name'] );
		$this->assertSame( 'Interested in our product.', $odoo['description'] );
		$this->assertSame( 'Contact Page', $odoo['x_wp_source'] );
	}

	public function test_map_to_odoo_lead_ignores_unmapped_fields(): void {
		$wp_data = [
			'name'         => 'A Lead',
			'extra_field'  => 'should be ignored',
		];

		$odoo = $this->module->map_to_odoo( 'lead', $wp_data );

		$this->assertSame( 'A Lead', $odoo['name'] );
		$this->assertArrayNotHasKey( 'extra_field', $odoo );
	}

	// ─── map_from_odoo: Contact ────────────────────────────

	public function test_map_from_odoo_contact_maps_name_and_email(): void {
		$odoo_data = [
			'name'    => 'Odoo Contact',
			'email'   => 'odoo@example.com',
			'comment' => 'Pulled from Odoo.',
			'phone'   => '+1555123',
			'website' => 'https://odoo.example.com',
		];

		$wp_data = $this->module->map_from_odoo( 'contact', $odoo_data );

		$this->assertSame( 'Odoo Contact', $wp_data['display_name'] );
		$this->assertSame( 'odoo@example.com', $wp_data['user_email'] );
		$this->assertSame( 'Pulled from Odoo.', $wp_data['description'] );
		$this->assertSame( '+1555123', $wp_data['billing_phone'] );
		$this->assertSame( 'https://odoo.example.com', $wp_data['user_url'] );
	}

	public function test_map_from_odoo_contact_maps_address_fields(): void {
		$odoo_data = [
			'street'       => '123 Main St',
			'street2'      => 'Suite 4',
			'city'         => 'Springfield',
			'zip'          => '62704',
			'company_name' => 'Test Corp',
		];

		$wp_data = $this->module->map_from_odoo( 'contact', $odoo_data );

		$this->assertSame( '123 Main St', $wp_data['billing_address_1'] );
		$this->assertSame( 'Suite 4', $wp_data['billing_address_2'] );
		$this->assertSame( 'Springfield', $wp_data['billing_city'] );
		$this->assertSame( '62704', $wp_data['billing_postcode'] );
		$this->assertSame( 'Test Corp', $wp_data['billing_company'] );
	}

	// ─── map_from_odoo: Lead ───────────────────────────────

	public function test_map_from_odoo_lead_maps_fields(): void {
		$odoo_data = [
			'name'         => 'Odoo Lead',
			'email_from'   => 'lead@odoo.com',
			'phone'        => '+33999',
			'partner_name' => 'Partner Corp',
			'description'  => 'Lead description.',
			'x_wp_source'  => 'Website',
		];

		$wp_data = $this->module->map_from_odoo( 'lead', $odoo_data );

		$this->assertSame( 'Odoo Lead', $wp_data['name'] );
		$this->assertSame( 'lead@odoo.com', $wp_data['email'] );
		$this->assertSame( '+33999', $wp_data['phone'] );
		$this->assertSame( 'Partner Corp', $wp_data['company'] );
		$this->assertSame( 'Lead description.', $wp_data['description'] );
		$this->assertSame( 'Website', $wp_data['source'] );
	}

	// ─── load_wp_data: Contact ─────────────────────────────

	public function test_load_wp_data_contact_returns_user_fields(): void {
		$user                = new \WP_User( 1 );
		$user->display_name  = 'John Doe';
		$user->user_email    = 'john@example.com';
		$user->first_name    = 'John';
		$user->last_name     = 'Doe';
		$user->description   = 'A user.';
		$user->user_url      = 'https://john.example.com';
		$GLOBALS['_wp_users'][1] = $user;

		$ref    = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$result = $ref->invoke( $this->module, 'contact', 1 );

		$this->assertSame( 'John Doe', $result['display_name'] );
		$this->assertSame( 'john@example.com', $result['user_email'] );
		$this->assertSame( 'John', $result['first_name'] );
		$this->assertSame( 'Doe', $result['last_name'] );
	}

	public function test_load_wp_data_contact_returns_empty_when_not_found(): void {
		$ref    = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$result = $ref->invoke( $this->module, 'contact', 999 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_contact_includes_billing_meta(): void {
		$user             = new \WP_User( 2 );
		$user->user_email = 'meta@example.com';
		$GLOBALS['_wp_users'][2]     = $user;
		$GLOBALS['_wp_user_meta'][2] = [
			'billing_phone'   => '+33100000000',
			'billing_company' => 'Meta Corp',
			'billing_city'    => 'Lyon',
		];

		$ref    = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$result = $ref->invoke( $this->module, 'contact', 2 );

		$this->assertSame( '+33100000000', $result['billing_phone'] );
		$this->assertSame( 'Meta Corp', $result['billing_company'] );
		$this->assertSame( 'Lyon', $result['billing_city'] );
	}

	// ─── load_wp_data: Lead ────────────────────────────────

	public function test_load_wp_data_lead_returns_post_fields(): void {
		$post               = new \stdClass();
		$post->ID           = 10;
		$post->post_title   = 'Test Lead';
		$post->post_content = 'Lead description text.';
		$post->post_type    = 'wp4odoo_lead';
		$post->post_status  = 'publish';
		$post->post_date    = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][10]    = $post;
		$GLOBALS['_wp_post_meta'][10] = [
			'_lead_email'   => 'lead@test.com',
			'_lead_phone'   => '+1555',
			'_lead_company' => 'LeadCo',
			'_lead_source'  => 'Homepage',
		];

		$ref    = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$result = $ref->invoke( $this->module, 'lead', 10 );

		$this->assertSame( 'Test Lead', $result['name'] );
		$this->assertSame( 'lead@test.com', $result['email'] );
		$this->assertSame( '+1555', $result['phone'] );
		$this->assertSame( 'LeadCo', $result['company'] );
		$this->assertSame( 'Lead description text.', $result['description'] );
		$this->assertSame( 'Homepage', $result['source'] );
	}

	public function test_load_wp_data_lead_returns_empty_when_not_found(): void {
		$ref    = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$result = $ref->invoke( $this->module, 'lead', 999 );

		$this->assertSame( [], $result );
	}

	public function test_load_wp_data_lead_returns_empty_for_wrong_post_type(): void {
		$post              = new \stdClass();
		$post->ID          = 20;
		$post->post_title  = 'Not a Lead';
		$post->post_type   = 'post';
		$post->post_status = 'publish';
		$post->post_date   = '2026-02-10 12:00:00';

		$GLOBALS['_wp_posts'][20] = $post;

		$ref    = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$result = $ref->invoke( $this->module, 'lead', 20 );

		$this->assertSame( [], $result );
	}

	// ─── load_wp_data: Unknown Entity ──────────────────────

	public function test_load_wp_data_unknown_entity_returns_empty(): void {
		$ref    = new \ReflectionMethod( $this->module, 'load_wp_data' );
		$result = $ref->invoke( $this->module, 'unknown', 1 );

		$this->assertSame( [], $result );
	}

	// ─── get_dedup_domain ──────────────────────────────────

	public function test_dedup_domain_contact_with_email(): void {
		$ref    = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$result = $ref->invoke( $this->module, 'contact', [ 'email' => 'dedup@example.com' ] );

		$this->assertSame( [ [ 'email', '=', 'dedup@example.com' ] ], $result );
	}

	public function test_dedup_domain_contact_without_email(): void {
		$ref    = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$result = $ref->invoke( $this->module, 'contact', [ 'name' => 'No Email' ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_contact_with_empty_email(): void {
		$ref    = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$result = $ref->invoke( $this->module, 'contact', [ 'email' => '' ] );

		$this->assertSame( [], $result );
	}

	public function test_dedup_domain_lead_returns_empty(): void {
		$ref    = new \ReflectionMethod( $this->module, 'get_dedup_domain' );
		$result = $ref->invoke( $this->module, 'lead', [ 'email_from' => 'lead@test.com' ] );

		$this->assertSame( [], $result );
	}

	// ─── Dependency Status ─────────────────────────────────

	public function test_dependency_status_is_available(): void {
		$status = $this->module->get_dependency_status();
		$this->assertTrue( $status['available'] );
	}

	public function test_dependency_status_has_no_notices(): void {
		$status = $this->module->get_dependency_status();
		$this->assertEmpty( $status['notices'] );
	}

	// ─── Boot ──────────────────────────────────────────────

	public function test_boot_does_not_crash(): void {
		$this->module->boot();
		$this->assertTrue( true );
	}
}
