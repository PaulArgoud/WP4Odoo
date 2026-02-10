# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

WordPress plugin providing bidirectional sync between WordPress/WooCommerce and Odoo ERP (v17+). **Version 1.3.1** — core infrastructure, admin UI, API layer, CRM module, Sales module with customer portal, WooCommerce module (HPOS compatible), PHPUnit tests, CI/CD, and full i18n (FR) are complete.

For detailed architecture, diagrams, and data flows, see [ARCHITECTURE.md](ARCHITECTURE.md).

## Development Environment

- **PHP 8.2+** with strict typing (`declare(strict_types=1)` in every file)
- **WordPress 6.0+** required
- **WooCommerce** optional (for WooCommerce module)
- **PHPUnit 11+** via Composer: `php composer.phar install`, run with `php vendor/bin/phpunit`
- `php` is aliased to MAMP (`/Applications/MAMP/bin/php/php8.3.28/bin/php`) — use full path with `find -exec`
- `composer` not installed globally — use `php composer.phar` in project root
- WordPress Coding Standards: WordPress spacing, naming, and documentation conventions

### Testing

**PHPUnit** — pure PHP unit tests (no WP test framework):
- `tests/bootstrap.php` stubs ABSPATH, WP functions (`sanitize_text_field`, `absint`, `current_time`, etc.), `WP_DB_Stub` for $wpdb mocking, and `WP4Odoo\Logger`
- Uses multiple `namespace {}` blocks (PHP requires consistent brace style when mixing namespaces)
- Plugin classes loaded via `require_once` (WordPress filenames, not PSR-4)
- Run: `php vendor/bin/phpunit` — 95 tests, 154 assertions

**PHPStan** — static analysis at level 5:
- `phpstan.neon` config with `szepeviktor/phpstan-wordpress` stubs
- `phpstan-bootstrap.php` stubs plugin constants and `WP4Odoo_Plugin` singleton
- Run: `php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G`

**Live testing** — symlink or copy into `wp-content/plugins/`. VS Code workspace `wp4odoo.code-workspace` includes live directory at `/Volumes/Cloud/Avalia/Travail/Sites internet/monbeautableau.fr/api/wp-content/plugins`.

## Conventions

### Naming & Prefixes
- **Plugin name**: WordPress For Odoo (short: WP4Odoo)
- **Namespace**: `WP4Odoo\` — maps to `includes/`
- **Text domain**: `wp4odoo`
- **Prefixes**: constants `WP4ODOO_`, options `wp4odoo_`, hooks `wp4odoo_`, CSS classes `wp4odoo-`, JS globals `wp4odoo*`
- **File naming**: WordPress convention `class-{name}.php`, manual `require_once` in `load_dependencies()`

### i18n
- Source strings in English, French translations in `languages/`
- All user-facing strings use `__()` / `_e()` / `esc_html__()` / `esc_html_e()` with the text domain
- After adding/changing strings: `xgettext` → `.pot`, `msgmerge --update` → `.po`, `msgfmt` → `.mo`
- Tools: `/opt/homebrew/bin/xgettext`, `/opt/homebrew/bin/msgfmt`, `/opt/homebrew/bin/msgmerge`

### Security
- Sanitization on all inputs: `sanitize_text_field()`, `esc_url_raw()`, `absint()`, `sanitize_key()`
- Admin AJAX: `$this->verify_request()` (nonce + `manage_options` capability)
- POST fields: `$this->get_post_field($key, $type)` helper with 5 types: `text`, `url`, `key`, `int`, `bool`
- API keys encrypted at rest with libsodium (OpenSSL fallback)
- `index.php` "silence is golden" in every subdirectory

### Module Pattern
- Extend `Module_Base`, implement `boot()` and `get_default_settings()`
- Anti-loop: check `$this->is_importing()` in every WP hook callback
- Delegate sub-concerns to helper classes via closures: `fn() => $this->get_settings()`, `fn() => $this->client()`
- Meta field constants: use `private const` arrays for meta key definitions (single source of truth)

## Key Classes

### Entry Point

`wp4odoo.php` — singleton `WP4Odoo_Plugin`, accessed via `wp4odoo()`.

### API Layer (`includes/api/`)

| Class | Role |
|-------|------|
| `Transport` (interface) | Contract: `authenticate()`, `execute_kw()`, `get_uid()` |
| `Odoo_JsonRPC` | JSON-RPC 2.0 transport (Odoo 17+) |
| `Odoo_XmlRPC` | XML-RPC legacy transport |
| `Odoo_Client` | High-level CRUD: `search()`, `search_read()`, `read()`, `create()`, `write()`, `unlink()`, `search_count()`, `fields_get()`, `execute()`, `is_connected()` |
| `Odoo_Auth` | Credential encryption, connection testing |

### Core Infrastructure (`includes/`)

| Class | Role |
|-------|------|
| `Module_Base` | Abstract base: push/pull, entity mapping (delegates to `Entity_Map_Repository`), field mapping, settings, sync hash |
| `Entity_Map_Repository` | Static DB access for `wp4odoo_entity_map`: `get_odoo_id()`, `get_wp_id()`, `save()`, `remove()` |
| `Sync_Queue_Repository` | Static DB access for `wp4odoo_sync_queue`: `enqueue()`, `fetch_pending()`, `update_status()`, `get_stats()`, `cleanup()`, `retry_failed()`, `cancel()`, `get_pending()` |
| `Partner_Service` | Shared `res.partner` lookup/creation: `get_partner_id_for_user()`, `get_or_create()`, `get_user_for_partner()` |
| `Sync_Engine` | Queue processor: MySQL advisory locking, backoff, batch processing (delegates DB to `Sync_Queue_Repository`) |
| `Queue_Manager` | Helpers: `push()`, `pull()`, `cancel()`, `get_pending()` (delegates to `Sync_Queue_Repository`) |
| `Field_Mapper` | Static type conversions: Many2one, dates, HTML, booleans, prices, relations |
| `Query_Service` | Paginated data access: `get_queue_jobs()`, `get_log_entries()` |
| `Webhook_Handler` | REST API endpoints for Odoo webhook notifications, per-IP rate limiting |
| `Logger` | DB-backed structured logger with level filtering |

### CRM Module (`includes/modules/`)

| Class | Role |
|-------|------|
| `CRM_Module` | Contact sync (WP users ↔ `res.partner`): email dedup, archive-on-delete, role filtering |
| `Lead_Manager` | Lead CPT (`wp4odoo_lead`), `[wp4odoo_lead_form]` shortcode, AJAX form submission |
| `Contact_Refiner` | Name composition/splitting, country/state resolution filters |

### Sales Module (`includes/modules/`)

| Class | Role |
|-------|------|
| `Sales_Module` | Order/invoice CPTs (`wp4odoo_order`, `wp4odoo_invoice`), data load/save, settings. Mutually exclusive with WooCommerce_Module |
| `Portal_Manager` | `[wp4odoo_customer_portal]` shortcode, AJAX pagination, uses `Partner_Service` for user → partner lookup |

### WooCommerce Module (`includes/modules/`)

| Class | Role |
|-------|------|
| `WooCommerce_Module` | WC-native product/order sync, stock pull, invoice CPT. Mutually exclusive with Sales_Module. Uses `Partner_Service` for customer resolution |

### Admin (`includes/admin/`)

| Class | Role |
|-------|------|
| `Admin` | Menu registration, asset enqueuing, plugin action link |
| `Settings_Page` | 5-tab settings page, Settings API, sanitize callbacks |
| `Admin_Ajax` | 9 AJAX handlers with nonce + capability verification |

## Implementation Status

### Complete (v1.3.1)

- **Core API** — Transport interface, JSON-RPC/XML-RPC transports, Odoo_Client, Odoo_Auth
- **Core Infrastructure** — Sync_Engine, Queue_Manager, Field_Mapper, Query_Service, Webhook_Handler, Logger, Entity_Map_Repository, Sync_Queue_Repository, Partner_Service
- **CRM Module** — bidirectional contact sync, lead form + CPT, email dedup, archive-on-delete, role filtering, country/state resolution
- **Sales Module** — order/invoice CPTs, field mappings, Many2one resolution, customer portal with tabbed UI
- **WooCommerce Module** — WC-native product/order sync, stock pull from Odoo, invoice CPT, Odoo status mapping, mutually exclusive with Sales Module
- **Admin UI** — 5-tab settings page, 9 AJAX handlers, module settings panels, mutual exclusion warning
- **PHPUnit** — 95 tests, 154 assertions
- **PHPStan** — level 5, 0 errors on 28 files
- **i18n** — translated strings (EN source, FR translation)
- **Security** — encrypted API keys, nonce verification, webhook rate limiting, index.php in all dirs, uninstall.php
- **CI/CD** — GitHub Actions (PHP 8.2 + 8.3, PHPUnit, PHPStan)
- **WooCommerce HPOS** — compatibility declared via `before_woocommerce_init`

### TODO

- **Future** — bulk import/export, product images, WooCommerce tax/shipping mapping, product variants, multi-currency
