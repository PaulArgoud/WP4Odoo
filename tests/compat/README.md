# Compatibility run

Execution-level checks against **real** Odoo servers and the **latest releases** of the free
third-party plugins. It complements the unit tests (which use hand-written stubs) and the weekly
API Surface scan (`tests/ApiSurface`, source-level).

| Script | What it verifies |
|---|---|
| `odoo-fields.php` | Every field a module maps by default exists in the Odoo schema (or is renamed through `$odoo_field_renames`), for each Odoo version. |
| `odoo-flows.php` | Contacts, CRM leads, products, stock, sale orders, invoices and error handling, through JSON-2, JSON-RPC and XML-RPC. |
| `plugins.sh` + `check-plugin.php` | For each free plugin: install the latest release, activate it, and check that its module is detected (`detect()`) and its dependency check reports no problem. |

Anything that fails and is not listed in `known-issues.php` fails the run; that file documents the
accepted gaps (Enterprise-only models, removed Odoo fields, plugins that need extra setup).

## Running it in CI

`.github/workflows/compat.yml` runs weekly and on demand (*Actions → Compatibility → Run workflow*).

## Running it locally

Requires Docker and Node 22 (`wp-env` does not start under newer Node versions).

```bash
npm ci
npx wp-env start
tests/compat/odoo-setup.sh          # starts Odoo 19 and 20, installs apps, writes .keys/
tests/compat/connect-network.sh     # makes them reachable as http://odoo19:8069 / http://odoo20:8069

npx wp-env run tests-cli wp eval-file wp-content/plugins/wp4odoo/tests/compat/odoo-fields.php
npx wp-env run tests-cli wp eval-file wp-content/plugins/wp4odoo/tests/compat/odoo-flows.php
npx wp-env run tests-cli bash wp-content/plugins/wp4odoo/tests/compat/plugins.sh
```

If port 8889 is taken (MAMP), add `{"testsPort": 8899}` to `.wp-env.override.json`.
Set `ODOO_VERSIONS="19"` to limit the run; add a version in `docker-compose.odoo.yml` to extend it.
The scripts leave test records in the wp-env database; run `npx wp-env clean tests` before the
integration suite afterwards.

## Adding or fixing coverage

* New free plugin: add `module-id  wordpress.org-slug` to `plugins.txt`.
* Accepted gap: add it to `known-issues.php` with the reason.
* Premium plugins cannot be installed automatically and are not covered.
