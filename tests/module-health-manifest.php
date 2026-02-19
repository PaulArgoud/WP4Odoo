<?php
declare( strict_types=1 );

/**
 * Module health manifest — expected third-party symbols per module.
 *
 * Used by ModuleHealthManifestTest to validate that all stubs match
 * the symbols each module actually depends on.
 *
 * Only symbols that exist in tests/stubs/ are listed. The test will
 * verify function_exists(), class_exists(), and defined() in the test
 * environment where stubs are loaded.
 *
 * @since 3.6.0
 */
return [

	// ─── Commerce ──────────────────────────────────────────

	'woocommerce'      => [
		'classes'   => [ 'WooCommerce', 'WC_Product', 'WC_Product_Variable', 'WC_Product_Variation', 'WC_Product_Attribute', 'WC_Order', 'WC_Order_Item', 'WC_DateTime' ],
		'functions' => [ 'wc_get_product', 'wc_get_order', 'wc_get_products', 'wc_update_product_stock', 'get_woocommerce_currency' ],
		'constants' => [ 'WC_VERSION' ],
	],

	'edd'              => [
		'classes'   => [ 'Easy_Digital_Downloads', 'EDD_Download', 'EDD_Customer' ],
		'functions' => [ 'edd_get_download', 'edd_get_order', 'edd_update_order_status' ],
		'constants' => [],
	],

	'sales'            => [
		'classes'   => [],
		'functions' => [],
		'constants' => [],
	],

	// ─── Memberships ──────────────────────────────────────

	'memberships'      => [
		'classes'   => [ 'WC_Memberships_User_Membership', 'WC_Memberships_Membership_Plan' ],
		'functions' => [ 'wc_memberships', 'wc_memberships_get_user_membership', 'wc_memberships_get_membership_plan' ],
		'constants' => [],
	],

	'memberpress'      => [
		'classes'   => [ 'MeprProduct', 'MeprTransaction', 'MeprSubscription' ],
		'functions' => [],
		'constants' => [ 'MEPR_VERSION' ],
	],

	'pmpro'            => [
		'classes'   => [ 'PMPro_Membership_Level', 'MemberOrder' ],
		'functions' => [ 'pmpro_getLevel', 'pmpro_getMembershipLevelForUser' ],
		'constants' => [],
	],

	'rcp'              => [
		'classes'   => [ 'RCP_Membership', 'RCP_Customer', 'RCP_Payments' ],
		'functions' => [ 'rcp_get_membership', 'rcp_get_membership_level', 'rcp_get_customer_by_user_id' ],
		'constants' => [],
	],

	// ─── Donations / Payments ─────────────────────────────

	'givewp'           => [
		'classes'   => [ 'Give' ],
		'functions' => [ 'give' ],
		'constants' => [ 'GIVE_VERSION' ],
	],

	'charitable'       => [
		'classes'   => [ 'Charitable' ],
		'functions' => [],
		'constants' => [],
	],

	'simplepay'        => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'SIMPLE_PAY_VERSION' ],
	],

	'wprm'             => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'WPRM_VERSION' ],
	],

	// ─── Bookings ─────────────────────────────────────────

	'amelia'           => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'AMELIA_VERSION' ],
	],

	'bookly'           => [
		'classes'   => [ 'Bookly\Lib\Plugin' ],
		'functions' => [],
		'constants' => [],
	],

	'fluent_booking'   => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'FLUENT_BOOKING_VERSION' ],
	],

	'jet_appointments' => [
		'classes'   => [ 'JET_APB\Plugin' ],
		'functions' => [],
		'constants' => [ 'JET_APB_VERSION' ],
	],

	'jet_booking'      => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'JET_ABAF_VERSION' ],
	],

	// ─── Project Management ───────────────────────────────

	'project_manager'  => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'CPM_VERSION' ],
	],

	// ─── LMS ──────────────────────────────────────────────

	'learndash'        => [
		'classes'   => [],
		'functions' => [ 'learndash_get_course_price', 'learndash_get_group_price', 'learndash_get_setting', 'learndash_user_get_course_date' ],
		'constants' => [],
	],

	'tutorlms'         => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'TUTOR_VERSION' ],
	],

	'lifterlms'        => [
		'classes'   => [ 'LLMS_Order', 'LLMS_Student' ],
		'functions' => [ 'llms_get_student', 'llms_get_enrolled_students' ],
		'constants' => [],
	],

	'learnpress'       => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'LP_PLUGIN_FILE', 'LEARNPRESS_VERSION' ],
	],

	'sensei'           => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'SENSEI_LMS_VERSION' ],
	],

	// ─── WooCommerce Extensions ──────────────────────────

	'wc_subscriptions'  => [
		'classes'   => [ 'WC_Subscriptions', 'WC_Subscription' ],
		'functions' => [ 'wcs_get_subscription' ],
		'constants' => [],
	],

	'wc_b2b'            => [
		'classes'   => [ 'B2bking' ],
		'functions' => [ 'wwp_get_wholesale_role_for_user', 'wwp_get_product_wholesale_price', 'wwp_get_all_wholesale_roles' ],
		'constants' => [ 'WWP_PLUGIN_VERSION' ],
	],

	'wc_points_rewards' => [
		'classes'   => [ 'WC_Points_Rewards', 'WC_Points_Rewards_Manager' ],
		'functions' => [],
		'constants' => [],
	],

	'wc_bookings'       => [
		'classes'   => [ 'WC_Booking', 'WC_Product_Booking' ],
		'functions' => [],
		'constants' => [],
	],

	'wc_bundle_bom'     => [
		'classes'   => [ 'WC_Bundles', 'WC_Composite_Products', 'WC_Product_Bundle', 'WC_Product_Composite', 'WC_Bundled_Item' ],
		'functions' => [],
		'constants' => [],
	],

	'wc_addons'         => [
		'classes'   => [ 'WC_Product_Addons' ],
		'functions' => [],
		'constants' => [ 'WC_PRODUCT_ADDONS_VERSION', 'THWEPO_VERSION', 'PPOM_VERSION' ],
	],

	'wc_inventory'      => [
		'classes'   => [],
		'functions' => [],
		'constants' => [],
	],

	'wc_shipping'       => [
		'classes'   => [ 'WC_Shipping_Zones', 'WC_Shipping_Method' ],
		'functions' => [],
		'constants' => [],
	],

	'wc_returns'        => [
		'classes'   => [],
		'functions' => [ 'wc_create_refund' ],
		'constants' => [],
	],

	// ─── Events / Jobs ────────────────────────────────────

	'events_calendar'   => [
		'classes'   => [ 'Tribe__Events__Main', 'Tribe__Tickets__Main' ],
		'functions' => [],
		'constants' => [],
	],

	'mec'               => [
		'classes'   => [ 'MEC' ],
		'functions' => [],
		'constants' => [ 'MEC_VERSION' ],
	],

	'fooevents'         => [
		'classes'   => [ 'FooEvents' ],
		'functions' => [],
		'constants' => [ 'FOOEVENTS_VERSION' ],
	],

	'job_manager'       => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'JOB_MANAGER_VERSION' ],
	],

	// ─── Product Configurator ────────────────────────────

	'jeero_configurator' => [
		'classes'   => [ 'Jeero_Product_Configurator' ],
		'functions' => [],
		'constants' => [ 'JEERO_VERSION' ],
	],

	// ─── Invoicing ────────────────────────────────────────

	'sprout_invoices'   => [
		'classes'   => [ 'SI_Invoice', 'SI_Payment', 'SI_Post_Type' ],
		'functions' => [],
		'constants' => [],
	],

	'wp_invoice'        => [
		'classes'   => [ 'WPI_Invoice' ],
		'functions' => [],
		'constants' => [],
	],

	// ─── Secondary E-commerce ────────────────────────────

	'surecart'          => [
		'classes'   => [ 'SureCart\Models\Product', 'SureCart\Models\Checkout', 'SureCart\Models\Subscription' ],
		'functions' => [],
		'constants' => [ 'SURECART_VERSION' ],
	],

	'ecwid'             => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'ECWID_PLUGIN_DIR' ],
	],

	'shopwp'            => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'SHOPWP_PLUGIN_DIR' ],
	],

	'crowdfunding'      => [
		'classes'   => [],
		'functions' => [ 'wpneo_crowdfunding_init' ],
		'constants' => [],
	],

	// ─── Marketplace ─────────────────────────────────────

	'dokan'             => [
		'classes'   => [ 'Dokan_Vendor', 'Dokan_Withdraw' ],
		'functions' => [ 'dokan_get_seller_id_by_order', 'dokan_get_earning_by_order', 'dokan_get_vendor_by_product', 'dokan_get_withdraw' ],
		'constants' => [ 'DOKAN_PLUGIN_VERSION' ],
	],

	'wcfm'              => [
		'classes'   => [ 'WCFMmp_Vendor' ],
		'functions' => [ 'wcfm_get_vendor_id_by_post', 'wcfm_get_vendor_store_name', 'wcfm_get_vendor_id_by_order', 'wcfm_get_commission', 'wcfm_get_withdrawal' ],
		'constants' => [ 'WCFM_VERSION', 'WCFMmp_VERSION' ],
	],

	'wc_vendors'        => [
		'classes'   => [ 'WCV_Vendors', 'WCV_Payout' ],
		'functions' => [],
		'constants' => [ 'WCV_PRO_VERSION' ],
	],

	// ─── Helpdesk ────────────────────────────────────────

	'awesome_support'   => [
		'classes'   => [],
		'functions' => [ 'wpas_insert_ticket', 'wpas_update_ticket_status', 'wpas_get_ticket_status' ],
		'constants' => [ 'WPAS_VERSION' ],
	],

	'supportcandy'      => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'WPSC_VERSION' ],
	],

	'fluent_support'    => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'FLUENT_SUPPORT_VERSION' ],
	],

	// ─── Affiliate ───────────────────────────────────────

	'affiliatewp'       => [
		'classes'   => [ 'AffWP_Affiliate', 'AffWP_Referral' ],
		'functions' => [ 'affiliate_wp', 'affwp_get_affiliate', 'affwp_get_referral' ],
		'constants' => [ 'AFFILIATEWP_VERSION' ],
	],

	// ─── Marketing CRM ───────────────────────────────────

	'fluentcrm'         => [
		'classes'   => [ 'FluentCrm\App\Models\Subscriber', 'FluentCrm\App\Models\Lists', 'FluentCrm\App\Models\Tag' ],
		'functions' => [],
		'constants' => [ 'FLUENTCRM', 'FLUENTCRM_PLUGIN_VERSION' ],
	],

	'mailpoet'          => [
		'classes'   => [ 'MailPoet\API\API' ],
		'functions' => [],
		'constants' => [ 'MAILPOET_VERSION' ],
	],

	'mc4wp'             => [
		'classes'   => [ 'MC4WP_Form' ],
		'functions' => [ 'mc4wp_get_api_v3' ],
		'constants' => [ 'MC4WP_VERSION' ],
	],

	// ─── Funnel / Sales Pipeline ─────────────────────────

	'funnelkit'         => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'WFFN_VERSION' ],
	],

	// ─── Gamification / Loyalty ──────────────────────────

	'gamipress'         => [
		'classes'   => [],
		'functions' => [ 'gamipress', 'gamipress_get_user_points', 'gamipress_award_points_to_user', 'gamipress_deduct_points_to_user' ],
		'constants' => [ 'GAMIPRESS_VERSION' ],
	],

	'mycred'            => [
		'classes'   => [],
		'functions' => [ 'mycred', 'mycred_get_users_cred', 'mycred_add', 'mycred_subtract' ],
		'constants' => [ 'myCRED_VERSION' ],
	],

	// ─── Community ───────────────────────────────────────

	'buddyboss'         => [
		'classes'   => [],
		'functions' => [ 'buddypress', 'bp_get_profile_field_data', 'xprofile_set_field_data', 'groups_get_group', 'groups_get_user_groups' ],
		'constants' => [ 'BP_VERSION' ],
	],

	'ultimate_member'   => [
		'classes'   => [ 'UM' ],
		'functions' => [],
		'constants' => [ 'UM_VERSION' ],
	],

	// ─── HR / ERP ────────────────────────────────────────

	'wperp'             => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'WPERP_VERSION' ],
	],

	'wperp_crm'         => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'WPERP_VERSION' ],
	],

	'wperp_accounting'  => [
		'classes'   => [],
		'functions' => [ 'erp_acct_get_dashboard_overview' ],
		'constants' => [ 'WPERP_VERSION' ],
	],

	// ─── Knowledge / Documents ──────────────────────────

	'knowledge'         => [
		'classes'   => [],
		'functions' => [],
		'constants' => [],
	],

	'documents'         => [
		'classes'   => [ 'Document_Revisions' ],
		'functions' => [],
		'constants' => [ 'WPDM_VERSION' ],
	],

	// ─── Generic CPT / Meta ──────────────────────────────

	'jetengine'         => [
		'classes'   => [ 'Jet_Engine' ],
		'functions' => [],
		'constants' => [ 'JET_ENGINE_VERSION' ],
	],

	'jetengine_meta'    => [
		'classes'   => [ 'Jet_Engine' ],
		'functions' => [],
		'constants' => [ 'JET_ENGINE_VERSION' ],
	],

	// ─── Meta-modules ────────────────────────────────────

	'acf'               => [
		'classes'   => [ 'ACF' ],
		'functions' => [ 'get_field', 'update_field', 'get_field_object' ],
		'constants' => [ 'ACF_MAJOR_VERSION' ],
	],

	'wpai'              => [
		'classes'   => [],
		'functions' => [ 'wp_all_import_get_import_id' ],
		'constants' => [ 'PMXI_VERSION' ],
	],

	// ─── Food Ordering ───────────────────────────────────

	'food_ordering'     => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'FLAVOR_FLAVOR_VERSION', 'WPPIZZA_VERSION', 'RP_VERSION' ],
	],

	// ─── Survey & Quiz ───────────────────────────────────

	'survey_quiz'       => [
		'classes'   => [],
		'functions' => [],
		'constants' => [ 'QUIZ_MAKER_VERSION', 'QSM_PLUGIN_INSTALLED' ],
	],

	// ─── Forms ───────────────────────────────────────────

	'forms'             => [
		'classes'   => [ 'GFAPI', 'GF_Field', 'WPCF7_ContactForm', 'WPCF7_Submission', 'WPCF7_FormTag', 'FrmAppHelper', 'FrmField', 'FrmEntryMeta', 'FrmForm', 'Ninja_Forms' ],
		'functions' => [ 'wpforms', 'et_setup_theme' ],
		'constants' => [ 'WPCF7_VERSION', 'FLUENTFORM', 'FORMINATOR_VERSION', 'JET_FORM_BUILDER_VERSION', 'ELEMENTOR_PRO_VERSION', 'BRICKS_VERSION' ],
	],

	// ─── WC Rental ──────────────────────────────────────

	'wc_rental'         => [
		'classes'   => [ 'WooCommerce' ],
		'functions' => [],
		'constants' => [],
	],

	// ─── Field Service ──────────────────────────────────

	'field_service'     => [
		'classes'   => [],
		'functions' => [],
		'constants' => [],
	],

	// ─── CRM (core) ─────────────────────────────────────

	'crm'               => [
		'classes'   => [],
		'functions' => [],
		'constants' => [],
	],

];
