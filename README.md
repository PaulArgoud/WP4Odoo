# WordPress For Odoo (WP4Odoo)

[![CI](https://github.com/PaulArgoud/wordpress-for-odoo/actions/workflows/ci.yml/badge.svg)](https://github.com/PaulArgoud/wordpress-for-odoo/actions/workflows/ci.yml)

Modular WordPress plugin that creates a seamless, bidirectional bridge between WordPress/WooCommerce and Odoo ERP (v14+).

Built on a clean, extensible architecture, it covers a wide range of integrations: CRM, Sales & Invoicing, WooCommerce, WooCommerce Subscriptions, Easy Digital Downloads, Memberships, MemberPress, Paid Memberships Pro, Restrict Content Pro, GiveWP, WP Charitable, WP Simple Pay (Stripe), WP Recipe Maker, LearnDash, LifterLMS, The Events Calendar, Amelia Booking, Bookly Booking, Sprout Invoices, WP-Invoice, WP Crowdfunding, Ecwid, ShopWP, and form-based lead capture.

Ships in **3 languages** (English, French, Spanish) and is fully translation-ready.

**Target users:** WordPress agencies and businesses running Odoo as their ERP who need reliable, real-time data flow between their website and back-office.

![WordPress For Odoo (WP4Odoo)](assets/images/logo-v2.avif)

## Features

- **Admin Dashboard** — 5-tab settings interface: Connection, Sync, Modules, Queue, Logs
- **Async Queue** — No API calls during user requests; all sync jobs go through a persistent database queue with exponential backoff, deduplication, and configurable batch size
- **Code Quality** — WordPress Coding Standards (PHPCS), PHPStan level 5 static analysis, 1788 unit tests + 26 integration tests, CI/CD with GitHub Actions
- **Dual Transport** — JSON-RPC 2.0 (default for Odoo 17+) and XML-RPC (legacy), swappable via settings, shared retry logic via `Retryable_Http` trait (3 attempts, exponential backoff + jitter)
- **Encrypted Credentials** — API keys encrypted at rest with libsodium (OpenSSL fallback)
- **Extensible** — Register custom modules via `wp4odoo_register_modules` action hook; filter data with `wp4odoo_map_to_odoo_*` / `wp4odoo_map_from_odoo_*`
- **Multilingual (3 languages)** — Fully internationalized with WordPress standard Gettext i18n. Ships with English (source), French, and Spanish translations (417 strings). Translation-ready for additional languages via `.po`/`.mo` files
- **Onboarding** — Post-activation redirect, setup notice, 3-step checklist with progress bar, inline Odoo documentation (API keys, webhooks)
- **Webhooks** — REST API endpoints for real-time notifications from Odoo, with per-IP rate limiting
- **WP-CLI** — Full command suite: `wp wp4odoo status|test|sync|queue|module` for headless management

## Requirements

- PHP 8.2+
- Odoo 17+ (JSON-RPC) or Odoo 14+ (XML-RPC)
- WordPress 6.0+

## Compatibility

### By Odoo version and hosting type

| Versions | On-Premise | Odoo.sh   | Online  | One App Free |
|:---------|:----------:|:---------:|:-------:|:------------:|
| 17 – 19  | ✅ Full³   | ✅ Full³ | ✅ Full | ⚠️ Partial²  |
| 14 – 16  | ✅ Full³   | ✅ Full³ | N/A¹    | N/A¹         |
| < 14     | ❌         | ❌       | N/A¹    | N/A¹         |

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

## Module System

Each Odoo domain is encapsulated in an independent module extending `Module_Base`. The plugin automatically detects missing Odoo apps at connection test and module activation.

| WP4Odoo Modules               | Synch. | Odoo Apps                             | Free⁴ | Key Features                                                                  |
|-------------------------------|:------:|---------------------------------------|:-----:|-------------------------------------------------------------------------------|
| **CRM**                       |   ↔️   | Contacts, CRM                         |  ⚠️  | Contact sync, lead form shortcode, email dedup, archive-on-delete              |
| **Sales**                     |   ⬅️   | Contacts, Sales, Invoicing            |  ❌  | Order/invoice CPTs, customer portal shortcode, currency display                |
| **Amelia Booking**            |   ➡️   | Contacts, Calendar                    |  ⚠️  | Service/appointment sync, calendar events, customer-to-partner resolution      |
| **Bookly Booking**            |   ➡️   | Contacts, Calendar                    |  ⚠️  | Service/booking sync via WP-Cron polling, hash-based change detection          |
| **Easy Digital Downloads**    |   ↔️   | Contacts, Sales, Invoicing            |  ❌  | Download/order sync, status mapping, invoice pull                              |
| **Events Calendar**           |   ↔️   | Contacts, Events (+ Calendar)         |  ⚠️  | Event/ticket/attendee sync, dual-model (event.event or calendar.event)         |
| **Forms (7 plugins)**         |   ➡️   | Contacts, CRM                         |  ⚠️  | GF, WPForms, CF7, Fluent, Formidable, Ninja, Forminator — lead auto-detection  |
| **GiveWP**                    |   ➡️   | Contacts, Invoicing (+ OCA Donation)  |  ⚠️  | Form/donation sync, dual-model detection, auto-validate, recurring donations   |
| **LearnDash**                 |   ↔️   | Contacts, Sales, Invoicing            |  ❌  | Course/group/transaction/enrollment sync, auto-post invoices, course/group pull |
| **LifterLMS**                 |   ↔️   | Contacts, Sales, Invoicing            |  ❌  | Course/membership/order/enrollment sync, auto-post invoices, course/membership pull |
| **MemberPress**               |   ➡️   | Contacts, Members, Invoicing          |  ❌  | Plan/txn/sub sync, auto-post invoices, status mapping                          |
| **Paid Memberships Pro**      |   ➡️   | Contacts, Members, Invoicing          |  ❌  | Level/order/membership sync, auto-post invoices, status mapping                |
| **Restrict Content Pro**      |   ➡️   | Contacts, Members, Invoicing          |  ❌  | Level/payment/membership sync, auto-post invoices, status mapping              |
| **WP Charitable**             |   ➡️   | Contacts, Invoicing (+ OCA Donation)  |  ⚠️  | Campaign/donation sync, dual-model detection, auto-validate, recurring         |
| **WP Recipe Maker**           |   ➡️   | Products                              |  ❌  | Recipe sync as service products, structured descriptions, push-only            |
| **WP Simple Pay**             |   ➡️   | Contacts, Invoicing (+ OCA Donation)  |  ⚠️  | Stripe payment sync, webhook capture, dual-model, auto-validate, recurring     |
| **WooCommerce**               |   ↔️   | Contacts, Sales, Inventory, Invoicing |  ❌  | Product/order/stock sync, variants, image pull, exchange rates, bulk ops       |
| **WooCommerce Memberships**   |   ➡️   | Contacts, Members                     |  ❌  | Plan auto-sync, status mapping, filterable via `wp4odoo_membership_status_map` |
| **WooCommerce Subscriptions** |   ↔️   | Contacts, Subscriptions, Invoicing    |  ❌  | Subscription/renewal sync, dual-model (sale.subscription / account.move)       |
| **Sprout Invoices**           |   ↔️   | Contacts, Invoicing                   |  ⚠️  | Invoice/payment sync, status mapping, auto-posting, One2many line items, pull  |
| **WP-Invoice**                |   ➡️   | Contacts, Invoicing                   |  ⚠️  | Invoice sync, auto-posting for paid invoices, One2many line items              |
| **WP Crowdfunding**           |   ➡️   | Products                              |  ❌  | Campaign sync as service products, funding description, coexists with WC       |
| **Ecwid**                     |   ➡️   | Contacts, Sales                       |  ❌  | Product/order sync via WP-Cron polling, REST API, hash-based change detection  |
| **ShopWP**                    |   ➡️   | Products                              |  ❌  | Shopify product sync via CPT + custom table, variant price/SKU from $wpdb      |

> ⁴ **[One App Free](https://www.odoo.com/pricing)**: with CRM as your free app, CRM and Forms modules work. With Invoicing as your free app, GiveWP, WP Charitable, WP Simple Pay, Sprout Invoices, and WP-Invoice work. With Calendar as your free app, Amelia, Bookly, and Events Calendar (fallback mode) work (partial — no Contacts). Sales, WooCommerce, WooCommerce Subscriptions (Enterprise), Memberships (MemberPress/PMPro/RCP/WC Memberships), LMS (LearnDash/LifterLMS), Ecwid, and WP Recipe Maker require 2–4 apps.

### Third-party modules

Third-party modules can be registered:

```php
add_action('wp4odoo_register_modules', function($plugin) {
    $plugin->register_module('my_module', new My_Custom_Module());
});
```

## Shortcodes

| Shortcode                   | Description                                                                                       |
|-----------------------------|---------------------------------------------------------------------------------------------------|
| `[wp4odoo_customer_portal]` | Customer portal with Orders and Invoices tabs (requires logged-in user linked to an Odoo partner) |
| `[wp4odoo_lead_form]`       | Lead capture form with AJAX submission, creates `crm.lead` in Odoo                                |

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

Each module also provides `wp4odoo_{module}_*_status_map` filters for customizing status mappings (e.g., `wp4odoo_mepr_txn_status_map`, `wp4odoo_wcs_status_map`). See [ARCHITECTURE.md](ARCHITECTURE.md#hooks--filters) for the full list.

## Architecture

![WP4ODOO Architecture](assets/images/architecture-v2.svg)

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

## Development

For detailed architecture, class diagrams, and data flows, see [ARCHITECTURE.md](ARCHITECTURE.md). For version history, see [CHANGELOG.md](CHANGELOG.md).

### Testing

```bash
# Install dependencies
composer install

# Run all checks at once (mirrors CI — recommended before pushing)
composer check

# Or run individually:
composer phpcs                # Coding standards (PHPCS — WordPress-Extra)
composer test                 # Unit tests (PHPUnit)
composer phpstan              # Static analysis (PHPStan level 5)

# Integration tests (requires Docker)
npm install
npx wp-env start
npm run test:integration
npx wp-env stop
```

### Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Ensure all checks pass: `composer check`
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