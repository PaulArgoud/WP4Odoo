# WordPress For Odoo (WP4Odoo)

[![CI](https://github.com/PaulArgoud/wordpress-for-odoo/actions/workflows/ci.yml/badge.svg)](https://github.com/PaulArgoud/wordpress-for-odoo/actions/workflows/ci.yml)

Modular WordPress plugin providing comprehensive, bidirectional integration between WordPress/WooCommerce and Odoo ERP (v14+). Covers CRM, Sales & Invoicing, and WooCommerce synchronization through a clean, extensible architecture. Ships in **3 languages** (English, French, Spanish) and is fully translation-ready.

**Target users:** WordPress agencies and businesses running Odoo as their ERP who need seamless data flow between their website and back-office.

![WordPress For Odoo (WP4Odoo)](assets/images/logo-v2.avif)

## Features

- **CRM Module** — Bidirectional contact sync (WP users <-> `res.partner`), lead capture form with shortcode, email deduplication, archive-on-delete, role-based filtering, country/state resolution
- **Sales Module** — Order and invoice sync from Odoo, custom post types for local storage, customer portal with tabbed UI and currency display (`[wp4odoo_customer_portal]`)
- **WooCommerce Module** — WC-native product, order, and stock sync with Odoo status mapping, HPOS compatible, product variant import from Odoo, product image pull, multi-currency with optional exchange rate conversion (prices auto-converted using Odoo rates, or skipped on mismatch), bulk product import/export (mutually exclusive with Sales module)
- **Memberships Module** — Push WooCommerce Memberships (plans + user memberships) to Odoo's native `membership` module, with status mapping and automatic plan sync
- **Forms Module** — Automatic lead creation in Odoo from Gravity Forms and WPForms submissions, with field auto-detection (name, email, phone, company, message), multilingual label matching, and filterable via `wp4odoo_form_lead_data`
- **Async Queue** — No API calls during user requests; all sync jobs go through a persistent database queue with exponential backoff, deduplication, and configurable batch size
- **Dual Transport** — JSON-RPC 2.0 (default for Odoo 17+) and XML-RPC (legacy), swappable via settings, shared retry logic via `Retryable_Http` trait (3 attempts, exponential backoff + jitter)
- **Webhooks** — REST API endpoints for real-time notifications from Odoo, with per-IP rate limiting
- **Encrypted Credentials** — API keys encrypted at rest with libsodium (OpenSSL fallback)
- **Admin Dashboard** — 5-tab settings interface: Connection, Sync, Modules, Queue, Logs
- **Onboarding** — Post-activation redirect, setup notice, 3-step checklist with progress bar, inline Odoo documentation (API keys, webhooks)
- **WP-CLI** — Full command suite: `wp wp4odoo status|test|sync|queue|module` for headless management
- **Extensible** — Register custom modules via `wp4odoo_register_modules` action hook; filter data with `wp4odoo_map_to_odoo_*` / `wp4odoo_map_from_odoo_*`
- **Multilingual (3 languages)** — Fully internationalized with WordPress standard Gettext i18n. Ships with English (source), French, and Spanish translations (276 strings). Translation-ready for additional languages via `.po`/`.mo` files
- **Code Quality** — WordPress Coding Standards (PHPCS), PHPStan level 5 static analysis, 601 unit tests + 26 integration tests, CI/CD with GitHub Actions

## Requirements

- PHP 8.2+
- Odoo 17+ (JSON-RPC) or Odoo 14+ (XML-RPC)
- WordPress 6.0+
- WooCommerce 7.1+ (optional, for WooCommerce and Memberships modules)
- WooCommerce Memberships 1.12+ (optional, for Memberships module)
- Gravity Forms 2.5+ or WPForms 1.7+ (optional, for Forms module)

## Compatibility

### By Odoo version and hosting type

| Odoo    | Odoo.      | Odoo.sh  | Odoo    | Odoo Online  |
| version | On-Premise |          | Online  | One App Free |
|:--------|:----------:|:---------:|:------:|:------------:|
| 17 – 19 | ✅ Full³   | ✅ Full³ | ✅ Full | ⚠️ Partial² |
| 14 – 16 | ✅ Full³   | ✅ Full³ | N/A¹    | N/A¹         |
| < 14    | ❌         | ❌       | N/A¹    | N/A¹         |

> ¹ Odoo Online always runs the latest stable version (currently 17+), so older versions do not apply.
>
> ² **[One App Free](https://www.odoo.com/pricing)** is Odoo's free plan (one app, unlimited users). WP4Odoo modules require multiple Odoo apps (see table below), so only a subset of features will work. Upgrade to the Standard plan for full compatibility.
>
> ³ Works with both Odoo **Community** (free) and **Enterprise** editions — all required apps are included in Community.

- **Odoo 17+** — uses JSON-RPC 2.0 (default, recommended)
- **Odoo 14 – 16** — uses XML-RPC (legacy transport, select in plugin settings)
- **Odoo < 14** — not supported (external API incompatibilities)

All hosting types expose the standard Odoo external API used by the plugin. No custom Odoo modules are required — only the standard apps listed in the [module table](#module-system) below.

## Installation

1. Download or clone this repository into `wp-content/plugins/wordpress-for-odoo/`
2. Activate the plugin from the WordPress admin
3. Go to **Odoo Connector** in the admin menu
4. Enter your Odoo credentials (URL, database, username, API key) in the **Connection** tab
5. Click **Test Connection** to verify
6. Enable the modules you need in the **Modules** tab

## Architecture

![WP4ODOO Architecture](assets/images/architecture.svg)

### Module System

Each Odoo domain is encapsulated in an independent module extending `Module_Base`. The plugin automatically detects missing Odoo apps at connection test and module activation.

| Module             | Sync | Odoo Apps                             | Free⁴ | Key Features                                                                   |
|--------------------|:----:|---------------------------------------|:-----:|--------------------------------------------------------------------------------|
| **CRM**            |  ↔   | Contacts, CRM                         |  ⚠️   | Contact sync, lead form shortcode, email dedup, archive-on-delete              |
| **Sales**          |  ←   | Contacts, Sales, Invoicing            |  ❌   | Order/invoice CPTs, customer portal shortcode, currency display                |
| **WooCommerce**    |  ↔   | Contacts, Sales, Inventory, Invoicing |  ❌   | Product/order/stock sync, variants, image pull, exchange rates, bulk ops       |
| **WC Memberships** |  →   | Contacts, Members                     |  ❌   | Plan auto-sync, status mapping, filterable via `wp4odoo_membership_status_map` |
| **Forms**          |  →   | Contacts, CRM                         |  ⚠️   | GF + WPForms lead creation, field auto-detection, multilingual label matching  |

> ↔ Bidirectional — → WP to Odoo — ← Odoo to WP
>
> ⁴ **[One App Free](https://www.odoo.com/pricing)**: with CRM as your free app, CRM and Forms modules work. Sales, WooCommerce, and Memberships require 2–4 apps.

Third-party modules can be registered:

```php
add_action( 'wp4odoo_register_modules', function( $plugin ) {
    $plugin->register_module( 'my_module', new My_Custom_Module() );
});
```

### Sync Flow

All synchronization goes through a persistent database queue — no Odoo API calls are made during user requests:

1. A WordPress or Odoo event triggers a sync job
2. The job is enqueued in `wp4odoo_sync_queue`
3. A WP-Cron task processes the queue in configurable batches
4. Data is transformed via `Field_Mapper` and sent through `Odoo_Client`
5. Entity mappings are stored in `wp4odoo_entity_map` with sync hashes for change detection

### Security

- API keys encrypted at rest (libsodium with OpenSSL fallback)
- Admin AJAX handlers protected by nonce + `manage_options` capability
- Webhooks authenticated via `X-Odoo-Token` header + per-IP rate limiting (100 req/min)
- All inputs sanitized (`sanitize_text_field`, `esc_url_raw`, `absint`)
- `index.php` in every subdirectory to prevent directory listing

## Shortcodes

| Shortcode                   | Description                                                                                       |
|-----------------------------|---------------------------------------------------------------------------------------------------|
| `[wp4odoo_customer_portal]` | Customer portal with Orders and Invoices tabs (requires logged-in user linked to an Odoo partner) |
| `[wp4odoo_lead_form]`       | Lead capture form with AJAX submission, creates `crm.lead` in Odoo                                |

## REST API

Namespace: `wp-json/wp4odoo/v1/`

| Endpoint                  | Method  | Auth               | Description                                |
|---------------------------|---------|--------------------|--------------------------------------------|
| `/webhook`                | POST    | Token + rate limit | Receives change notifications from Odoo    |
| `/webhook/test`           | GET     | Public             | Health check                               |
| `/sync/{module}/{entity}` | POST    | WP Auth            | Triggers sync for a specific module/entity |

## Hooks

### Actions

| Hook                       | Description                                       |
|----------------------------|---------------------------------------------------|
| `wp4odoo_init`             | Plugin initialized                                |
| `wp4odoo_loaded`           | All plugins loaded                                |
| `wp4odoo_register_modules` | Register custom modules                           |
| `wp4odoo_lead_created`     | Lead form submitted                               |
| `wp4odoo_api_call`         | Every Odoo API call (model, method, args, result) |

### Filters

| Filter                                    | Description                       |
|-------------------------------------------|-----------------------------------|
| `wp4odoo_map_to_odoo_{module}_{entity}`   | Modify data before push to Odoo   |
| `wp4odoo_map_from_odoo_{module}_{entity}` | Modify data during pull from Odoo |
| `wp4odoo_ssl_verify`                      | Enable/disable SSL verification   |

## WP-CLI

```bash
wp wp4odoo status                    # Connection info, queue stats, modules
wp wp4odoo test                      # Test Odoo connection
wp wp4odoo sync run                  # Process sync queue
wp wp4odoo sync run --dry-run        # Preview sync without changes
wp wp4odoo queue stats               # Queue statistics
wp wp4odoo queue list --page=1       # Paginated job list
wp wp4odoo queue retry               # Retry all failed jobs
wp wp4odoo queue cleanup --days=7    # Delete old completed/failed jobs
wp wp4odoo queue cancel 42           # Cancel a pending job
wp wp4odoo module list               # List modules with status
wp wp4odoo module enable crm         # Enable a module
wp wp4odoo module disable crm        # Disable a module
```

## Development

For detailed architecture, class diagrams, and data flows, see [ARCHITECTURE.md](ARCHITECTURE.md). For version history, see [CHANGELOG.md](CHANGELOG.md).

### Testing

```bash
# Install dependencies
composer install

# Unit tests (PHPUnit)
php vendor/bin/phpunit

# Static analysis (PHPStan level 5)
php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G

# Coding standards (PHPCS — WordPress-Extra)
php vendor/bin/phpcs

# Integration tests (requires Docker)
npm install
npx wp-env start
npm run test:integration
npx wp-env stop
```

### Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Ensure all checks pass: `php vendor/bin/phpunit && php vendor/bin/phpstan analyse --memory-limit=1G && php vendor/bin/phpcs`
4. Commit and open a Pull Request

### Translations

After adding or changing user-facing strings:

```bash
# Extract strings
xgettext --language=PHP --keyword=__ --keyword=_e --keyword=esc_html__ \
    --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e \
    --from-code=UTF-8 -o languages/wp4odoo.pot \
    wp4odoo.php includes/**/*.php admin/views/*.php templates/*.php

# Update translations
msgmerge --update languages/wp4odoo-fr_FR.po languages/wp4odoo.pot
msgmerge --update languages/wp4odoo-es_ES.po languages/wp4odoo.pot

# Compile
msgfmt -o languages/wp4odoo-fr_FR.mo languages/wp4odoo-fr_FR.po
msgfmt -o languages/wp4odoo-es_ES.mo languages/wp4odoo-es_ES.po
```

## License

[GPL v2 or later](LICENSE)