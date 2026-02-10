# Architecture — WordPress For Odoo

## Overview

Modular WordPress plugin providing bidirectional synchronization between WordPress/WooCommerce and Odoo ERP (v14+). The plugin covers three domains: CRM, Sales & Invoicing, and WooCommerce.

```
┌─────────────────────────────────────────────────────────────────┐
│                        WordPress                                │
│                                                                 │
│  ┌──────────┐  ┌──────────┐  ┌───────────────┐                │
│  │   CRM    │  │  Sales   │  │  WooCommerce  │   Modules      │
│  │  Module  │  │  Module  │  │    Module      │                │
│  └────┬─────┘  └────┬─────┘  └──────┬────────┘                │
│       │              │               │                          │
│       └──────────────┼───────────────┘                          │
│                      ▼                                          │
│              ┌──────────────┐     ┌────────────────┐           │
│              │  Sync Engine │◄────│ Queue Manager  │           │
│              │  (cron job)  │     └────────────────┘           │
│              └──────┬───────┘                                   │
│                     │                                           │
│              ┌──────▼───────┐     ┌────────────────┐           │
│              │ Field Mapper │     │ Webhook Handler│◄── REST   │
│              └──────┬───────┘     └────────┬───────┘    API    │
│                     │                      │                    │
│              ┌──────▼──────────────────────▼────┐              │
│              │          Odoo Client             │              │
│              │  ┌──────────┐  ┌──────────────┐  │              │
│              │  │ JSON-RPC │  │   XML-RPC    │  │              │
│              │  │Transport │  │  Transport   │  │              │
│              │  └────┬─────┘  └──────┬───────┘  │              │
│              │       └──── Transport ────┘       │              │
│              │            interface              │              │
│              └──────────────┬───────────────────┘              │
│                             │                                   │
└─────────────────────────────┼───────────────────────────────────┘
                              │ HTTP
                              ▼
                    ┌──────────────────┐
                    │    Odoo ERP      │
                    │     (v14+)       │
                    └──────────────────┘
```

## Directory Structure

```
WordPress For Odoo/
├── wp4odoo.php              # Entry point, singleton, autoloader, hooks, WP-CLI registration (~280 lines)
├── CLAUDE.md                          # Instructions for Claude Code
├── ARCHITECTURE.md                    # This file
├── CHANGELOG.md                       # Version history
│
├── includes/
│   ├── api/
│   │   ├── interface-transport.php    # Transport interface (authenticate, execute_kw, get_uid)
│   │   ├── trait-retryable-http.php   # Retryable_Http trait (retry + exponential backoff + jitter)
│   │   ├── class-odoo-client.php      # High-level client (CRUD, search, fields_get)
│   │   ├── class-odoo-jsonrpc.php     # JSON-RPC 2.0 transport (Odoo 17+) uses Retryable_Http
│   │   ├── class-odoo-xmlrpc.php      # XML-RPC transport (legacy) uses Retryable_Http
│   │   └── class-odoo-auth.php        # Auth, API key encryption, connection testing
│   │
│   ├── modules/
│   │   ├── class-crm-module.php       # CRM: contact sync orchestration (delegates to Contact_Manager)
│   │   ├── class-contact-manager.php  # CRM: contact data load/save/sync-check
│   │   ├── class-lead-manager.php     # CRM: lead CPT, shortcode, form, data load/save
│   │   ├── class-contact-refiner.php  # CRM: name/country/state refinement filters
│   │   ├── class-sales-module.php     # Sales: orders, invoices (delegates CPT ops to CPT_Helper)
│   │   ├── class-portal-manager.php   # Sales: customer portal shortcode, AJAX, queries
│   │   ├── class-currency-guard.php      # WooCommerce: static currency mismatch detection utility
│   │   ├── class-variant-handler.php    # WooCommerce: variant import (product.product → WC variations)
│   │   ├── class-image-handler.php      # WooCommerce: product image import (Odoo image_1920 → WC thumbnail)
│   │   ├── class-product-handler.php    # WooCommerce: product CRUD with currency guard
│   │   ├── class-order-handler.php      # WooCommerce: order CRUD + Odoo status mapping
│   │   └── class-woocommerce-module.php  # WooCommerce: sync coordinator (delegates to handlers)
│   │
│   ├── admin/
│   │   ├── class-admin.php            # Admin menu, assets, activation redirect, setup notice
│   │   ├── class-bulk-handler.php     # Bulk product import/export operations
│   │   ├── class-admin-ajax.php       # 15 AJAX handlers (test, retry, cleanup, logs, module settings, bulk, onboarding, checklist)
│   │   └── class-settings-page.php    # Settings API, 5-tab rendering, setup checklist, sanitize callbacks
│   │
│   ├── class-dependency-loader.php    # Loads all plugin class files (require_once)
│   ├── class-database-migration.php   # Table creation (dbDelta) and default options
│   ├── class-module-registry.php      # Module registration, mutual exclusivity, lifecycle
│   ├── class-module-base.php          # Abstract base class for modules
│   ├── class-entity-map-repository.php # Static DB access for wp4odoo_entity_map
│   ├── class-sync-queue-repository.php # Static DB access for wp4odoo_sync_queue
│   ├── class-partner-service.php       # Shared res.partner lookup/creation service
│   ├── class-sync-engine.php          # Queue processor, batch operations, advisory locking
│   ├── class-queue-manager.php        # Helpers for enqueuing sync jobs
│   ├── class-query-service.php        # Paginated queries (queue jobs, log entries)
│   ├── class-field-mapper.php         # Type conversions (Many2one, dates, HTML)
│   ├── class-cpt-helper.php           # Shared CPT register/load/save helpers
│   ├── class-webhook-handler.php      # REST API endpoints for Odoo webhooks, rate limiting
│   ├── class-cli.php                 # WP-CLI commands (loaded only in CLI context)
│   └── class-logger.php              # DB-backed logger with level filtering
│
├── admin/
│   ├── css/admin.css                  # Admin styles (~400 lines)
│   ├── js/admin.js                    # Admin JS: AJAX interactions (~570 lines)
│   └── views/                         # Admin page templates
│       ├── page-settings.php          #   Main wrapper (h1 + checklist + nav-tabs + tab dispatch)
│       ├── partial-checklist.php      #   Setup checklist (progress bar + 3 steps)
│       ├── tab-connection.php         #   Odoo connection form + inline help + webhook token
│       ├── tab-sync.php               #   Sync settings + logging settings
│       ├── tab-modules.php            #   Module cards with AJAX toggles + inline settings panels
│       ├── tab-queue.php              #   Stats cards + paginated jobs table
│       └── tab-logs.php               #   Filter bar + AJAX paginated log table
│
├── assets/                            # Frontend assets
│   ├── css/frontend.css              #   Lead form styling
│   ├── css/portal.css                #   Customer portal styling
│   ├── js/lead-form.js              #   Lead form AJAX submission
│   └── js/portal.js                 #   Portal tab switching + AJAX pagination
│
├── templates/
│   └── customer-portal.php           #   Customer portal HTML template (orders/invoices tabs)
│
├── tests/                             # PHPUnit tests (356 tests, 704 assertions, 41 files analysed)
│   ├── bootstrap.php                 #   Constants, stub loading, plugin class requires
│   ├── stubs/
│   │   ├── wp-classes.php            #   WP_Error, WP_REST_*, WP_User, WP_CLI, AJAX test helpers
│   │   ├── wp-functions.php          #   WordPress function stubs (~60 functions)
│   │   ├── wc-classes.php            #   WC_Product, WC_Order, WC_Product_Variable/Variation/Attribute
│   │   ├── wp-db-stub.php            #   WP_DB_Stub ($wpdb mock with call recording)
│   │   ├── plugin-stub.php           #   WP4Odoo_Plugin test singleton
│   │   └── wp-cli-utils.php          #   WP_CLI\Utils\format_items stub
│   └── Unit/
│       ├── EntityMapRepositoryTest.php  #   10 tests for Entity_Map_Repository
│       ├── FieldMapperTest.php          #   30 tests for Field_Mapper
│       ├── ModuleBaseHashTest.php       #   4 tests for generate_sync_hash()
│       ├── PartnerServiceTest.php       #   10 tests for Partner_Service
│       ├── QueueManagerTest.php         #   7 tests for Queue_Manager
│       ├── SyncQueueRepositoryTest.php  #   18 tests for Sync_Queue_Repository
│       ├── WooCommerceModuleTest.php    #   22 tests for WooCommerce_Module
│       ├── VariantHandlerTest.php       #   7 tests for Variant_Handler
│       ├── BulkSyncTest.php             #   12 tests for bulk import/export
│       ├── ImageHandlerTest.php         #   9 tests for Image_Handler
│       ├── CurrencyTest.php             #   9 tests for multi-currency support
│       ├── LoggerTest.php               #   9 tests for Logger
│       ├── SyncEngineTest.php           #   8 tests for Sync_Engine
│       ├── OdooAuthTest.php             #   8 tests for Odoo_Auth
│       ├── OdooClientTest.php           #   9 tests for Odoo_Client
│       ├── QueryServiceTest.php         #   6 tests for Query_Service
│       ├── ContactRefinerTest.php       #   12 tests for Contact_Refiner
│       ├── SettingsPageTest.php         #   8 tests for Settings_Page
│       ├── CLITest.php                  #   16 tests for CLI
│       ├── OrderHandlerTest.php         #   9 tests for Order_Handler
│       ├── ProductHandlerTest.php       #   7 tests for Product_Handler
│       ├── ContactManagerTest.php       #   12 tests for Contact_Manager
│       └── LeadManagerTest.php          #   10 tests for Lead_Manager
│
├── uninstall.php                      # Cleanup on plugin uninstall
│
└── languages/                         # i18n files
    ├── wp4odoo.pot          #   Gettext template (source strings)
    ├── wp4odoo-fr_FR.po     #   French translations
    └── wp4odoo-fr_FR.mo     #   Compiled French translations
```

## Architectural Patterns

### 1. Singleton — Single Entry Point

```php
// wp4odoo.php
final class WP4Odoo_Plugin {
    private static ?self $instance = null;

    public static function instance(): self { /* ... */ }
    private function __construct() { /* ... */ }
}

// Global accessor
function wp4odoo(): WP4Odoo_Plugin {
    return WP4Odoo_Plugin::instance();
}
```

The singleton delegates to `Dependency_Loader` (class loading), `Database_Migration` (table DDL + defaults), and `Module_Registry` (module registration/lifecycle). It keeps hooks, cron, REST, and API client access.

### 2. Module System

Each Odoo domain is encapsulated in an independent module extending `Module_Base`:

```
Module_Base (abstract)
├── CRM_Module          → res.partner, crm.lead         [COMPLETE]
├── Sales_Module        → product.template, sale.order, account.move  [COMPLETE]
├── WooCommerce_Module  → + stock.quant, woocommerce hooks  [COMPLETE]
└── [Custom_Module]     → extensible via action hook
```

**Module_Base provides:**
- Push/Pull orchestration: `push_to_odoo()`, `pull_from_odoo()`
- Entity mapping CRUD: `get_mapping()`, `save_mapping()`, `get_wp_mapping()`, `remove_mapping()` (delegates to `Entity_Map_Repository`)
- Data transformation: `map_to_odoo()`, `map_from_odoo()`, `generate_sync_hash()`
- Settings: `get_settings()`, `get_settings_fields()`, `get_default_settings()`
- Helpers: `is_importing()` (anti-loop guard), `resolve_many2one_field()` (Many2one → scalar), `client()`
- Subclass hooks: `boot()`, `load_wp_data()`, `save_wp_data()`, `delete_wp_data()`

**Module lifecycle:**
1. `Module_Registry::register_all()` is called on the `init` hook
2. Modules instantiated and registered via `register($id, $module)`
3. If `wp4odoo_module_{id}_enabled` is true → `$module->boot()` is called
4. `boot()` registers module-specific WordPress hooks

**Third-party extension:**
```php
add_action('wp4odoo_register_modules', function($plugin) {
    $plugin->register_module('my_module', new My_Module());
});
```

### 3. Queue-Based Synchronization

No Odoo API requests are made during user requests. Everything goes through a persistent database queue:

```
WP Event               Sync Engine (cron)           Odoo
    │                       │                        │
    │  enqueue()            │                        │
    ├──────────────►  sync_queue table               │
    │                       │                        │
    │                  process_queue()                │
    │                       ├───── execute_kw() ────►│
    │                       │◄──── response ─────────│
    │                       │                        │
    │                  update status                  │
    │                  (completed/failed)             │
```

**Table `wp4odoo_sync_queue`:**

| Column | Purpose |
|--------|---------|
| `module` | Source module (crm, sales, woocommerce) |
| `direction` | `wp_to_odoo` or `odoo_to_wp` |
| `entity_type` | Entity type (contact, lead, product, order...) |
| `wp_id` / `odoo_id` | Identifiers on both sides |
| `action` | `create`, `update`, `delete` |
| `payload` | JSON-serialized data |
| `priority` | 1-10, lower values processed first |
| `status` | `pending` → `processing` → `completed` / `failed` |
| `attempts` | Retry counter (max 3 by default) |
| `max_attempts` | Maximum retry count (default 3) |
| `error_message` | Last error message on failure |
| `scheduled_at` | Scheduling (exponential backoff: `attempts × 60s`) |
| `processed_at` | Timestamp when the job was last processed |
| `created_at` | Timestamp when the job was enqueued |

**Reliability mechanisms:**
- MySQL advisory locking via `GET_LOCK()` / `RELEASE_LOCK()` (prevents parallel execution)
- Exponential backoff on failure
- Deduplication: updates an existing `pending` job rather than creating a duplicate
- Configurable batch size (50 items per cron tick by default)

### 4. Interchangeable Transport (Strategy Pattern)

Both transports implement the `Transport` interface and share HTTP retry logic via the `Retryable_Http` trait:

```php
interface Transport {
    public function authenticate( string $username ): int;
    public function execute_kw( string $model, string $method, array $args, array $kwargs ): mixed;
    public function get_uid(): ?int;
}

trait Retryable_Http {
    private function http_post_with_retry( string $url, array $request_args, string $endpoint ): array;
}
```

```
Odoo_Client ── uses ── Transport (interface)
                            │
    ┌───────────────────────┼──────────────────────┐
    │                       │                      │
Odoo_JsonRPC            Odoo_XmlRPC
POST /jsonrpc           POST /xmlrpc/2/common (auth)
                        POST /xmlrpc/2/object (CRUD)
    └──── both use Retryable_Http trait ──────────┘
```

`Retryable_Http` provides `http_post_with_retry()` — 3 attempts with exponential backoff (2^attempt × 500ms) + random jitter (0-1000ms). Extracted from duplicated retry loops in both transports.

The protocol is configurable in options (`wp4odoo_connection.protocol`). JSON-RPC is the default for Odoo 17+.

### 5. Entity Mapping

The `wp4odoo_entity_map` table maintains the correspondence between WordPress and Odoo IDs:

```
┌─────────────────────────────────────────────────────┐
│              wp4odoo_entity_map                     │
├──────────┬─────────────┬───────┬─────────┬──────────┤
│ module   │ entity_type │ wp_id │ odoo_id │ odoo_model     │ sync_hash│ last_synced_at      │
├──────────┼─────────────┼───────┼─────────┼────────────────┼──────────┼─────────────────────┤
│ crm      │ contact     │ 42    │ 1337    │ res.partner    │ a3f8...  │ 2026-02-10 12:00:00 │
│ woo      │ product     │ 15    │ 201     │ product.tmpl   │ b7e2...  │ 2026-02-10 12:05:00 │
│ woo      │ order       │ 88    │ 5042    │ sale.order     │ c1d9...  │ 2026-02-10 12:10:00 │
└──────────┴─────────────┴───────┴─────────┴────────────────┴──────────┴─────────────────────┘
```

- Unique composite index `(module, entity_type, wp_id, odoo_id)` to prevent duplicates
- `odoo_model` stores the Odoo model name (e.g. `res.partner`) for reverse lookups
- `sync_hash` (SHA-256 of data) to detect changes without making an API call
- `last_synced_at` tracks when the mapping was last synchronized
- Fast lookup in both directions via separate indexes (`idx_wp_lookup`, `idx_odoo_lookup`)

### 6. Query Service (Data Access Layer)

The `Query_Service` class (`includes/class-query-service.php`) provides static methods for paginated data retrieval, decoupling database queries from the admin UI:

```php
Query_Service::get_queue_jobs( $page, $per_page, $status );
Query_Service::get_log_entries( $filters, $page, $per_page );
```

Used by both `Settings_Page` (server-side rendering) and `Admin_Ajax` (AJAX endpoints).

## Database

### Tables Created on Activation

Managed via `dbDelta()` in `Database_Migration::create_tables()`:

**`{prefix}wp4odoo_sync_queue`** — Sync queue
- Primary index: `(status, priority, scheduled_at)` for efficient polling

**`{prefix}wp4odoo_entity_map`** — WP ↔ Odoo mapping
- Unique: `(module, entity_type, wp_id, odoo_id)`
- WP lookup: `(entity_type, wp_id)`
- Odoo lookup: `(odoo_model, odoo_id)`

**`{prefix}wp4odoo_logs`** — Structured logs
- Indexes: `(level, created_at)` and `(module)`
- `context` field stores JSON contextual data

### WordPress Options (`wp_options`)

| Key | Type | Description |
|-----|------|-------------|
| `wp4odoo_connection` | `array` | URL, database, username, encrypted API key, protocol, timeout |
| `wp4odoo_sync_settings` | `array` | Direction, conflict rule, batch size, interval |
| `wp4odoo_log_settings` | `array` | Enabled, level, retention days |
| `wp4odoo_module_{id}_enabled` | `bool` | Per-module activation |
| `wp4odoo_module_{id}_settings` | `array` | Per-module configuration |
| `wp4odoo_module_{id}_mappings` | `array` | Custom field mappings |
| `wp4odoo_webhook_token` | `string` | Auto-generated webhook auth token |
| `wp4odoo_db_version` | `string` | DB schema version |
| `wp4odoo_onboarding_dismissed` | `bool` | Setup notice dismissed |
| `wp4odoo_checklist_dismissed` | `bool` | Setup checklist dismissed |
| `wp4odoo_checklist_webhooks_confirmed` | `bool` | Webhooks step marked done |

## REST API

Namespace: `wp-json/wp4odoo/v1/`

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/webhook` | `POST` | Token | Receives change notifications from Odoo |
| `/webhook/test` | `GET` | Public | Health check |
| `/sync/{module}/{entity}` | `POST` | WP Auth | Triggers sync for a specific module/entity |

The webhook is authenticated via an `X-Odoo-Token` header compared against `wp4odoo_webhook_token`. Rate limiting: 100 requests per IP per 60-second window (returns HTTP 429 when exceeded).

## Sync Flows

### Push (WP → Odoo)

```
1. WP hook triggered (e.g., woocommerce_update_product)
2. Module::push_to_odoo() called
3. Data transformed via map_to_odoo() + Field_Mapper
4. Job added to queue via Sync_Engine::enqueue()
5. Cron runs process_queue()
6. Odoo_Client::create() or write() via JSON-RPC/XML-RPC
7. Mapping saved in entity_map
8. Status updated (completed/failed)
```

### Pull (Odoo → WP)

```
1. Odoo sends a webhook POST /wp4odoo/v1/webhook
2. Webhook_Handler validates the token
3. Job added to queue (direction: odoo_to_wp)
4. Cron runs process_queue()
5. Module::pull_from_odoo() called
6. Data fetched via Odoo_Client::read()
7. Transformed via map_from_odoo() + Field_Mapper
8. WP4ODOO_IMPORTING constant defined (anti-loop)
9. WP entity created/updated
10. Mapping and hash saved
```

## Security

### API Key Encryption

```
Storage:  API key → sodium_crypto_secretbox() → wp_options (encrypted)
Reading:  wp_options → sodium_crypto_secretbox_open() → API key (plaintext)
```

- Derivation key based on WordPress salts (`AUTH_KEY`, `SECURE_AUTH_KEY`)
- Optional dedicated key via `WP4ODOO_ENCRYPTION_KEY` in `wp-config.php`
- OpenSSL fallback if libsodium is unavailable

### Request Authentication

- **Admin AJAX**: `manage_options` capability + nonce verification
- **Incoming webhooks**: token in `X-Odoo-Token` header + per-IP rate limiting (100 req/min)
- **REST API sync**: standard WordPress authentication (cookie/nonce or Application Passwords)
- **WooCommerce HPOS**: compatibility declared via `before_woocommerce_init` hook

### Input Sanitization

All user inputs are sanitized with:
- `sanitize_text_field()` for strings
- `esc_url_raw()` for URLs
- `absint()` for integers
- `wp_kses_post()` for rich HTML

## Admin UI

5-tab settings interface accessible via top-level "Odoo Connector" menu:

| Tab | Template | Features |
|-----|----------|----------|
| Connection | `tab-connection.php` | Credentials form, "Test connection" AJAX, webhook token display + copy |
| Sync | `tab-sync.php` | Direction, conflict rule, batch size, interval, auto-sync; logging settings |
| Modules | `tab-modules.php` | Card grid with AJAX toggle switches, inline settings panels, WooCommerce dependency check |
| Queue | `tab-queue.php` | 4 status cards, jobs table with server-side pagination, retry/cleanup/cancel |
| Logs | `tab-logs.php` | Filter bar (level, module, dates), AJAX paginated log table, purge |

**Key classes:**
- `Admin` — orchestrator: menu registration, asset enqueuing, plugin settings link, activation redirect, setup notice
- `Settings_Page` — Settings API registration, tab rendering, setup checklist, sanitize callbacks
- `Admin_Ajax` — 15 handlers: test_connection, retry_failed, cleanup_queue, cancel_job, purge_logs, fetch_logs, queue_stats, toggle_module, save_module_settings, bulk_import_products, bulk_export_products, fetch_queue, dismiss_onboarding, dismiss_checklist, confirm_webhooks

## Modules Detail

### CRM — COMPLETE

**Files:** `class-crm-module.php` (contact sync orchestration), `class-contact-manager.php` (contact data load/save/sync-check), `class-lead-manager.php` (leads), `class-contact-refiner.php` (field refinement)

**Odoo models:** `res.partner`, `crm.lead`

| Direction | Source | Destination | Matching |
|-----------|--------|-------------|----------|
| WP → Odoo | `user_register` / `profile_update` | `res.partner` create/update | Email dedup |
| WP → Odoo | `delete_user` | `res.partner` archive (`active=false`) or unlink | Mapping |
| WP → Odoo | `[wp4odoo_lead_form]` submission | `crm.lead` create | — |
| Odoo → WP | Webhook `res.partner` | WP user create/update (subscriber) | Email dedup |
| Odoo → WP | Webhook `crm.lead` | `wp4odoo_lead` CPT | Mapping |

**Key features:**
- Email deduplication in both directions (push: searches Odoo by email before creating; pull: `get_user_by('email')`)
- Archive-on-delete: sends `active=false` instead of unlink when setting enabled
- Role-based filtering: configurable `sync_role` (empty = all roles)
- Anti-loop via `$this->is_importing()` in every WP hook callback
- Country/state resolution: ISO code ↔ Odoo `res.country` / `res.country.state` IDs
- `wp4odoo_lead` CPT for storing leads locally, nested under plugin admin menu
- `[wp4odoo_lead_form]` shortcode with AJAX submission (`assets/js/lead-form.js`)

**Field mappings:**

Contact (WP user ↔ `res.partner`):
```
display_name → name, user_email → email, description → comment,
first_name → x_wp_first_name, last_name → x_wp_last_name (composed into name by Contact_Refiner),
billing_phone → phone,
billing_company → company_name, billing_address_1 → street,
billing_address_2 → street2, billing_city → city, billing_postcode → zip,
billing_country → country_id (ISO→ID), billing_state → state_id (name→ID),
user_url → website
```

Lead (form → `crm.lead`):
```
name → name, email → email_from, phone → phone,
company → partner_name, description → description, source → x_wp_source
```

**Settings:** `sync_users_as_contacts`, `archive_on_delete`, `sync_role`, `create_users_on_pull`, `default_user_role`, `lead_form_enabled`

### Sales — COMPLETE

**Files:** `class-sales-module.php` (order/invoice sync, CPTs), `class-portal-manager.php` (portal shortcode, AJAX, queries)

**Odoo models:** `product.template`, `sale.order`, `account.move`

| Direction | Source | Destination | Matching |
|-----------|--------|-------------|----------|
| Odoo → WP | Webhook `sale.order` | `wp4odoo_order` CPT | Mapping |
| Odoo → WP | Webhook `account.move` | `wp4odoo_invoice` CPT | Mapping |

**Customer portal:** `[wp4odoo_customer_portal]` shortcode renders a tabbed interface (Orders / Invoices) with pagination and currency display. Links WP users to Odoo partners via CRM entity_map, then queries `wp4odoo_order` / `wp4odoo_invoice` CPTs by `_wp4odoo_partner_id` meta.

**Field mappings:**

Order (Odoo `sale.order` → `wp4odoo_order` CPT):
```
name → post_title, amount_total → _order_total, date_order → _order_date,
state → _order_state, partner_id → _wp4odoo_partner_id (Many2one → ID),
currency_id → _order_currency (Many2one → code string)
```

Invoice (Odoo `account.move` → `wp4odoo_invoice` CPT):
```
name → post_title, amount_total → _invoice_total, invoice_date → _invoice_date,
state → _invoice_state, payment_state → _payment_state, partner_id → _wp4odoo_partner_id,
currency_id → _invoice_currency (Many2one → code string)
```

**Settings:** `import_products`, `portal_enabled`, `orders_per_page`

### WooCommerce — COMPLETE

**Files:** `class-woocommerce-module.php` (sync coordinator), `class-product-handler.php` (product CRUD), `class-order-handler.php` (order CRUD + status mapping), `class-variant-handler.php` (variant import), `class-image-handler.php` (product image pull), `class-currency-guard.php` (currency mismatch detection)

**Odoo models:** `product.template`, `product.product`, `sale.order`, `stock.quant`, `account.move`

| Direction | Source | Destination | Matching |
|-----------|--------|-------------|----------|
| WP → Odoo | `woocommerce_update_product` | `product.template` create/update | SKU |
| WP → Odoo | `woocommerce_new_order` | `sale.order` create | — |
| WP → Odoo | `woocommerce_order_status_changed` | `sale.order` update | Mapping |
| Odoo → WP | Webhook `product.template` | WC product update | SKU |
| Odoo → WP | Webhook `sale.order` | WC order status update | Mapping |
| Odoo → WP | Webhook `stock.quant` | `wc_update_product_stock()` | SKU |
| Odoo → WP | Webhook `account.move` | `wp4odoo_invoice` CPT | Mapping |
| Odoo → WP | Product pull `product.product` | WC variations (auto-enqueued after template pull) | Template mapping |
| WP → Odoo | Bulk export (all products) | `product.template` create/update (via queue) | Entity map |
| Odoo → WP | Bulk import (all products) | WC product create/update + variants (via queue) | Entity map |

**Key features:**
- Mutually exclusive with Sales_Module (same Odoo models)
- Uses `Partner_Service` for customer resolution (WP user → Odoo partner)
- WC-native APIs: `wc_get_product()`, `wc_get_order()`, `wc_update_product_stock()`
- HPOS compatible (High-Performance Order Storage)
- `wp4odoo_invoice` CPT for invoices (WC has no native invoice type)
- **Product variants**: auto-imports `product.product` variants as WC variations after a `product.template` pull; skips single-variant (simple) products; resolves attributes via `product.template.attribute.value`
- **Bulk operations**: queue-based import/export of all products via admin UI (Sync tab), no synchronous API calls during requests
- **Multi-currency guard**: skips price update when Odoo `currency_id` differs from WC shop currency (`get_woocommerce_currency()`); stores Odoo currency code in product meta; same guard applies to variants via `Variant_Handler`
- `stock.quant` resolution: checks both `product` and `variant` entity mappings (since stock.quant references `product.product`)

**Order status mapping (Odoo → WC):**

```php
$map = [
    'draft'   => 'pending',
    'sent'    => 'on-hold',
    'sale'    => 'processing',
    'done'    => 'completed',
    'cancel'  => 'cancelled',
];

```

**Anti-loop protection:** The `WP4ODOO_IMPORTING` constant is defined during pull operations to prevent WooCommerce hooks from re-enqueuing a sync.

**Settings:** `sync_products`, `sync_orders`, `sync_stock`, `sync_product_images`, `auto_confirm_orders`

### Partner Service

**File:** `class-partner-service.php`

Shared service for managing WP user ↔ Odoo `res.partner` relationships. Used by `Portal_Manager` and `WooCommerce_Module`.

**Resolution flow (3-step):**
1. Check `wp4odoo_entity_map` for existing mapping
2. Search Odoo by email (`res.partner` domain)
3. Create new partner if not found

**Key methods:**
- `get_partner_id_for_user(int $user_id)` — Get Odoo partner ID for a WP user
- `get_or_create(string $email, array $data, ?int $user_id)` — Get existing or create new partner
- `get_user_for_partner(int $partner_id)` — Reverse lookup: Odoo partner → WP user

### WP-CLI

**File:** `class-cli.php` (loaded only when `WP_CLI` is defined)

| Command | Action |
|---------|--------|
| `wp wp4odoo status` | Connection info, queue stats, module list |
| `wp wp4odoo test` | Test Odoo connection |
| `wp wp4odoo sync run` | Process sync queue |
| `wp wp4odoo queue stats` | Queue statistics (supports `--format`) |
| `wp wp4odoo queue list` | Paginated job list (`--page`, `--per-page`) |
| `wp wp4odoo queue retry` | Retry all failed jobs |
| `wp wp4odoo queue cleanup` | Delete old jobs (`--days`) |
| `wp wp4odoo queue cancel <id>` | Cancel a pending job |
| `wp wp4odoo module list` | List modules with status |
| `wp wp4odoo module enable <id>` | Enable a module |
| `wp wp4odoo module disable <id>` | Disable a module |

Pure delegation to existing services — no business logic in CLI class.

### Onboarding

**Post-activation redirect:** Transient consumed on `admin_init` → redirect to settings page (skips bulk activation, AJAX, WP-CLI).

**Setup notice:** Dismissible admin notice on all pages until connection is configured or dismissed. Inline `<script>` for AJAX dismiss.

**Setup checklist:** 3-step progress widget on the settings page:
1. Connect Odoo (auto-detected: URL + API key)
2. Enable a module (auto-detected: any `wp4odoo_module_*_enabled`)
3. Configure webhooks (manual: "Mark as done" button)

Auto-dismissed when all steps completed. Dismiss via × button persisted in `wp4odoo_checklist_dismissed`.

**Inline documentation:** 3 collapsible `<details>` blocks in the Connection tab: Getting Started (prerequisites), API key generation (Odoo 14-16 and 17+), webhook configuration (native and Automated Actions).

## Hooks & Filters

### Actions

| Hook | Trigger | Parameters |
|------|---------|------------|
| `wp4odoo_init` | Plugin initialized | `$plugin` |
| `wp4odoo_loaded` | All plugins loaded | — |
| `wp4odoo_register_modules` | Module registration | `$plugin` |
| `wp4odoo_lead_created` | Lead form submitted and saved | `$wp_id`, `$lead_data` |
| `wp4odoo_api_call` | Every Odoo API call | `$model`, `$method`, `$args`, `$kwargs`, `$result` |

### Filters

| Filter | Usage |
|--------|-------|
| `wp4odoo_map_to_odoo_{module}_{entity}` | Modify mapped Odoo values before push |
| `wp4odoo_map_from_odoo_{module}_{entity}` | Modify mapped WP data during pull |
| `wp4odoo_ssl_verify` | Enable/disable SSL verification |

## Cron

| Schedule | Interval | Usage |
|----------|----------|-------|
| `wp4odoo_five_minutes` | 300s | Default for sync |
| `wp4odoo_fifteen_minutes` | 900s | Configurable alternative |

Cron event: `wp4odoo_scheduled_sync` → `Sync_Engine::process_queue()`

## Odoo API Reference

### Common Signature

```
execute_kw(db, uid, password, model, method, args, kwargs)
```

### Relational Field Types

| Type | Read | Write |
|------|------|-------|
| Many2one | `[id, "Name"]` or `false` | `id` (int) |
| One2many | `[id, id, ...]` | `[[0,0,{vals}]]` create, `[[1,id,{vals}]]` update, `[[2,id,0]]` delete |
| Many2many | `[id, id, ...]` | `[[6,0,[ids]]]` replace, `[[4,id,0]]` add |

### Search Domains (Polish Notation)

```python
# Simple
[['email', '!=', False]]

# Implicit AND
[['state', '=', 'sale'], ['partner_id', '!=', False]]

# Explicit OR
['|', ['state', '=', 'sale'], ['state', '=', 'done']]
```
