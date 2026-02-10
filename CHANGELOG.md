# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.9.0] - 2026-02-10

### Added

#### Onboarding — Activation Redirect & Setup Notice
- Post-activation redirect to the settings page (consumes a transient set by `activate()`)
- Dismissible admin notice on all pages until the Odoo connection is configured
- Inline `<script>` AJAX dismiss via `wp4odoo_dismiss_onboarding` handler — no need to enqueue `admin.js` globally
- New option: `wp4odoo_onboarding_dismissed`

#### Onboarding — Setup Checklist
- Progress bar + 3-step checklist on the settings page (between `<h1>` and tabs)
- Auto-detected steps: Connect Odoo, Enable a module, Configure webhooks in Odoo
- Auto-dismiss when all steps completed; manual dismiss via × button
- "Configure webhooks" step uses a "Mark as done" action (no server-side verification possible)
- 2 new AJAX handlers: `wp4odoo_dismiss_checklist`, `wp4odoo_confirm_webhooks`
- New options: `wp4odoo_checklist_dismissed`, `wp4odoo_checklist_webhooks_confirmed`

#### Documentation — Inline Odoo Help
- 3 collapsible `<details>` blocks in the Connection tab (pure HTML, no JS required):
  1. **Getting Started** (shown only if URL is empty): prerequisites + required Odoo modules per feature
  2. **How to generate an API key**: step-by-step instructions for Odoo 14-16 and 17+
  3. **How to configure webhooks**: native webhooks (17+) and Automated Actions (14-16) with Python code template

#### WP-CLI Commands
- `wp wp4odoo status` — connection info, queue stats, module list
- `wp wp4odoo test` — test Odoo connection
- `wp wp4odoo sync run` — process queue
- `wp wp4odoo queue stats [--format=table|json|csv]` — queue statistics
- `wp wp4odoo queue list [--page=N] [--per-page=N]` — paginated job list
- `wp wp4odoo queue retry` — retry all failed jobs
- `wp wp4odoo queue cleanup [--days=N]` — delete old completed/failed jobs
- `wp wp4odoo queue cancel <id>` — cancel a pending job
- `wp wp4odoo module list` — list modules with enabled/disabled status
- `wp wp4odoo module enable <id>` — enable a module
- `wp wp4odoo module disable <id>` — disable a module
- New file: `includes/class-cli.php` — loaded only in WP-CLI context (not via `Dependency_Loader`)

### Changed
- `Admin_Ajax` now has 15 handlers (added `dismiss_onboarding`, `dismiss_checklist`, `confirm_webhooks`)
- `Admin` — 2 new hooks: `admin_init` (redirect), `admin_notices` (setup notice)
- `Settings_Page` — `render_setup_checklist()` with `has_any_module_enabled()` helper
- `admin.js` — 2 new bindings: `bindDismissChecklist()`, `bindConfirmWebhooks()`
- `admin.css` — inline help section styles, setup checklist styles
- `Sync_Engine::get_stats()` docblock updated to include `last_completed_at`
- PHPStan: 0 errors on 40 files
- PHPUnit: 138 tests, 215 assertions
- Plugin version bumped from 1.8.0 to 1.9.0

#### New Files
- `includes/class-cli.php` — WP-CLI command class
- `admin/views/partial-checklist.php` — Setup checklist template
- `phpstan-wp-cli-stubs.php` — `WP_CLI\Utils` namespace stubs for PHPStan

#### Translations
- Regenerated `.pot`, merged `.po`, recompiled `.mo` — 249 translated strings, 0 fuzzy, 0 untranslated (was 205)
- 44 new French translations for onboarding, inline help, and checklist strings

#### Documentation
- `ARCHITECTURE.md` — updated with CLI, onboarding, checklist, inline help sections; updated file counts and handler counts
- `README.md` — updated with WP-CLI commands, onboarding mention, new file/handler counts

## [1.8.0] - 2026-02-10

### Fixed

#### Admin UI — Module Toggle Error Handling
- `bindModuleToggles()` now reverts the toggle switch and settings panel on AJAX error or network failure (was silently failing)
- Toggle is disabled during the request to prevent double-clicks

#### Admin UI — Loading Feedback
- `fetchLogs()` now shows a "Loading..." row before AJAX fetch (no visual feedback previously)
- `fetchQueue()` shows the same loading state for queue table

#### Admin UI — Queue Pagination Inconsistency
- Queue tab now uses AJAX pagination matching the Logs tab pattern (was full page reload)
- New `fetch_queue` AJAX handler in `Admin_Ajax` reusing `Query_Service::get_queue_jobs()`
- New `fetchQueue()` and `bindQueuePagination()` JS methods for client-side table rendering

### Added

#### Admin UI — Responsive CSS
- `@media (max-width: 782px)` breakpoint: stats grid 2-column, modules grid single-column, filters stacked, Direction/Attempts queue columns hidden on mobile
- `.wp4odoo-table-wrap` horizontal scroll wrapper for queue table on narrow screens

#### Admin UI — "Last sync" Timestamp
- `Sync_Queue_Repository::get_stats()` now returns `last_completed_at` (`MAX(processed_at)` from completed jobs)
- Timestamp displayed next to Refresh button on Queue tab, updated live via `refreshStats()`
- 7 new i18n strings in `wp_localize_script`: `loading`, `lastSync`, `cancel`, `statusPending`, `statusProcessing`, `statusCompleted`, `statusFailed`

#### Admin UI — Connection Form Validation
- `required` attribute on URL, Database, and Username inputs (native HTML5 validation)
- "Test connection" button disabled when any required field is empty (`bindConnectionValidation()`)

#### Tests
- `SyncQueueRepositoryTest` — 2 new tests for `get_stats()` `last_completed_at` (with timestamp, null case)

### Changed
- `Admin_Ajax` now has 12 handlers (added `fetch_queue`)
- `admin.js` — `bindRetryFailed()` and `bindCleanupQueue()` now also call `fetchQueue(1)` after stats refresh
- PHPStan: 0 errors on 36 files
- PHPUnit: 138 tests, 215 assertions (was 136/209)
- Plugin version bumped from 1.7.0 to 1.8.0

#### Translations
- Regenerated `.pot`, merged `.po`, recompiled `.mo` — 205 translated strings, 0 fuzzy, 0 untranslated
- Translated 7 new strings + fixed 6 stale fuzzy entries from previous versions

#### Documentation
- `ARCHITECTURE.md` — updated test counts (138/215), SyncQueueRepositoryTest count (16→18), AJAX handler count (12), added `fetch_queue` to handler list
- `README.md` — updated test counts (138/215)

## [1.7.0] - 2026-02-10

### Added

#### Multi-Currency Support
- Currency awareness across all monetary fields: Odoo `currency_id` (Many2one) is now extracted, resolved to ISO 4217 code, and stored
- **WooCommerce products/variants**: currency guard skips price update when Odoo currency differs from WC shop currency (`get_woocommerce_currency()`), logs warning; stores Odoo currency code in `_wp4odoo_currency` product meta
- **Invoices (both modules)**: `_invoice_currency` stored as CPT post meta from Odoo `currency_id`
- **Orders (Sales module)**: `_order_currency` stored as CPT post meta from Odoo `currency_id`
- **Customer portal**: currency code displayed after amounts (e.g., `1 500,00 EUR`)
- PHPUnit tests for currency extraction and mapping (CurrencyTest)

### Changed
- `WooCommerce_Module` — added `currency_id` to product, variant, and invoice mappings; currency guard in `save_product_data()` and `save_variant_data()`; Many2one resolution in `save_invoice_data()`
- `Variant_Handler` — added `currency_id` to `search_read` fields; currency guard in variant loop
- `Sales_Module` — added `currency_id` to order and invoice mappings; `_order_currency` and `_invoice_currency` in meta constants; Many2one resolution in save methods
- `Portal_Manager` — added currency meta keys to order and invoice queries
- PHPStan: 0 errors on 36 files
- PHPUnit: 136 tests, 209 assertions (was 127/196)
- Plugin version bumped from 1.6.0 to 1.7.0

#### Documentation
- `README.md` — updated test counts (136/209), added multi-currency guard and product image pull to features and module table, removed 2 undocumented filters (`wp4odoo_order_status_map`, `wp4odoo_woo_product_to_odoo`)
- `ARCHITECTURE.md` — updated test counts (136/209), added CurrencyTest to directory listing, fixed individual test counts (PartnerService 11→10, WooCommerceModule 21→22, BulkSync 10→12), added currency fields to Sales field mappings, added multi-currency guard to WooCommerce key features, removed same 2 ghost filters

## [1.6.0] - 2026-02-10

### Added

#### WooCommerce Module — Product Image Pull from Odoo
- `Image_Handler` (`includes/modules/class-image-handler.php`) — decodes Odoo `image_1920` base64, creates WP media attachment, sets as WC product thumbnail; SHA-256 hash stored in `_wp4odoo_image_hash` post meta to skip unchanged images; MIME detection via `finfo`; automatic cleanup of previous Odoo-sourced thumbnails
- `sync_product_images` setting (default: enabled) in WooCommerce module — controls whether featured images are pulled from Odoo
- Odoo data capture via `wp4odoo_map_from_odoo_woocommerce_product` filter — reuses the existing `read()` response (zero extra API calls)
- PHPUnit tests for Image_Handler (edge cases, valid PNG/JPEG imports)

### Changed
- `WooCommerce_Module` — added `Image_Handler` integration, `capture_odoo_data()` filter, `maybe_pull_product_image()` post-pull step, new `sync_product_images` setting field
- PHPStan: 0 errors on 36 files (was 35)
- PHPUnit: 127 tests, 196 assertions (was 118/186)
- Plugin version bumped from 1.5.0 to 1.6.0

## [1.5.0] - 2026-02-10

### Added

#### Core — Extracted Classes for Single Responsibility
- `Dependency_Loader` (`includes/class-dependency-loader.php`) — centralized `require_once` for all 30+ plugin class files, extracted from `WP4Odoo_Plugin::load_dependencies()`
- `Database_Migration` (`includes/class-database-migration.php`) — `create_tables()` (SQL DDL via `dbDelta`) and `set_default_options()`, extracted from `WP4Odoo_Plugin`
- `Module_Registry` (`includes/class-module-registry.php`) — module registration, WooCommerce/Sales mutual exclusivity rules, lifecycle (`register_all()`, `register()`, `get()`, `all()`), extracted from `WP4Odoo_Plugin`
- `CPT_Helper` (`includes/class-cpt-helper.php`) — shared static helpers for Custom Post Type registration, loading, and saving with Many2one resolution; used by Sales_Module and WooCommerce_Module
- `Bulk_Handler` (`includes/admin/class-bulk-handler.php`) — encapsulates bulk product import/export logic (fetch IDs, check mappings, enqueue jobs), extracted from `Admin_Ajax`
- `Contact_Manager` (`includes/modules/class-contact-manager.php`) — contact data load/save, email dedup on pull, role-based sync check, billing meta field handling; extracted from `CRM_Module`

### Changed

#### Refactoring — Deduplication and SRP
- `wp4odoo.php` — reduced from ~440 to ~270 lines; constructor delegates to `Dependency_Loader::load()` + `Module_Registry`; `activate()` delegates to `Database_Migration`; removed `load_dependencies()`, `create_tables()`, `set_default_options()`, `register_modules()`
- `class-sales-module.php` — reduced from ~380 to ~295 lines; `register_order_cpt()`/`register_invoice_cpt()` delegate to `CPT_Helper::register()`; load/save methods delegate to `CPT_Helper::load()`/`CPT_Helper::save()`; removed private `register_cpt()`, `load_cpt_data()`, `save_cpt_data()`
- `class-woocommerce-module.php` — reduced from ~818 to ~770 lines; invoice CPT registration/load/save delegate to `CPT_Helper`
- `class-crm-module.php` — reduced from ~510 to ~320 lines; contact data ops delegate to `Contact_Manager`; `CONTACT_META_FIELDS` constant moved to `Contact_Manager`; removed `load_contact_data()`, `save_contact_data()`, `should_sync_user()`
- `class-admin-ajax.php` — reduced from ~454 to ~390 lines; `bulk_import_products()`/`bulk_export_products()` are thin wrappers delegating to `Bulk_Handler`
- `admin/js/admin.js` — generic `bindBulkAction(selector, action, confirmKey)` replaces duplicate `bindBulkImport()`/`bindBulkExport()` methods
- PHPStan: 0 errors on 35 files (was 29)
- Plugin version bumped from 1.4.0 to 1.5.0

## [1.4.0] - 2026-02-10

### Added

#### WooCommerce Module — Bulk Product Import/Export
- `bulk_import_products` AJAX handler: searches all Odoo `product.template` IDs, enqueues pull jobs (create or update based on existing mapping) via the queue system
- `bulk_export_products` AJAX handler: iterates all WooCommerce product IDs, enqueues push jobs (create or update based on existing mapping) via the queue system
- Admin UI: "Bulk Operations" section in Sync tab with "Import all products from Odoo" and "Export all products to Odoo" buttons, confirm dialogs, success notices
- Admin JS bindings: `bindBulkImport()`, `bindBulkExport()` with `wp4odooAdmin.i18n.confirmBulkImport` / `confirmBulkExport`

#### WooCommerce Module — Product Variants (Pull from Odoo)
- `Variant_Handler` class (`includes/modules/class-variant-handler.php`, ~270 lines): dedicated handler for importing Odoo `product.product` variants into WooCommerce variable products
  - `pull_variants()`: reads all variants for a template, converts parent to variable product, creates/updates WC variations with SKU, price, stock, weight, and attributes
  - `ensure_variable_product()`: converts a simple WC product to variable if needed (`wp_set_object_terms` + `WC_Product_Variable`)
  - `save_variation()`: creates or updates a `WC_Product_Variation` with field data and attributes
  - `collect_attributes()`: batch-reads `product.template.attribute.value` from Odoo, returns structured attribute data
  - `set_parent_attributes()`: registers `WC_Product_Attribute` objects on the variable parent
- `variant` entity type in `WooCommerce_Module`:
  - `$odoo_models['variant'] = 'product.product'`
  - `$default_mappings['variant']`: sku→default_code, regular_price→lst_price, stock_quantity→qty_available, weight→weight, display_name→display_name
  - `load_variant_data()`, `save_variant_data()`, `delete_wp_data('variant')` added to match expressions
- `pull_from_odoo()` override in `WooCommerce_Module`:
  - Variant entities: delegates to `pull_variant()` → `Variant_Handler`
  - Product entities: calls parent pull, then auto-enqueues variant pulls via `enqueue_variants_for_template()` when >1 variant exists
  - Single-variant templates (simple products) are skipped automatically
- `save_stock_data()` updated: also checks `variant` entity mapping since `stock.quant` references `product.product` IDs, not `product.template`
- Entity mapping for variants: `entity_type = 'variant'`, `odoo_model = 'product.product'`

#### Tests
- `VariantHandlerTest` — 7 tests: instantiation, save_variation (new, empty SKU, with attributes, zero weight), ensure_variable_product, pull_variants with empty data
- `BulkSyncTest` — 10 tests: entity mapping lookups, queue enqueue patterns, bulk import/export create/update logic, variant mapping save
- Updated `WooCommerceModuleTest` — 3 new tests: variant model declaration, variant mapping (forward + reverse)
- WC class stubs in `tests/bootstrap.php`: `WC_Product`, `WC_Product_Variable`, `WC_Product_Variation`, `WC_Product_Attribute`, `wc_get_product()`, `wc_get_products()`, `wc_update_product_stock()`, `wp_set_object_terms()`, `sanitize_title()`
- WC class stubs in `phpstan-bootstrap.php`: `WC_Product_Variable`, `WC_Product_Variation`, `WC_Product_Attribute`, `wc_get_products()`

### Changed

- `Admin_Ajax` now has 11 handlers (added `bulk_import_products`, `bulk_export_products`)
- `admin.js` expanded with `bindBulkImport()` and `bindBulkExport()` methods
- `class-admin.php`: 2 new i18n strings (`confirmBulkImport`, `confirmBulkExport`)
- `tab-sync.php`: added "Bulk Operations" section after the settings form
- `phpstan-bootstrap.php`: version bump + WC variant stubs
- 118 tests, 186 assertions — all green (was 95 tests, 154 assertions)
- PHPStan: 0 errors on 29 files (was 28)
- Plugin version bumped from 1.3.1 to 1.4.0
- Minimum Odoo version lowered from 17 to 14 (XML-RPC transport supports Odoo 14-16; JSON-RPC requires 17+)

## [1.3.1] - 2026-02-10

### Added

#### Security — Webhook Rate Limiting
- Per-IP rate limiting on `/wp4odoo/v1/webhook` endpoint: 100 requests per 60-second window
- Returns HTTP 429 (Too Many Requests) when limit exceeded
- Rate check runs before token validation to protect against brute-force

#### WooCommerce HPOS Compatibility
- `declare_hpos_compatibility()` method via `before_woocommerce_init` hook
- Declares compatibility with WooCommerce Custom Order Tables (High-Performance Order Storage)

#### CI/CD — GitHub Actions
- `.github/workflows/ci.yml` — automated testing on push/PR to main
- Matrix: PHP 8.2 + 8.3, PHPUnit + PHPStan
- CI badge added to README.md

### Fixed

#### CI/CD — Permission denied
- `ci.yml`: prefixed `vendor/bin/phpunit` and `vendor/bin/phpstan` with `php` to avoid executable permission issues on GitHub Actions runners

### Changed

#### Sync Engine — MySQL Advisory Locking
- Replaced transient-based locking with MySQL `GET_LOCK()` / `RELEASE_LOCK()` in `Sync_Engine`
- Atomic, server-level locking — eliminates race conditions with object caching plugins
- Lock timeout reduced from 300s to 1s (non-blocking check: skip if already locked)

#### Plugin Metadata
- Plugin URI: `https://github.com/PaulArgoud/wordpress-for-odoo`
- Author: Paul ARGOUD, Author URI: `https://paul.argoud.net`

#### Documentation
- `ARCHITECTURE.md` — 8 corrections: Partner_Service added, WooCommerce marked COMPLETE, test files updated (7 of 7), locking/rate limiting/HPOS documented, filters no longer marked "planned"
- `README.md` — CI badge, HPOS mention, rate limiting, `/sync` endpoint added

#### Cleanup
- Deleted orphan `messages.mo` at project root
- Added `index.php` in `tests/` and `tests/Unit/`
- Fixed `.gitignore`: removed duplicate `composer.phar` entry
- Plugin version bumped from 1.3.0 to 1.3.1

## [1.3.0] - 2026-02-10

### Added

#### WooCommerce Module
- `WooCommerce_Module` — full implementation with WC-native post types: product sync (`wc_get_product`/`WC_Product`), order sync (`wc_get_order`/`WC_Order`), stock pull from Odoo (`wc_update_product_stock`), and invoice CPT (`wp4odoo_invoice`)
- Odoo sale.order state → WC order status mapping (draft→pending, sent→on-hold, sale→processing, done→completed, cancel→cancelled)
- WC hook callbacks with anti-loop protection: `woocommerce_update_product`, `woocommerce_new_order`, `woocommerce_order_status_changed`, `before_delete_post`
- Settings fields: sync_products, sync_orders, sync_stock, auto_confirm_orders

#### Partner_Service — Shared res.partner Management
- `Partner_Service` — new core class for centralized Odoo partner lookup and creation: `get_partner_id_for_user()`, `get_user_for_partner()`, `get_or_create()` (~130 lines)
- 3-step resolution: check entity_map → search Odoo by email → create new partner
- Automatic mapping persistence when a WP user ID is provided
- Used by Portal_Manager and WooCommerce_Module (replaces direct Entity_Map_Repository calls)

#### Mutual Exclusion
- Sales_Module and WooCommerce_Module cannot be active simultaneously (same Odoo models: sale.order, product.template)
- Exclusion logic in `register_modules()`: when WC is active and woocommerce module enabled, Sales_Module is not loaded
- Admin UI notice: "Enabling the WooCommerce module replaces the Sales module."

#### Tests
- `PartnerServiceTest` — 11 tests: partner lookup, Odoo search, partner creation, mapping persistence, email-as-name fallback
- `WooCommerceModuleTest` — 18 tests: module identity, Odoo model declarations, default settings, settings fields, field mappings (forward + reverse), boot safety without WC
- WC stubs in `phpstan-bootstrap.php`: `wc_get_product()`, `wc_get_order()`, `wc_update_product_stock()`, `WC_Product`, `WC_Order`, `WC_DateTime`

### Changed

- `Portal_Manager` — now receives `Partner_Service` via constructor injection (was calling `Entity_Map_Repository` directly)
- `Sales_Module::boot()` — creates `Partner_Service` instance and passes it to `Portal_Manager`
- `phpstan-bootstrap.php` — added WooCommerce function/class stubs
- `tests/bootstrap.php` — added `apply_filters()` stub, `Partner_Service` require, `WooCommerce_Module` require
- 95 tests, 154 assertions — all green (was 67 tests, 107 assertions)
- PHPStan: 0 errors on 28 files (was 27)
- Plugin version bumped from 1.2.0 to 1.3.0

## [1.2.0] - 2026-02-10

### Added

#### Repository Classes — DB Access Centralization
- `Entity_Map_Repository` — new class centralizing all `wp4odoo_entity_map` DB operations: `get_odoo_id()`, `get_wp_id()`, `save()`, `remove()` (~80 lines)
- `Sync_Queue_Repository` — new class centralizing all `wp4odoo_sync_queue` DB operations: `enqueue()`, `fetch_pending()`, `update_status()`, `get_stats()`, `cleanup()`, `retry_failed()`, `cancel()`, `get_pending()` (~170 lines)

#### PHPStan Static Analysis
- `phpstan.neon` — configuration at level 5 with `szepeviktor/phpstan-wordpress` stubs
- `phpstan-bootstrap.php` — stubs for plugin constants and `WP4Odoo_Plugin` singleton
- 0 errors on 27 source files

#### PHPUnit Test Coverage Extension
- `SyncQueueRepositoryTest` — 16 tests: enqueue validation, defaults, deduplication, cancel
- `EntityMapRepositoryTest` — 10 tests: CRUD operations, return types, where clauses
- `QueueManagerTest` — 7 tests: push/pull arg assembly, cancel/get_pending delegation
- `WP_DB_Stub` test helper: configurable $wpdb mock with call recording

### Fixed

#### Lead Form Bug
- Fixed `compact('description')` referencing undefined variable in `Lead_Manager::handle_lead_submission()` — the variable was `$desc`, not `$description` (found by PHPStan)

#### Product Sync Gap
- `Sales_Module` — `load_wp_data()`, `save_wp_data()`, `delete_wp_data()` now log a warning when called with the `product` entity type (declared in `$odoo_models` but not yet implemented, was silently returning empty data)

### Changed

#### Refactoring — Elimination of Duplications and Repository Extraction
- `Module_Base` — 4 entity mapping methods now delegate to `Entity_Map_Repository` (removed `global $wpdb`)
- `Sync_Engine` — `process_queue()` and `handle_failure()` delegate DB access to `Sync_Queue_Repository`; `enqueue()`, `get_stats()`, `cleanup()`, `retry_failed()` are now thin forwarders (removed `global $wpdb`)
- `Queue_Manager` — `cancel()` and `get_pending()` delegate to `Sync_Queue_Repository` (removed `global $wpdb`)
- `Portal_Manager` — `get_partner_id_for_user()` delegates to `Entity_Map_Repository` (removed `global $wpdb`)
- `Sales_Module` — deduplicated 3 pairs of nearly identical CPT methods via parameterized private helpers: `register_cpt()`, `load_cpt_data()`, `save_cpt_data()`
- `CRM_Module` — added log line at pull email dedup for consistency with push dedup
- `global $wpdb` now only appears in repository classes, `Query_Service`, and `Logger`
- Plugin version bumped from 1.1.1 to 1.2.0

#### Tests
- `tests/bootstrap.php` — `WP_DB_Stub` class, WP function stubs (`sanitize_text_field`, `absint`, `current_time`, transient stubs), require_once for repository and queue classes
- 67 tests, 107 assertions — all green (was 34 tests, 47 assertions)

#### Documentation
- `ARCHITECTURE.md` — added repository files to directory tree, noted delegation in Module_Base description
- `CHANGELOG.md` — added this entry

#### Translations
- Regenerated `.pot`, merged `.po`, recompiled `.mo`

## [1.1.1] - 2026-02-10

### Fixed

#### Double Prefix Bug
- Fixed `wp4wp4odoo_` double prefix in 5 occurrences across CRM/Lead components — now consistently uses `wp4odoo_`
- Shortcode: `wp4wp4odoo_lead_form` → `wp4odoo_lead_form` (`class-crm-module.php`)
- Nonce: `wp4wp4odoo_lead` → `wp4odoo_lead` (`class-lead-manager.php`, create + verify)
- Action hook: `wp4wp4odoo_lead_created` → `wp4odoo_lead_created` (`class-lead-manager.php`)
- Setting description: referenced shortcode name corrected (`class-crm-module.php`)

### Changed
- Plugin version bumped from 1.1.0 to 1.1.1

#### Documentation Audit
- `ARCHITECTURE.md` — fixed all `wp4wp4odoo_` references, added missing DB columns (`max_attempts`, `error_message`, `processed_at`, `odoo_model`, `last_synced_at`), clarified contact field mapping (first_name/last_name stored as `x_wp_first_name`/`x_wp_last_name` then composed by `Contact_Refiner`), marked WooCommerce module as stub, marked unimplemented filters as planned
- `CHANGELOG.md` — added this entry

#### Translations
- Regenerated `.pot`, merged `.po`, recompiled `.mo` — 181 translated strings, 0 fuzzy

#### Cleanup
- Deleted orphan files: `messages.mo` (root), `odoo-wp-connector-fr_FR.po~`, `wp4odoo-fr_FR.po~` (languages/)

## [1.1.0] - 2026-02-10

### Changed

#### Plugin Rebrand
- Renamed plugin from "Odoo WP Connector" to "WordPress For Odoo" (WP4Odoo)
- New prefixes: `WP4ODOO_` (constants), `wp4odoo_` (options/hooks/AJAX/tables), `wp4odoo-` (CSS/handles)
- New namespace: `WP4Odoo\` (was `OdooWPC\`)
- New text domain: `wp4odoo` (was `odoo-wp-connector`)
- Main plugin file: `wp4odoo.php` (was `odoo-wp-connector.php`)
- Plugin folder: `wp4odoo/` (was `wp-odoo-connector/`)
- CPTs renamed: `wp4odoo_order`, `wp4odoo_invoice`, `wp4odoo_lead` (were `odoo_*`)
- Shortcodes renamed: `[wp4odoo_customer_portal]`, `[wp4odoo_lead_form]`
- REST namespace: `wp4odoo/v1` (was `odoo-wpc/v1`)
- Added `.gitignore` and `LICENSE` (GPL-2.0-or-later) for GitHub distribution

### Added

#### Sales Module — Full Implementation
- Upgraded Sales module from stub (~58 lines) to full implementation (~350 lines, 12 methods)
- `wp4odoo_order` CPT for local storage of Odoo `sale.order` records
- `wp4odoo_invoice` CPT for local storage of Odoo `account.move` records
- Field mappings: 5 fields for orders (name, total, date, state, partner_id), 6 fields for invoices (name, total, date, state, payment_state, partner_id)
- `ORDER_META` and `INVOICE_META` private constants for meta field definitions
- Many2one `partner_id` resolution via `Field_Mapper::many2one_to_id()` on save
- `get_settings_fields()` — 3 admin UI settings fields (import_products, portal_enabled, orders_per_page)
- Delegates portal rendering to `Portal_Manager` via dependency injection (closures)

#### Customer Portal
- `Portal_Manager` class (~200 lines): shortcode rendering, AJAX pagination, data queries
- `[wp4odoo_customer_portal]` shortcode for logged-in users to view their orders and invoices
- CRM entity_map bridge: WP user → Odoo partner_id → orders/invoices via `_wp4odoo_partner_id` meta
- Tab interface (Orders / Invoices) with status badges and pagination
- AJAX `wp4odoo_portal_data` handler for tab switching and pagination
- Login prompt for unauthenticated users, "not linked" message for users without CRM mapping
- `templates/customer-portal.php` — portal HTML template
- `assets/css/portal.css` — portal styling with responsive breakpoints
- `assets/js/portal.js` — tab switching and AJAX pagination

#### PHPUnit Tests
- `composer.json` with PHPUnit 10+/11+ dependency
- `phpunit.xml` configuration targeting `tests/Unit/`
- `tests/bootstrap.php` — minimal WordPress function stubs (ABSPATH, wp_json_encode, Logger)
- `tests/Unit/FieldMapperTest.php` — 30 test methods for Field_Mapper pure PHP methods
- `tests/Unit/ModuleBaseHashTest.php` — 4 test methods for `generate_sync_hash()` via concrete stub
- 34 tests, 47 assertions, all green

#### Security
- `index.php` "silence is golden" files in all 13 plugin subdirectories to prevent directory listing

### Changed
- Plugin version bumped from 1.0.3 to 1.1.0
- `includes/modules/class-sales-module.php` — rewritten from stub to full implementation
- French translation updated: 181 translated strings (29 new strings for Sales/Portal)
- `wp4odoo.php` — added `require_once` for `class-portal-manager.php`

## [1.0.3] - 2026-02-09

### Changed

#### Refactoring — CRM Module Split
- Extracted `Lead_Manager` from `CRM_Module`: CPT registration, shortcode, form submission, lead data load/save (~200 lines)
- Extracted `Contact_Refiner` from `CRM_Module`: name composition/splitting, country/state resolution filters (~140 lines)
- `CRM_Module` reduced from 775 to ~430 lines, focused on contact sync only
- Delegates to `Lead_Manager` and `Contact_Refiner` via dependency injection (closures)

#### Refactoring — Admin_Ajax POST Sanitization
- Added `get_post_field()` private helper to centralize `$_POST` sanitization
- Supports 5 types: `text`, `url`, `key`, `int`, `bool`
- Replaced ~15 repetitive `isset/sanitize/wp_unslash` patterns across all handlers

#### Refactoring — Contact Meta Fields
- Extracted duplicated `$meta_fields` arrays to `CRM_Module::CONTACT_META_FIELDS` private constant
- Single source of truth for billing field keys used by both `load_contact_data()` and `save_contact_data()`

### Added
- `includes/modules/class-lead-manager.php` — Lead management class
- `includes/modules/class-contact-refiner.php` — Contact refinement class

## [1.0.2] - 2026-02-09

### Added

#### Module Settings UI
- Per-module inline settings panels in the Modules tab with checkbox, select, number, and text field types
- `save_module_settings` AJAX handler with field-type-aware sanitization
- Settings panel auto-shows/hides when toggling a module on/off
- "Save settings" button per module with success/error feedback

#### Internationalization (i18n)
- All source strings now in English (proper WordPress i18n pattern)
- `languages/odoo-wp-connector.pot` — gettext template with 110+ translatable strings
- `languages/odoo-wp-connector-fr_FR.po` — complete French translation
- `languages/odoo-wp-connector-fr_FR.mo` — compiled French translation

#### Uninstall Handler
- `uninstall.php` — drops 3 custom tables, deletes all `wp4odoo_%` options, clears cron on uninstall

### Changed
- All French hardcoded strings across admin views, AJAX handlers, and JS converted to English `__()` calls
- Tab labels in Settings_Page changed from French to English
- `Admin_Ajax` now has 9 handlers (added `save_module_settings`)
- `Module_Base::get_settings_fields()` — new method for subclass settings field definitions
- `CRM_Module::get_settings_fields()` — 6 configurable fields (sync_users, archive, role, pull creation, default role, lead form)
- Admin CSS expanded with module settings panel styles
- Admin JS expanded with settings panel toggle and save handler

## [1.0.1] - 2026-02-09

### Added

#### CRM Module — Full Implementation
- Bidirectional contact sync: WP users ↔ Odoo `res.partner` with email deduplication on both sides
- Lead form: `[wp4odoo_lead_form]` shortcode with AJAX submission, `wp4odoo_lead` CPT for local storage
- Archive-on-delete: sends `active=false` to Odoo instead of unlinking (configurable)
- Role-based filtering: `sync_role` setting to restrict which WP roles are synced
- Country/state resolution: ISO codes ↔ Odoo `res.country` / `res.country.state` IDs
- User creation on pull with configurable default role and username generation
- 15 field mappings for contacts (name, email, phone, company, address, website)
- 6 field mappings for leads (name, email, phone, company, description, source)
- `wp4odoo_lead_created` action hook fired after lead form submission
- Frontend assets: `assets/js/lead-form.js` (AJAX handler), `assets/css/frontend.css` (form styling)

#### Module_Base Helpers
- `is_importing()` — anti-loop guard for WP hook callbacks during pull operations
- `resolve_many2one_field()` — resolves Odoo Many2one `[id, "Name"]` values to scalar fields via API read

### Changed
- `Module_Base` now imports `Field_Mapper` for use in `resolve_many2one_field()`
- CRM module upgraded from stub (~60 lines) to full implementation (~530 lines, 19 methods)

## [1.0.0] - 2026-02-09

### Added

#### Core API
- `Transport` interface (`includes/api/interface-transport.php`) defining the contract for RPC transports
- `Odoo_JsonRPC` — JSON-RPC 2.0 transport for Odoo 17+ implementing `Transport`
- `Odoo_XmlRPC` — XML-RPC legacy transport implementing `Transport`
- `Odoo_Client` — High-level client with lazy connection: `search()`, `search_read()`, `read()`, `create()`, `write()`, `unlink()`, `search_count()`, `fields_get()`, `execute()`
- `Odoo_Auth` — Credential encryption (libsodium + OpenSSL fallback), connection testing

#### Core Infrastructure
- `Sync_Engine` — Queue processor with transient locking, exponential backoff, deduplication, batch processing
- `Queue_Manager` — Convenience methods for enqueuing and cancelling sync jobs
- `Query_Service` — Static data access layer for paginated queue jobs and log entries
- `Module_Base` — Abstract base class for modules with push/pull, mapping, hashing
- `Field_Mapper` — Static type conversion utilities (Many2one, dates, HTML, booleans)
- `Webhook_Handler` — REST API endpoints for Odoo webhook notifications
- `Logger` — Database-backed structured logger with level filtering and retention cleanup
- 3 database tables: `sync_queue`, `entity_map`, `logs` (created via `dbDelta()`)
- Cron schedules: every 5 minutes (default), every 15 minutes

#### Module Stubs
- `CRM_Module` — Structure and hooks for `res.partner` / `crm.lead` sync
- `Sales_Module` — Structure and hooks for products, orders, invoices sync
- `WooCommerce_Module` — Structure and hooks for WooCommerce bidirectional sync

#### Admin UI
- Top-level "Odoo Connector" menu in WordPress admin with `dashicons-randomize`
- 5-tab settings page: Connexion, Synchronisation, Modules, File d'attente, Journaux
- **Connexion tab**: Odoo credentials form (URL, database, username, API key, protocol, timeout), "Test connection" button, webhook token display with copy
- **Synchronisation tab**: Direction, conflict rule, batch size, interval, auto-sync toggle; log settings (enable, level, retention)
- **Modules tab**: Card grid with AJAX-powered toggle switches, WooCommerce dependency check
- **File d'attente tab**: 4 status cards (pending/processing/completed/failed), jobs table with server-side pagination, retry/cleanup/cancel actions
- **Journaux tab**: Filter bar (level, module, dates), AJAX-powered log table with pagination, purge with confirmation
- 8 AJAX handlers with nonce verification and `manage_options` capability check
- Plugin action link "Reglages" on the Plugins page
- Custom CSS (~170 lines) and JavaScript (~280 lines)
