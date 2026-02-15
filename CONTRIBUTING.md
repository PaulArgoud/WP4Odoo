# Contributing to WordPress For Odoo (WP4Odoo)

Thanks for your interest in contributing to WP4Odoo! This guide will help you get started.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [What We're Looking For](#what-were-looking-for)
- [Getting Started](#getting-started)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Testing](#testing)
- [Translations](#translations)
- [Submitting Changes](#submitting-changes)
- [Issue Guidelines](#issue-guidelines)
- [Review Process](#review-process)

## Code of Conduct

This project follows the [WordPress Community Code of Conduct](https://make.wordpress.org/handbook/community-code-of-conduct/). Be respectful, constructive, and inclusive in all interactions.

## What We're Looking For

Contributions are welcome in the following areas:

- **Bug fixes** — Especially edge cases in sync logic, field mapping, or third-party plugin compatibility.
- **New integrations** — Adding support for other WordPress plugins (form plugins, e-commerce, LMS, etc.).
- **Translations** — New languages or improvements to existing French/Spanish translations.
- **Documentation** — Tutorials, usage examples, hook documentation, FAQ entries.
- **Tests** — Increasing unit or integration test coverage.
- **Performance improvements** — Queue processing, batch sync, memory usage.

Please **open an issue first** before working on:

- New modules (CRM, Sales, WooCommerce, etc.) — these require architectural discussion.
- Changes to the sync queue or transport layer.
- Breaking changes to hook signatures or the REST API.

Out of scope (will likely be declined):

- Features requiring custom Odoo modules — WP4Odoo is designed to work with standard Odoo apps only.
- Support for Odoo versions below 14.

## Getting Started

1. **Fork** the repository on GitHub.
2. **Clone** your fork locally:
   ```bash
   git clone https://github.com/YOUR-USERNAME/wordpress-for-odoo.git
   cd wordpress-for-odoo
   ```
3. **Create a feature branch** from `main`:
   ```bash
   git checkout -b feature/my-feature
   ```

## Development Setup

### Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+ and npm (for integration tests)
- Docker (for integration tests via `wp-env`)
- An Odoo instance (17+ recommended) for manual testing

### Install Dependencies

```bash
composer install
npm install        # only needed for integration tests
```

### Local WordPress Environment

The project includes a `.wp-env.json` configuration for [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/):

```bash
npx wp-env start          # Starts WordPress + MySQL in Docker
npx wp-env stop           # Stops the environment
npx wp-env clean all      # Resets everything
```

WordPress will be available at `http://localhost:8888` (admin: `admin` / `password`).

### Connecting to Odoo

For development, you can use:

- **Odoo.com** free trial (one app free, limited but sufficient for CRM/Forms testing)
- **Docker**: `docker run -d -p 8069:8069 odoo:17` for a local instance
- An existing Odoo staging server

## Coding Standards

### PHP

The project follows **WordPress Coding Standards** enforced via PHPCS:

```bash
composer phpcs                          # Check standards
php vendor/bin/phpcbf                   # Auto-fix what can be fixed
```

Key conventions:

- **Naming**: `snake_case` for functions and variables, `Upper_Snake_Case` for class names (WordPress convention).
- **Docblocks**: Every public method must have a `@param` / `@return` docblock.
- **Type hints**: Use PHP type declarations for parameters and return types wherever possible.
- **Sanitization**: All user inputs must be sanitized (`sanitize_text_field`, `absint`, `esc_url_raw`). All outputs must be escaped (`esc_html`, `esc_attr`).
- **Nonces**: Every admin AJAX handler must verify a nonce.

### Static Analysis

PHPStan level 6 is required to pass:

```bash
composer phpstan            # or: php -d memory_limit=1G vendor/bin/phpstan analyse --memory-limit=1G
```

### File Organization

```
includes/
├── api/                         # Odoo transport & client
│   ├── class-odoo-client.php
│   ├── class-odoo-jsonrpc.php
│   └── ...
├── admin/                       # Admin PHP classes & AJAX traits
│   ├── class-admin.php
│   ├── class-settings-page.php
│   └── ...
├── i18n/                        # WPML/Polylang translation adapters
├── modules/
│   ├── class-module-base.php    # Abstract base for all modules
│   ├── class-crm-module.php
│   └── ...
├── class-sync-engine.php        # Queue processor (batch, retry, circuit breaker)
├── class-sync-queue-repository.php  # Persistent queue DB layer
└── ...
admin/
├── views/                       # Admin page templates
├── js/                          # Admin JavaScript
├── css/                         # Admin CSS
templates/                       # Frontend templates (portal)
tests/
├── Unit/                        # PHPUnit unit tests
├── Integration/                 # Integration tests (require wp-env)
├── stubs/                       # Class/function stubs for unit tests
├── helpers/                     # Shared test helpers
```

New classes should follow this structure. Place module-specific code inside `includes/modules/`.

### JavaScript & CSS

- Vanilla JS only (no jQuery for new code, though legacy code may use it).
- CSS follows WordPress admin conventions.
- Prefix all CSS classes and JS globals with `wp4odoo-` or `wp4odoo_`.

## Testing

### Unit Tests

```bash
composer test               # or: php vendor/bin/phpunit
```

Unit tests live in `tests/Unit/` and should not require a database or network. Mock Odoo API responses using the existing test helpers.

**When to write tests:**

- Every bug fix should include a regression test.
- New public methods need at least one test.
- Sync logic and field mapping changes require thorough coverage.

### Integration Tests

```bash
npx wp-env start
npm run test:integration
npx wp-env stop
```

Integration tests live in `tests/Integration/` and run inside a real WordPress environment. Use these for testing database operations, hook firing, and WP-CLI commands.

### Test Naming Convention

```php
public function test_sync_contact_creates_partner_in_odoo(): void {}
public function test_duplicate_email_is_deduplicated(): void {}
public function test_queue_retries_failed_job_with_backoff(): void {}
```

Use descriptive names that read as sentences: `test_<what>_<expected_behavior>`.

## Translations

WP4Odoo ships with English (source), French (`fr_FR`), and Spanish (`es_ES`).

### Adding a New Language

1. Copy the `.pot` template:
   ```bash
   cp languages/wp4odoo.pot languages/wp4odoo-{locale}.po
   ```
2. Translate the strings in the `.po` file (use [Poedit](https://poedit.net/) or a text editor).
3. Compile:
   ```bash
   msgfmt -o languages/wp4odoo-{locale}.mo languages/wp4odoo-{locale}.po
   ```
4. Submit a PR with both `.po` and `.mo` files.

### Updating Existing Translations

After adding or changing translatable strings in PHP:

```bash
# Regenerate the .pot template
xgettext --language=PHP --keyword=__ --keyword=_e --keyword=esc_html__ \
    --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e \
    --from-code=UTF-8 -o languages/wp4odoo.pot \
    wp4odoo.php includes/**/*.php admin/views/*.php templates/*.php

# Update existing translations
msgmerge --update languages/wp4odoo-fr_FR.po languages/wp4odoo.pot
msgmerge --update languages/wp4odoo-es_ES.po languages/wp4odoo.pot

# Recompile
msgfmt -o languages/wp4odoo-fr_FR.mo languages/wp4odoo-fr_FR.po
msgfmt -o languages/wp4odoo-es_ES.mo languages/wp4odoo-es_ES.po
```

## Submitting Changes

### Commit Messages

Use clear, descriptive commit messages. Prefix with the area of change:

```
crm: fix duplicate contact sync when email contains uppercase
woo: add support for variable product image galleries
queue: increase default batch size to 25
docs: add webhook setup instructions
i18n: update French translation for portal strings
tests: add unit tests for Field_Mapper date conversion
```

### Pull Request Checklist

Before submitting your PR, make sure:

- [ ] All checks pass:
  ```bash
  composer check              # Runs PHPCS + PHPUnit + PHPStan (mirrors CI)
  # Or individually: composer phpcs / composer test / composer phpstan
  ```
- [ ] New code includes tests (unit and/or integration as appropriate).
- [ ] Translatable strings use `__()`, `esc_html__()`, etc. with the `wp4odoo` text domain.
- [ ] No hardcoded Odoo field names — use `Field_Mapper` constants.
- [ ] PR description explains **what** changed and **why**.
- [ ] If adding a hook, it's documented in the [ARCHITECTURE.md hooks table](ARCHITECTURE.md#hooks--filters).

### Branch Naming

```
feature/short-description    # New features
fix/short-description        # Bug fixes
docs/short-description       # Documentation only
i18n/short-description       # Translation updates
test/short-description       # Test additions
```

## Issue Guidelines

### Bug Reports

A good bug report includes:

- **Environment**: PHP version, WordPress version, WooCommerce version (if relevant), Odoo version + hosting type (On-Premise / Odoo.sh / Online).
- **Plugin version**: WP4Odoo version number or commit hash.
- **Steps to reproduce**: Numbered steps to trigger the bug.
- **Expected behavior**: What should happen.
- **Actual behavior**: What actually happens.
- **Logs**: Relevant entries from the WP4Odoo Logs tab or `wp-content/debug.log`.
- **Screenshots**: If it's a UI issue.

### Feature Requests

Describe the use case first, then the proposed solution. Explain how it fits within the existing module architecture.

## Review Process

- PRs are typically reviewed within **one week**.
- At least **one approval** is required before merging.
- CI must pass (PHPUnit, PHPStan, PHPCS) — the GitHub Actions workflow runs automatically.
- Maintainers may request changes or suggest alternative approaches.
- Once approved, the maintainer will merge and include the change in the next release (see [CHANGELOG.md](CHANGELOG.md)).

## Questions?

If something isn't covered here, open an issue with the `question` label or reach out at [paul.argoud.net](https://paul.argoud.net).

Thank you for helping make WP4Odoo better!