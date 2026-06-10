=== WordPress For Odoo ===
Contributors: paulargoud
Tags: odoo, woocommerce, erp, sync, crm
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 3.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bidirectional sync between WordPress/WooCommerce and Odoo ERP (v14+). 72 modules, async queue, webhooks, multisite and WP-CLI.

== Description ==

WordPress For Odoo creates a seamless, bidirectional bridge between WordPress/WooCommerce and Odoo ERP (v14+). Built on a clean, extensible architecture with 72 integration modules, an async sync queue, WordPress Multisite support (per-site company scoping), and full WP-CLI support. Ships in 3 languages (English, French, Spanish).

**Target users:** WordPress agencies and businesses running Odoo as their ERP who need reliable, real-time data flow between their website and back-office.

= Features =

* **Async Queue** — No API calls during user requests; all sync jobs go through a persistent database queue with exponential backoff, deduplication, and configurable batch size.
* **Dual Transport** — JSON-RPC 2.0 (default for Odoo 17+) and XML-RPC (legacy), swappable via settings.
* **Encrypted Credentials** — API keys encrypted at rest with libsodium (OpenSSL fallback).
* **Webhooks** — REST API endpoints for real-time notifications from Odoo, with per-IP rate limiting and HMAC verification.
* **Admin Dashboard** — 6-tab settings interface (Connection, Sync, Modules, Queue, Logs, Health) with guided onboarding and system health monitoring.
* **WP-CLI** — Full command suite: `wp wp4odoo status|test|sync|queue|module` for headless management.
* **WPML / Polylang Translation Sync** — Multilingual product sync: pushes translated names/descriptions to Odoo with language context, pulls translations back.
* **Multisite** — Each site in a network syncs with a specific Odoo company (`res.company`); shared network connection with per-site company mapping.
* **Extensible** — Register custom modules via `wp4odoo_register_modules`; filter data with `wp4odoo_map_to_odoo_*` / `wp4odoo_map_from_odoo_*`; map ACF or JetEngine custom fields to Odoo via meta-modules.

= Supported integrations =

CRM, Sales, WooCommerce (+ Subscriptions, Bookings, B2B, Points & Rewards, Bundles, Add-Ons, Inventory, Shipping, Returns, Rental), EDD, Ecwid, SureCart, Memberships (MemberPress, PMPro, RCP), GiveWP, Charitable, WP Simple Pay, Forms (11 plugins), Amelia, Bookly, FluentBooking, JetAppointments/JetBooking, LearnDash, LifterLMS, TutorLMS, LearnPress, Sensei, The Events Calendar, Modern Events Calendar, FooEvents, WP Job Manager, helpdesk (Awesome Support, SupportCandy, Fluent Support), AffiliateWP, FluentCRM, GamiPress, myCRED, BuddyBoss, Ultimate Member, WP ERP, Dokan, WCFM, WC Vendors, MailPoet, MC4WP, ACF, JetEngine, WP All Import, and more.

== Requirements ==

PHP 8.2+, MySQL 8.0+ / MariaDB 10.5+, WordPress 6.0+, Odoo 17+ (JSON-RPC) or 14+ (XML-RPC). No custom Odoo modules required — only the standard Odoo apps.

== Installation ==

1. Upload the plugin to `wp-content/plugins/wp4odoo/`, or install it from the Plugins screen.
2. Activate the plugin from the WordPress admin.
3. Go to **Odoo Connector** in the admin menu.
4. Enter your Odoo credentials (URL, database, username, API key) in the **Connection** tab.
5. Click **Test Connection** to verify.
6. Enable the modules you need in the **Modules** tab.

== Frequently Asked Questions ==

= Does it require a custom Odoo module? =

No. The plugin uses Odoo's standard external API (JSON-RPC / XML-RPC). Only the standard Odoo apps for the data you sync are required.

= Which Odoo versions are supported? =

Odoo 17+ uses JSON-RPC 2.0 (recommended). Odoo 14–16 uses XML-RPC (selectable in settings). Odoo < 14 is not supported.

= Is WooCommerce required? =

No. WooCommerce is optional and only needed for the WooCommerce-related modules.

= Does it support WordPress Multisite? =

Yes. Each site in a network can sync with a specific Odoo company, sharing a single network connection.

== Changelog ==

= 3.9.2 =
* Added: Optional persistent object-cache layer for WP↔Odoo entity mappings (Redis/Memcached).
* Changed: Distribution packaging (.distignore, readme.txt) and reproducible builds (committed composer.lock); CI dependency caching for integration tests.
* Fixed: CI integration test flakiness (wp-env start retry with backoff), README CI badge URL, Codecov token passing.

= 3.9.1 =
* Fixed: WP Crowdfunding 2.x compatibility, unescaped admin outputs, PHPDoc array types.
* Changed: Extracted shared Marketplace / LMS / Events / Booking base classes; test stub consolidation.

= 3.9.0 =
* Fixed: Numerous architecture, multisite, and reliability fixes across the sync engine, advisory locks, and translation services.

See [CHANGELOG.md](https://github.com/PaulArgoud/wp4odoo/blob/main/CHANGELOG.md) for the complete history.

== Upgrade Notice ==

= 3.9.2 =
Reliability, packaging, and an optional persistent entity-map cache. Safe upgrade.
