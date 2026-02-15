# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.2.0] - Unreleased

### Fixed
- **WC Bookings silent push failure** — Entity type `'product'` was not declared in WC_Bookings_Module's `$odoo_models` (only `'service'` and `'booking'`), causing every booking product push to silently fail. Changed to `'service'`
- **Exclusive group mismatch** — WooCommerce, Sales, and EDD modules used `'commerce'` as their exclusive group while CLAUDE.md and ARCHITECTURE.md documented `'ecommerce'`. Unified to `'ecommerce'`
- **Batch creates double failure** — `Sync_Engine::process_batch_creates()` added jobs to `$claimed_jobs` before JSON validation, causing `handle_failure()` to be called twice (once for invalid JSON, once for batch error). Moved append after validation
- **SSRF bypass via DNS failure** — `is_safe_url()` returned `true` when `gethostbyname()` failed (returns the input on DNS failure), allowing URLs with unresolvable hostnames to bypass SSRF protection. Now returns `false`
- **Queue health metrics cache leak** — `invalidate_stats_cache()` cleared `wp4odoo_queue_stats` but not `wp4odoo_queue_health`, leaving stale health metrics
- **Stale recovery ordering** — `recover_stale_processing()` ran after `fetch_pending()`, so freshly recovered jobs were excluded from the current batch. Reordered to recover first
- **Odoo_Client retry missing action** — Retry path after session re-auth did not fire `wp4odoo_api_call` action, making retry calls invisible to monitors
- **MySQL 5.7 compat** — `@@in_transaction` session variable query could produce a visible error on MySQL 5.7 (which lacks this variable). Wrapped with `suppress_errors()`
- **Dual accounting delete** — `resolve_accounting_model()` was skipped for `delete` actions, causing delete calls to target the wrong Odoo model when OCA `donation.donation` was active
- **Helpdesk exclusive group/priority** — `Helpdesk_Module_Base` used method overrides instead of properties for `$exclusive_group` and `$exclusive_priority`, inconsistent with all other intermediate bases. Converted to properties
- **Undefined `$jobs` variable** — `Sync_Engine::process_queue()` could reference undefined `$jobs` if the try block threw before assignment
- **Partner email normalization** — `Partner_Service::get_or_create_batch()` now trims and lowercases emails before Odoo lookup, preventing duplicate partners from case mismatches
- **Reconciler client hoisting** — `Reconciler` resolved the Odoo client inside the per-entity loop instead of once before it
- **Logger context truncation** — `truncate_context()` JSON-encoded the full array then truncated the string, producing invalid JSON. Now truncates the array first, then encodes
- **Empty encryption key warning** — `Odoo_Auth` now logs a warning via `error_log()` when the encryption key is empty, aiding diagnosis of misconfigured installations
- **CLI `--format` validation** — `queue stats` and `queue list` subcommands now reject unsupported `--format` values with a clear error
- **Ecwid cron orphan on deactivation** — `wp4odoo_ecwid_poll` cron event was cleared on uninstall but not on plugin deactivation, leaving an orphaned cron entry. Added to `deactivate()`
- **Exclusive group priority documentation** — ARCHITECTURE.md listed membership, invoicing, and helpdesk exclusive group priorities in reverse order (lower number shown as winning). Corrected to reflect actual `>=` logic where highest number wins

### Added
- **Compatibility report link** — TESTED_UP_TO version warnings now include a "Report compatibility" link that opens a pre-filled WPForms form with module name, WP4Odoo version, third-party plugin version, WordPress version, PHP version, and Odoo major version. Shown in both the global admin notice banner and per-module notices on the Modules tab. Filterable via `wp4odoo_compat_report_url`
- **Odoo version detection** — `Transport` interface gains `get_server_version(): ?string`. JSON-RPC extracts `server_version` from the authenticate response; XML-RPC calls `version()` on `/xmlrpc/2/common` after auth. `test_connection()` now populates the `version` field. The AJAX handler stores the version in `wp4odoo_odoo_version` option for use in compat reports and diagnostics

### Changed
- **Log module filter** — Expanded the log viewer module dropdown from ~20 hardcoded entries to all 33 sync modules plus 5 system modules, organized in `<optgroup>` sections
- **Log level i18n** — Log level labels (Debug, Info, Warning, Error, Critical) in the sync settings tab are now translatable
- **Admin JS i18n** — Hardcoded English strings in `admin.js` (server error, unknown error, completed, remove) replaced with localized strings via `wp_localize_script`
- **XSS defense-in-depth** — Added `escapeHtml()` helper in `admin.js` for log `module` and `level` fields in the AJAX log table
- **Module toggle accessibility** — Added `aria-label` on module enable/disable toggle switches
- **Log context column** — `QueryService::get_log_entries()` now includes the `context` column in its SELECT
- **CLI confirmation prompts** — `sync run` and `queue retry` now require interactive confirmation (skippable with `--yes`)

## [3.1.5] - 2026-02-15

### Changed
- **Handler base classes** — Extracted 5 abstract handler base classes to eliminate duplicated constructor, logger, and shared helper logic across 13 handler files:
  - `Membership_Handler_Base` — shared by MemberPress, PMPro, RCP handlers (constructor + logger)
  - `Donation_Handler_Base` — shared by GiveWP, Charitable, SimplePay handlers (constructor + logger + `load_form_by_cpt()` + `format_donation()` dual-model routing)
  - `LMS_Handler_Base` — shared by LearnDash, LifterLMS handlers (constructor + logger + `build_invoice()` + `build_sale_order()`)
  - `Helpdesk_Handler_Base` — shared by Awesome Support, SupportCandy handlers (constructor + logger + `PRIORITY_MAP` + `map_priority()` + `parse_ticket_from_odoo()`)
  - `Booking_Handler_Base` — shared by Amelia, Bookly, WC Bookings handlers (constructor + logger)
- **`push_entity()` helper** — `Module_Helpers::push_entity(module, entity, setting_key, wp_id)` consolidates the repeated guard + map + queue pattern into a single method. Now used by 24 hook callbacks across 19 trait files (was 6 across 3). Removed unused `Queue_Manager` imports from 10 traits
- **`LMS_Module_Base` abstract class** — Converted `LMS_Helpers` trait to `LMS_Module_Base` abstract class extending `Module_Base`, aligning with `Membership_Module_Base`, `Booking_Module_Base`, etc. LearnDash and LifterLMS modules now extend this intermediate base class instead of using a trait
- **CLI `match` expressions** — Converted `queue()` and `module()` subcommand dispatch from `switch` to PHP 8.0 `match` expressions in `CLI`
- **Handler tests** — 9 handler test classes refactored to extend `Module_Test_Case`, removing ~100 lines of duplicated `$wpdb` stub initialization and global store resets. 8 new tests: 4 for `push_entity()`, 2 for `Booking_Handler_Base`, 2 for `LMS_Module_Base`
- **Queue stats consolidation** — `Sync_Queue_Repository::get_stats()` merged 3 separate SQL queries (pending count, failed count, last completed timestamp) into a single `GROUP BY status` query. Cache invalidation moved from per-job `update_status()` to a single `invalidate_stats_cache()` call at end of batch processing in `Sync_Engine`, eliminating transient thrashing during large batches
- **Webhook dedup window** — `Webhook_Handler::DEDUP_WINDOW` increased from 300 s (5 min) to 1800 s (30 min) to prevent duplicate processing of retried Odoo webhooks
- **i18n hardcoded labels** — Wrapped remaining hardcoded UI strings with translation functions: log level labels in `tab-logs.php`, direction labels (`WP → Odoo` / `Odoo → WP`) in `tab-queue.php`, `class-admin.php`, and `admin.js`

### Fixed
- **Batch creates JSON decode** — Added `json_last_error()` validation in `Sync_Engine::process_batch_creates()` for individual job payloads (matching the existing check in single-job processing). Invalid JSON now correctly marks the job as `Error_Type::Permanent` instead of silently skipping
- **Remaining counter** — `Sync_Engine` remaining jobs counter now subtracts both individually processed and batch-grouped job counts, preventing inflated "remaining" log entries

### Tests
- **292 new unit tests** — 15 new test files covering previously untested base classes, handlers, and modules:
  - `CRMModuleTest` (55 tests) — CRM_Module push/pull contacts and leads
  - `MembershipModuleBaseTest` (15), `DualAccountingModuleBaseTest` (17), `BookingModuleBaseTest` (26), `HelpdeskModuleBaseTest` (28) — intermediate module base classes
  - `MembershipHandlerBaseTest` (2), `DonationHandlerBaseTest` (10), `HelpdeskHandlerBaseTest` (14), `LMSHandlerBaseTest` (12) — handler base classes
  - `CrowdfundingHandlerTest` (13), `EcwidHandlerTest` (14), `LearnDashHandlerTest` (33), `ShopWPHandlerTest` (8), `SproutInvoicesHandlerTest` (37), `WPInvoiceHandlerTest` (18) — individual handlers

## [3.1.0] - 2026-02-15

### Added
- **`should_sync()` helper** — New `Module_Base::should_sync(string $setting_key)` consolidates the repeated `is_importing()` + settings check pattern into a single guard clause. Applied across ~40 hook callbacks in 22 trait files, replacing ~80 lines of duplicated guard logic. 6 new tests in `ModuleBaseHelpersTest`
- **`poll_entity_changes()` helper** — New `Module_Base::poll_entity_changes(string $entity_type, array $items, string $id_field)` extracts the SHA-256 hash-based change detection loop from Bookly and Ecwid cron traits into a reusable method. Detects creates, updates (hash mismatch), and deletes (missing items) against the entity map. 5 new tests in `ModuleBaseHelpersTest`
- **`Odoo_Accounting_Formatter::build_invoice_lines()`** — Shared static method for converting line item arrays into Odoo One2many `(0, 0, {...})` tuples with fallback to a single total line. Used by WP-Invoice and Sprout Invoices handlers (key normalization + delegation). 6 new tests in `OdooAccountingFormatterTest`

- **`get_dedup_domain()` in all modules** — Every module now implements `get_dedup_domain()` for idempotent Odoo creates. Products/services dedup by `name`, invoices/payments by `ref`, donations by `payment_ref`, attendees by `email+event_id`, leads by `email_from`, contacts by `email`. 76 new tests in `DedupDomainTest`
- **Table existence checks** — `check_dependency()` now verifies that required third-party database tables exist before declaring a module available. New `get_required_tables()` override in Amelia, Bookly, PMPro, ShopWP, and SupportCandy modules. Missing tables produce a warning notice and prevent module boot
- **System cron recommendation** — Polling modules (Bookly, Ecwid) now show an informational notice when `DISABLE_WP_CRON` is not set, recommending a system cron job for reliable 5-minute intervals. New `uses_cron_polling()` override in Module_Helpers trait
- **Queue_Manager::reset()** — New static method for test isolation, clears the internal singleton repository. Added to `Module_Test_Case::reset_static_caches()`
- **Graceful degradation for third-party hooks** — All ~87 third-party hook callbacks are now wrapped with `safe_callback()` in `Module_Base`. If a third-party plugin update causes a callback to crash (`\Throwable`), the exception is caught and logged as `critical` instead of crashing the WordPress request. WP-Cron polling (Bookly, Ecwid) also has individual try/catch around each poll operation. 9 new tests in `SafeCallbackTest`
- **Defensive version bounds** — Each module now declares `PLUGIN_MIN_VERSION` and `PLUGIN_TESTED_UP_TO` constants. At boot, `check_dependency()` blocks modules whose third-party plugin is below minimum (error notice), and warns when the plugin version exceeds the last tested version (warning notice). Patch-version normalization (e.g. `10.5.0` within `10.5` range). Admin notice banner on the settings page for untested versions. `Module_Registry` enforces version gating before boot. 17 new tests in `VersionBoundsTest`
- **AffiliateWP module** — Push-only sync of AffiliateWP affiliates and referral commissions to Odoo. Affiliates synced as `res.partner` (vendor), referrals as vendor bills (`account.move` with `move_type=in_invoice`). First `in_invoice` support in the plugin. Auto-sync affiliate before referral push. Auto-post vendor bills when referral is paid (optional). Status mapping: unpaid→draft, paid→posted, rejected→cancel. 3 settings (sync_affiliates, sync_referrals, auto_post_bills). 62 new tests
- **Odoo_Accounting_Formatter::for_vendor_bill()** — New static method for formatting vendor bill data (`in_invoice`). No `product_id` in lines (name + price_unit only). Filter hook `wp4odoo_vendor_bill_line_data`
- **WP All Import interceptor module** — Meta-module that intercepts WP All Import CSV/XML imports via `pmxi_saved_post` hook and routes imported records to the correct module's sync queue. Ensures Odoo sync even when WP All Import's "speed optimization" disables standard WordPress hooks. Static routing table (18 post types → modules), filterable via `wp4odoo_wpai_routing_table`. Import completion summary logging. Queue dedup handles double-enqueue safely. 30 new tests

### Security
- **OpenSSL encryption upgraded to AES-256-GCM** — OpenSSL fallback encryption (when libsodium is unavailable) now uses AES-256-GCM (authenticated encryption with associated data) instead of AES-256-CBC. Backward-compatible decryption of existing CBC-encrypted values. Re-encrypted on next save
- **Webhook token encrypted at rest** — `wp4odoo_webhook_token` option is now encrypted using `Odoo_Auth::encrypt()` (libsodium / AES-256-GCM). Backward-compatible with previously stored plaintext tokens
- **SSRF protection** — Odoo connection URL validation now blocks private/reserved IP ranges (RFC 1918, loopback, link-local) and localhost/`.local`/`.internal` TLDs. Uses `gethostbyname()` DNS resolution to catch DNS rebinding
- **CLI reconcile --fix confirmation** — `wp wp4odoo queue reconcile --fix` now requires interactive confirmation before modifying records (skippable with `--yes`)
- **CLI module enable mutual exclusivity** — `wp wp4odoo module enable` now enforces exclusive group rules, automatically disabling conflicting modules with a warning

### Fixed
- **Odoo_Client create() retry safety** — Session re-authentication retry is now skipped for `create` method calls, preventing duplicate record creation when a session expires mid-request
- **is_session_error() false positive** — Removed `access denied` from session error detection. Odoo uses "access denied" for business-level `AccessError` (insufficient model ACL), not session expiry
- **Queue processing race condition** — `process_queue()` (global lock) and `process_module_queue()` (per-module lock) could both claim the same job. New atomic `claim_job()` method uses `UPDATE…WHERE status='pending'` to prevent double-processing
- **Error_Type classification in push_to_odoo()** — Client exceptions in `push_to_odoo()` are now classified via `classify_exception()`: HTTP 5xx/429, timeouts, and network errors → `Error_Type::Transient` (retry with backoff); access denied, validation errors, constraints → `Error_Type::Permanent` (fail immediately)
- **Failure_Notifier ratio-based logic** — Aligned with `Circuit_Breaker`: a batch counts as failed only when 80%+ of jobs failed (was counting individual failures). Prevents false alarms from occasional failures in healthy batches
- **recover_stale_processing infinite retry** — Stale job recovery now increments `attempts` and marks jobs as `failed` when `max_attempts` is exceeded, preventing infinite retry loops after process crashes
- **Database_Migration migration 5 idempotency** — `idx_wp_lookup` index replacement now checks column composition before ALTER TABLE, preventing redundant DROP+ADD on partial migration re-runs
- **get_stats() cache invalidation** — Queue stats transient is now deleted on `update_status()`, ensuring dashboards reflect status changes immediately
- **HTTP status code in exceptions** — `Retryable_Http` now propagates HTTP status codes (429, 500, etc.) via `RuntimeException::getCode()` for proper `Error_Type` classification
- **Amelia hooks inconsistency** — `on_booking_canceled()` and `on_booking_rescheduled()` now use `should_sync('sync_appointments')` instead of bare `is_importing()`, aligning with all other hook callbacks
- **Circuit_Breaker** — `PROBE_TTL` increased from 120 s to 360 s to exceed `RECOVERY_DELAY + BATCH_TIME_LIMIT` (355 s), preventing overlapping probes when a half-open probe batch takes its full allotted time
- **Sync_Engine** — Batch creates with an unresolvable module (disabled or removed) now mark all grouped jobs as permanently failed instead of silently skipping them (jobs were left stuck in `processing` for 10 min until stale recovery)
- **Sync_Queue_Repository** — Dedup `SELECT … FOR UPDATE` now checks for both `pending` and `processing` statuses, preventing duplicate enqueues when an entity is already being synced
- **Webhook_Handler** — Rate limiting switched from `wp_cache_*` (non-persistent by default) to `set_transient()` / `get_transient()`, ensuring rate limits persist across PHP processes

### Changed
- **Entity_Map_Repository bulk cache** — `get_module_entity_mappings()` no longer populates the per-entity LRU cache to prevent counter-productive eviction of frequently-used entries during bulk polling operations (Bookly, Ecwid). Cache size increased from 2000 to 5000
- **SimplePay_Hooks** — Deduplicated `on_payment_succeeded()` and `on_invoice_payment_succeeded()` (95% identical) into a shared `process_stripe_payment()` method
- **Bookly_Cron_Hooks / Ecwid_Cron_Hooks** — `poll()` methods now delegate to `poll_entity_changes()` from `Module_Base` instead of duplicating the hash-diff loop
- **WP_Invoice_Handler / Sprout_Invoices_Handler** — `build_invoice_lines()` now normalizes plugin-specific keys and delegates to `Odoo_Accounting_Formatter::build_invoice_lines()`
- **22 hook traits** — Replaced `is_importing()` + `$settings` guard clauses with `should_sync()` calls across all third-party hook callbacks

## [3.0.5] - 2026-02-14

### Added
- **Awesome Support module** — Bidirectional sync between Awesome Support tickets and Odoo helpdesk tickets (`helpdesk.ticket` Enterprise) or project tasks (`project.task` Community fallback). Dual-model detection at runtime. Push: ticket data + customer→partner resolution + team_id/project_id injection. Pull: stage name→WP status via keyword heuristic (close/done/resolved/solved/cancel + FR keywords). Closed stage resolution by `sequence DESC` with 1h transient cache. Exclusive group `helpdesk` (priority 10). 43 new tests
- **SupportCandy module** — Same bidirectional helpdesk sync as Awesome Support but for SupportCandy. Custom table data access via `$wpdb` (`wpsc_ticket`, `wpsc_ticketmeta`). Detection via `WPSC_VERSION`. Exclusive group `helpdesk` (priority 15 — Awesome Support wins). 43 new tests
- **Helpdesk_Module_Base** — Shared abstract base class for helpdesk modules (Awesome Support + SupportCandy). Dual-model detection, stage resolution, keyword-based status mapping, shared push/pull logic. Follows `Booking_Module_Base` pattern
- **Odoo_Model enum** — Added `HelpdeskTicket` (`helpdesk.ticket`), `HelpdeskStage` (`helpdesk.stage`), `ProjectTask` (`project.task`), `ProjectTaskType` (`project.task.type`) cases
- **WC Points & Rewards module** — Bidirectional sync between WooCommerce Points & Rewards and Odoo Loyalty (`loyalty.card`). Point balances synced to/from a configured Odoo loyalty program. Custom find-or-create pattern for loyalty cards (search by partner_id + program_id). Model detection probes `loyalty.program`. Settings: sync/pull toggles + program ID. Clear limitation notices (balance-only sync, no transaction history). 44 new tests
- **Translation UI (Phase 5)** — Language detection panel in WooCommerce module settings: "Detect languages" button probes WPML/Polylang active languages against Odoo `res.lang`, shows per-language availability indicators, and provides per-language toggles for selective translation pull
- **Translation_Service::detect_languages()** — New public method returning structured language availability data (plugin name, default language, per-language Odoo locale availability). Odoo `res.lang` probe cached via 1-hour transient
- **Admin AJAX** — `wp4odoo_detect_languages` endpoint and `languages` field type in module settings save handler
- **pull_translations_batch()** — Optional `$enabled_languages` parameter to filter which languages are pulled from Odoo
- **Taxonomy term translations (Phase 6)** — Product categories (`product_cat`) and attribute values (`pa_*`) are now translated during pull from Odoo. Accumulate-and-flush pattern extended to three entity types: products, categories, attribute values
- **Translation_Adapter** — `get_term_translations()` and `create_term_translation()` implemented in both WPML_Adapter (filter API with `tax_*` element types) and Polylang_Adapter (function API)
- **Translation_Service::pull_term_translations_batch()** — Pulls translated term names from Odoo and applies to WPML/Polylang translated terms. Reuses `read_translated_batch()` for efficient per-language batch reads
- **Product category pull** — `Product_Handler` resolves Odoo `categ_id` Many2one to WP `product_cat` terms during product pull. WooCommerce_Module now maps `categ_id` and declares `product.category` Odoo model
- **Attribute value entity tracking** — `Variant_Handler` saves entity_map entries for attribute values after parent attribute creation, enabling translation flush by taxonomy
- **WP Job Manager module** — New bidirectional sync module for WP Job Manager (100k+ installs). Syncs `job_listing` CPT ↔ Odoo `hr.job` (job positions). Status mapping: `publish` ↔ `recruit`, `expired`/`filled` ↔ `open`. Pull support: department → `job_listing_category` taxonomy. Filterable status hooks. 61 new tests
- **Odoo_Model enum** — Added `HrJob` (`hr.job`) and `HrDepartment` (`hr.department`) cases for the HR domain
- **Backup warning** — Persistent admin banner on plugin settings page reminding users to back up WordPress + Odoo databases before sync. JS confirmation dialog before bulk import/export and retry operations. WP-CLI warning before `sync run`. Translated (FR, ES)
- **ACF meta-module** — Advanced Custom Fields mapping module: maps ACF custom fields to Odoo custom fields (`x_*`). Filter-based enrichment architecture — hooks into existing modules' push/pull pipelines without owning entity types. Admin UI with repeatable-row mapping configurator (target module, entity type, ACF field, Odoo field, type). 9 type conversions (text, number, integer, boolean, date, datetime, html, select, binary). CRM contacts use ACF user context (`user_{ID}`). 82 new tests
- **Module_Base infrastructure** — `_wp_entity_id` injected into `$wp_data` in `push_to_odoo()` for filter consumers. New `wp4odoo_after_save_{module}_{entity}` action fired after `save_wp_data()` in `pull_from_odoo()` for meta-module post-save writes
- **WC Product Bundles / Composite Products → Odoo Manufacturing BOM module** — Push-only sync of WC bundles and composite products as Manufacturing BOMs (`mrp.bom`) with One2many `bom_line_ids`. Cross-module entity_map lookup for product resolution, transient retry for unmapped products, configurable BOM type (phantom kit / normal manufacture)
- **Odoo_Model enum** — Added `MrpBom` (`mrp.bom`) and `MrpBomLine` (`mrp.bom.line`) cases
- **Health endpoint** — `GET /wp-json/wp4odoo/v1/health` returns system status (queue depth, failed count, circuit breaker state, module counts, version, timestamp). Protected by webhook token authentication. Status: `healthy` / `degraded` (CB open or 100+ failed jobs)
- **Batch API pipeline** — `Sync_Engine` now groups `wp_to_odoo` create jobs by module+entity_type and processes groups of 2+ via `push_batch_creates()`, which calls `Odoo_Client::create_batch()` in a single RPC call. Falls back to individual creates on batch error
- **Idempotent creates** — `Module_Base::push_to_odoo()` now calls `get_dedup_domain()` before creating: if a matching record exists in Odoo, it switches to update (prevents duplicates from retried creates). CRM dedup by email, WooCommerce dedup by SKU (`default_code`)
- **Translation accumulator** — Generic pull translation infrastructure in `Module_Base`: `get_translatable_fields()`, `accumulate_pull_translation()`, `flush_pull_translations()`. Sync_Engine flushes translations at end of each batch. Any module can override `get_translatable_fields()` to enable automatic translation support
- **Module_Registry::get_booted_count()** — New public method returning count of booted modules (used by health endpoint)

### Fixed
- **Retryable_Http** — HTTP 429 (Too Many Requests) responses now trigger retry with backoff, same as 5xx errors (was previously treated as a non-retryable client error)
- **Partner_Service** — `get_or_create()` now returns `null` (letting Sync_Engine retry) when the advisory lock cannot be acquired, instead of proceeding without lock protection (race condition risk)
- **Circuit_Breaker** — Stale DB fallback state (older than 1 hour) is now cleaned up automatically in `is_available()`, preventing a forever-open circuit if `record_success()` was never called
- **Entity_Map_Repository** — LRU cache eviction now removes orphaned bidirectional entries: when `wp:X → Y` is evicted but `odoo:Y → X` survives (or vice versa), the orphan is cleaned up to prevent stale lookups
- **Failure_Notifier** — `last_failure_email` timestamp is now saved BEFORE calling `wp_mail()` (was after), narrowing the race window where concurrent processes could both send duplicate notification emails
- **Odoo_Accounting_Formatter** — New `auto_post()` static method unifies the auto-post/auto-validate logic (donation.donation → `validate`, account.move → `action_post`). `Module_Helpers::auto_post_invoice()` and `Dual_Accounting_Module_Base::auto_validate()` now delegate to this single implementation
- **Polling queries** — `Entity_Map_Repository::get_module_entity_mappings()`, `Bookly_Handler::get_all_services()`, and `Bookly_Handler::get_active_bookings()` now include `LIMIT 50000` safety bounds to prevent unbounded result sets on high-volume sites
- **Webhook_Handler** — `Queue_Manager::pull()` now wrapped in try/catch: on exception, logs CRITICAL, fires `wp4odoo_webhook_enqueue_failed` action, returns 503 (was uncaught → 500 with lost payload)
- **Odoo_Client::is_session_error()** — Replaced broad `str_contains($message, '403')` with word-boundary regex `/\bhttp\s*403\b|\b403\s*forbidden\b/` to prevent false positives (e.g. "Product #1403"). Added `$e->getCode() === 403` check first
- **Database_Migration::migration_5()** — Index replacement now uses atomic `ALTER TABLE ... DROP KEY, ADD KEY` (single statement). Previously dropped index before adding replacement, risking index loss on failure
- **Circuit_Breaker** — State now persisted to `wp_options` (DB fallback) so the circuit stays open during Odoo outages even when object cache (Redis/Memcached) is flushed. `PROBE_TTL` increased from 60 s to 120 s to prevent concurrent probe batches
- **Circuit_Breaker::record_failure()** — MySQL advisory lock (`GET_LOCK('wp4odoo_cb_failure')`) around failure counting prevents lost increments under concurrent queue workers
- **Module_Base::push_to_odoo()** — Mapping save failure on update path now returns `Sync_Result::failure(Transient)` (was silent — asymmetric with create path)
- **Partner_Service::get_or_create()** — MySQL advisory lock (`GET_LOCK`) around search+create prevents duplicate partner creation under concurrent queue workers
- **Sync_Engine / Sync_Queue_Repository** — Processing start time recorded in `processed_at` when job status → `processing`; stale job recovery now uses `processed_at` (not `created_at`) for accurate timeout detection
- **Sync_Engine** — Early memory check (`is_memory_exhausted()`) now runs before `fetch_pending()`, preventing OOM when loading a large batch into memory
- **Sync_Queue_Repository::enqueue()** — Dedup WHERE clause includes both `wp_id` AND `odoo_id` when both are known (was exclusive OR, allowing near-duplicates)
- **Odoo_Client** — Timeout fallback uses `??` instead of `?:` (0 is a valid timeout value, not falsy)
- **Odoo_Client** — Automatic re-authentication on session errors (HTTP 403, expired session, access denied): detects session-related exceptions, resets transport, re-authenticates, and retries the call once
- **Booking_Module_Base** — Replaced `?:` (elvis) operator with explicit `!empty()` check for service name fallback — `?:` would incorrectly fall through on `'0'`
- **WP All Import hooks** — Added `is_importing()` anti-loop guard to `on_post_saved()` preventing re-enqueue during Odoo pull
- **Image_Handler** — Added `MAX_IMAGE_BYTES` (10 MB) size limit before `base64_decode()` to prevent OOM on oversized Odoo image fields

### Changed
- **Module_Registry** — `register_all()` refactored from 40+ individual if/new/register blocks to declarative `$module_defs` array: `[id, class, detection_callback]`. Detection callbacks are closures (or null for always-available modules). Reduces ~120 lines to ~40 lines, error-proof
- **Circuit_Breaker::record_batch()** — Docblock now explicitly documents the design decision: operates at batch level (80% threshold detects systemic Odoo failures), not individual job level (handled by Sync_Engine retry logic)
- **Settings_Repository** — Instance-level cache (`$cache`) for `get_connection()`, `get_sync_settings()`, `get_log_settings()` — avoids repeated `get_option()` calls within the same request. Cache invalidated on save
- **Odoo_Accounting_Formatter** — New `wp4odoo_invoice_line_data` filter on `for_account_move()` invoice lines, allowing injection of `tax_ids`, analytic accounts, or other Odoo fields
- **Admin_Ajax** — SSRF protection: URL fields now validated against private/reserved IP ranges (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`) with DNS resolution before validation
- **LMS_Helpers trait** — Extracted shared enrollment loading logic (decode synthetic ID → load enrollment → resolve partner → resolve course product → format sale order) from LearnDash and LifterLMS modules into `trait-lms-helpers.php`. Both modules now delegate via callable parameters instead of duplicating 33 lines each
- **WooCommerce module** — `sync_translations` setting migrated from boolean to array of language codes (backward compatible). UI replaced from checkbox to interactive language detection panel
- **WC_Pull_Coordinator** — `flush_translations()` extended to flush category and attribute value translations alongside product translations. New accumulators: `pulled_categories`, `pulled_attribute_values`
- **Module_Base** — `$entity_map` and `$settings_repo` changed from `private` to `protected`, allowing subclass access (WP All Import module no longer needs redundant refs)
- **Entity_Map_Repository** — Cache eviction upgraded from FIFO to true LRU: `get_odoo_id()` and `get_wp_id()` move accessed entries to end of array on hit
- **Database_Migration** — New migration 5: composite index `idx_wp_lookup (module, entity_type, wp_id)` replaces `(entity_type, wp_id)` on entity_map; added `idx_status_created (status, created_at)` on sync_queue for cleanup queries
- **WooCommerce_Hooks** — Callback-level settings checks: `on_product_save()` checks `sync_products`, `on_new_order()`/`on_order_status_changed()` check `sync_orders` before enqueuing
- **Job_Manager_Handler** — Status mapping refactored to use `Status_Mapper::resolve()` for consistency with all other handlers
- **WPAI_Hooks / WooCommerce_Hooks** — Added debug logging for skipped records (unrouted post types, disabled modules, disabled sync settings)

## [3.0.0] - 2026-02-13

### Changed
- **Sync_Result** — `entity_id` is now `?int` (nullable) instead of `int` with `0` default. `success()` factory defaults to `null`, `failure()` passes `null`. Clearer semantics: `null` = no entity, vs `0` which was ambiguous
- **Webhook_Handler** — Rate limit reduced from 100 to 20 requests per IP per 60-second window, matching realistic Odoo webhook throughput
- **Field_Mapper::format_price()** — Replaced `(float) number_format()` with `round()` to avoid string→float conversion artifacts (e.g. `1234.5600` locale issues)
- **Module_Helpers::auto_post_invoice()** — Returns `bool` instead of `void`, allowing callers to detect auto-posting failures
- **Entity_Map_Repository** — Added LRU cache eviction (`MAX_CACHE_SIZE = 2000`); drops oldest half when exceeded, preventing unbounded memory growth during large batch operations
- **Sync_Queue_Repository::recover_stale_processing()** — Accepts configurable `$timeout_seconds` parameter (was hardcoded 600s). New `stale_timeout` sync setting (60–3600 s, default 600 s) with `Settings_Repository::get_stale_timeout()` accessor
- **Sync_Queue_Repository::get_stats()** — Cached via 5-minute transient (`wp4odoo_queue_stats`), split completed count into separate indexed query
- **Sync_Queue_Repository::get_health_metrics()** — Cached via 5-minute transient (`wp4odoo_queue_health`)
- **Database_Migration::run_migrations()** — Wrapped migration callbacks in try/catch; stops on first failure without incrementing schema version, preventing partial upgrades
- **Query_Service** — `get_queue_jobs()` and `get_log_entries()` now use explicit column projection, excluding LONGTEXT fields (`payload`, `context`) to reduce memory on paginated admin pages
- **Sync_Engine** — Constructor accepts optional `?Logger $logger` parameter (4th arg) for dependency injection in tests. Batch loop now checks memory usage against 80% of `memory_limit` (`MEMORY_THRESHOLD`), stopping gracefully before OOM
- **EDD_Module** — Handler initialization (`Partner_Service`, `EDD_Download_Handler`, `EDD_Order_Handler`) moved from `boot()` to `__construct()`, preventing null reference if Sync_Engine processes residual queue jobs on a non-booted module
- **Order_Handler / EDD_Order_Handler** — Migrated 3 remaining status mapping methods to `Status_Mapper::resolve()` with filterable hooks; 100% of status maps now use the centralized pattern
- **Bookly_Cron_Hooks / Ecwid_Cron_Hooks** — Deletion detection in polling loops uses `array_flip()` + `isset()` instead of `in_array()`, reducing O(n²) to O(n)
- **Ecwid_Handler** — `fetch_api()` uses `array_push(...$items)` instead of `array_merge()` in pagination loop, avoiding repeated full-array copies
- **Exchange_Rate_Service** — `fetch_rates()` uses a transient-based mutex (`LOCK_KEY`, 30 s TTL) to prevent cache stampede when multiple concurrent processes detect a cache miss

### Fixed
- **Failure_Notifier** — `wp_mail()` return value is now checked; cooldown timestamp is only saved on successful email delivery (previously, a failed send would suppress the next notification attempt)
- **Odoo_Auth** — Logs a warning when API key decryption fails, aiding diagnosis of encryption key rotation issues (was silent failure)

### Added
- **Pricelist price sync** — Pull computed prices from Odoo pricelists (`product.pricelist`) and apply as WooCommerce sale prices; transient-cached (5min), currency guard integration, pricelist tracking meta
- **Shipment tracking sync** — Pull completed shipments (`stock.picking`) from Odoo into WooCommerce order meta (AST-compatible format); SHA-256 hash change detection, HPOS compatible
- 3 new `Odoo_Model` enum cases: `ProductPricelist`, `ProductPricelistItem`, `StockPicking`
- **Database migration 4** — `idx_processed_status (status, processed_at)` index on `wp4odoo_sync_queue` for efficient health metrics queries
- **WP-Cron log cleanup** — Daily `wp4odoo_log_cleanup` event triggers `Logger::cleanup()` (respects `retention_days` setting), replacing the previous in-sync-run cleanup that added latency to every cron tick
- **`wp_convert_hr_to_bytes()`** stub for unit tests (memory limit parsing)
- **Transactional gap fix (P0)** — `push_to_odoo()` now checks `save_mapping()` return value after Odoo `create()`. On failure, returns `Sync_Result::failure()` carrying the created `odoo_id`, which is persisted to the queue job for retry (prevents duplicate Odoo records)
- **Schema validation (P1)** — New `Schema_Cache` class caches Odoo `fields_get()` results per model (memory + 24h transient). `map_to_odoo()` warns about mapped fields not found in model schema (non-blocking)
- **HMAC webhook signature (P3)** — Optional `X-Odoo-Signature` header for HMAC-SHA256 payload integrity verification. Backward-compatible: absent header uses token-only auth
- **Reconciliation CLI (P2)** — New `wp wp4odoo reconcile <module> <entity_type> [--fix]` command detects orphaned entity_map entries (mapped to deleted Odoo records) and optionally removes them
- **WC_Pull_Coordinator (P4)** — Extracted ~215 lines of pull orchestration from WooCommerce_Module into dedicated `WC_Pull_Coordinator` class (variant dispatch, shipment dispatch, post-pull hooks)
- **CPT_Helper** — 2 new shared static methods: `parse_service_product()` (Odoo → WP service product parsing) and `save_from_odoo()` (CPT insert/update with optional meta writes). Used by LearnDash and LifterLMS handlers
- **Module_Base::handle_cpt_save()** — New protected method encapsulating the 5 standard guard clauses (anti-loop, revision, autosave, post_type, settings) before `enqueue_push()`. Replaces ~300 lines of duplicated boilerplate across 12 hooks trait methods
- **LMS handler DRY** — LearnDash_Handler and LifterLMS_Handler parse/save methods (8 total) now delegate to `CPT_Helper::parse_service_product()` and `CPT_Helper::save_from_odoo()`
- **WC Bookings Module** — WooCommerce Bookings ↔ Odoo bidirectional sync: booking products to `product.product` (bidirectional), individual bookings to `calendar.event` (push-only). All-day support, persons count in description, status filtering (confirmed/paid/complete), booking product type filter, service auto-sync before booking push, partner resolution via user or order billing. Independent module (coexists with WooCommerce). `WC_Bookings_Handler`, `WC_Bookings_Hooks` trait
- **Translation Service (i18n)** — Cross-cutting WPML/Polylang integration for multilingual product sync. `Translation_Adapter` interface with `WPML_Adapter` (filter API) and `Polylang_Adapter` (function API). `Translation_Service` provides adapter detection (WPML first, then Polylang), WP→Odoo locale mapping (34 locales, filterable via `wp4odoo_odoo_locale`), Odoo version detection (`ir.translation` for Odoo 14-15 vs `context={'lang'}` for Odoo 16+), and dual-path translation push. Accessible from any module via `$this->translation_service()` in `Module_Helpers`
- **Odoo_Client context support** — `create()`, `write()`, and `read()` accept an optional `$context` array parameter, passed as kwargs to Odoo RPC. Used by `Translation_Service` for language-aware writes (`context={'lang': 'fr_FR'}`)
- **WooCommerce multilingual push** — Detects WPML/Polylang translations on product save, enqueues translated field values (name, description) as separate queue jobs with `_translate` payload flag. Translated posts push to the original product's Odoo record with language context instead of creating separate records
- **Translation pull (Odoo → WP)** — Batch-reads translated field values from Odoo when products are pulled, creating/updating WPML or Polylang translated WP posts. One Odoo `read()` call per language for the entire batch (N calls for N languages, not N×P). `Translation_Adapter` write methods (`create_translation()`, `set_post_language()`, `link_translations()`), `Translation_Service::pull_translations_batch()`, accumulate-and-flush pattern in `WC_Pull_Coordinator`, `wp4odoo_batch_processed` action in `Sync_Engine`, `sync_translations` WooCommerce setting. Extensible via `wp4odoo_translatable_fields_woocommerce` filter
- 200 new/updated unit tests (2042 total, 3185 assertions): WC Bookings module + handler, pricelist handler, shipment handler, transient cache isolation, nullable entity_id, memory threshold, stale timeout configuration, schema cache, reconciler, HMAC signature, pull coordinator (+ flush/accumulator), WPML adapter (16 tests), Polylang adapter (16 tests), Translation_Service (24 tests), Odoo_Client context (7 tests)

## [2.9.5] - 2026-02-12

### Changed
- **Query_Service** — Replaced deprecated `SQL_CALC_FOUND_ROWS` / `FOUND_ROWS()` with separate `COUNT(*)` + `SELECT` queries for MySQL 8.0+ / MariaDB 10.5+ compatibility
- **Webhook_Handler rate limiter** — Switched from `get_transient()` / `set_transient()` to `wp_cache_get()` / `wp_cache_set()` with a non-persistent cache group, avoiding a database write on every webhook request when no persistent object cache is configured
- **Module_Test_Case** — Added centralized `reset_static_caches()` method calling `Logger::reset_cache()` and `Odoo_Auth::flush_credentials_cache()`. Non-module tests (OdooAuthTest, CLITest, AdminAjaxTest, LoggerTest) now delegate to this single method instead of duplicating individual flush calls
- **ARCHITECTURE.md** — Documented `handler_*()` vs `get_*()` abstract method naming convention in intermediate base classes
- **Circuit_Breaker** — Replaced TOCTOU-prone `get_transient()` + `set_transient()` probe mutex with atomic MySQL advisory lock (`GET_LOCK`) and double-checked locking pattern, preventing duplicate probe batches during recovery
- **Entity_Map_Repository** — `get_wp_ids_batch()` and `get_odoo_ids_batch()` now deduplicate input IDs and serve cache hits before querying the database, reducing unnecessary SQL queries during batch operations
- **Failure_Notifier** — Threshold and cooldown are now configurable via `failure_threshold` and `failure_cooldown` in sync settings (defaults: 5 failures, 3600 s cooldown), replacing hardcoded constants
- **Events_Calendar_Hooks** — Removed unnecessary `\` global function prefix for consistency with all other hook traits
- **Retryable_Http** — Clarified docblock to explicitly state no internal retry
- **CI** — Added Composer dependency caching (`actions/cache@v4`) to `test` and `coverage` jobs; Codecov `fail_ci_if_error` set to `true`
- **Status mapping DRY** — Extracted repeated 2-line status mapping pattern (`apply_filters` + array lookup + default) into centralized `Status_Mapper::resolve()`, used by 20 methods across 10 handler files
- **Ecwid_Handler** — `fetch_api()` now paginates Ecwid REST API responses with `offset`/`limit` parameters, safety-capped at 50 pages × 100 items (was single-page fetch limited to first 100 items)
- **Bookly_Cron_Hooks / Ecwid_Cron_Hooks** — WP-Cron `poll()` methods now acquire a MySQL advisory lock (`GET_LOCK`) with try/finally release, preventing concurrent cron executions from double-processing data
- **Sync_Engine backoff** — Added random jitter (0–60 s) to exponential backoff delay, preventing thundering herd when multiple jobs retry simultaneously
- **Circuit_Breaker** — Switched from binary all-or-nothing to ratio-based threshold (80% failure rate). New `record_batch(successes, failures)` method replaces direct `record_success()`/`record_failure()` calls from Sync_Engine, detecting partial degradation (e.g. 1 success + 20 failures no longer resets the counter)
- **Sync_Queue_Repository** — `enqueue()` accepts explicit `$in_transaction` parameter instead of querying `@@in_transaction` MySQL variable, improving portability and testability
- **Sync_Queue_Repository** — Payload size validation: `enqueue()` rejects payloads exceeding 1 MB after JSON encoding, preventing oversized queue entries

### Added
- **Odoo_Model** — New string-backed enum (`includes/class-odoo-model.php`) listing all 14 Odoo models used by WP4Odoo, replacing hardcoded model name strings in model probes, dual-model detection, and accounting comparisons
- **Architecture diagrams** — New `architecture-synth.svg` (grouped by category, used in README) and `architecture-full.svg` (all 24 individual modules, used in ARCHITECTURE.md) replacing the incomplete `architecture-v2.svg`
- `wp_cache_get()` / `wp_cache_set()` / `wp_cache_delete()` stubs for unit tests
- MySQL 8.0+ / MariaDB 10.5+ added to README.md requirements
- **Database migration 3** — `idx_dedup_composite (module, entity_type, direction, status)` index on `wp4odoo_sync_queue` for reliable InnoDB gap locking during dedup `SELECT … FOR UPDATE`
- **Module_Base** — `flush_mapping_cache()` method to invalidate the in-memory field mapping cache after bulk operations or runtime mapping changes
- **Module_Base** — `enqueue_push()` protected helper method for the common 3-line enqueue pattern (get mapping → determine action → `Queue_Manager::push`)
- **Status_Mapper** — New shared utility class (`includes/modules/class-status-mapper.php`) centralizing filterable status mapping with `resolve(status, map, hook, default)`
- **Settings_Repository** — `get_failure_threshold()` and `get_failure_cooldown()` typed accessors
- 30 new unit tests (1853 total, 2893 assertions): circuit breaker probe mutex atomicity + ratio-based `record_batch()`, entity map batch dedup/cache, configurable failure thresholds, `Odoo_Model` enum values and `tryFrom()`, payload size validation, savepoint `$in_transaction` parameter

### Fixed
- **uninstall.php** — Added cleanup of `_transient_wp4odoo_%` and `_transient_timeout_wp4odoo_%` rows; added missing `wp4odoo_ecwid_poll` cron unhook

## [2.9.0] - 2026-02-12

### Changed
- **Amelia Module** — Now bidirectional: services support pull from Odoo (create/update/delete). Appointments remain push-only (originate in WordPress). `Amelia_Handler` gains `parse_service_from_odoo()`, `save_service()`, `delete_service()` methods. New setting: `pull_services`
- **Bookly Module** — Now bidirectional: services support pull from Odoo (create/update/delete). Bookings remain push-only (originate in WordPress). `Bookly_Handler` gains `parse_service_from_odoo()`, `save_service()`, `delete_service()` methods. New setting: `pull_services`
- **Booking_Module_Base** — Extended with pull infrastructure: 3 new abstract methods (`handler_parse_service_from_odoo()`, `handler_save_service()`, `handler_delete_service()`), `pull_from_odoo()` override with `pull_services` gate, `map_from_odoo()`, `save_wp_data()`, `delete_wp_data()` overrides. Sync direction changed from `wp_to_odoo` to `bidirectional`. Reduced abstract methods from 14 to 9 by consolidating 7 data extractors into `handler_extract_booking_fields()`
- **WC Memberships Module** — Now bidirectional: plans support full pull (create/update/delete), memberships support status/date updates from Odoo (no create/delete — memberships originate in WooCommerce). `Membership_Handler` gains reverse status map, `map_odoo_status_to_wc()`, `parse_plan_from_odoo()`, `save_plan()`, `parse_membership_from_odoo()`, `save_membership_from_odoo()` methods. New settings: `pull_plans`, `pull_memberships`
- **Anti-loop flag** — `Module_Base::$importing` changed from global `static bool` to per-module `static array` keyed by module ID, preventing cross-module interference when processing parallel sync operations
- **Retryable_Http** — Removed blocking `usleep()` retry loop; single HTTP attempt with immediate throw on error. Retry orchestration delegated to `Sync_Engine` at queue level (exponential backoff via `scheduled_at`)
- **SSL verification** — Replaced `apply_filters('wp4odoo_ssl_verify')` with `WP4ODOO_DISABLE_SSL_VERIFY` constant in both JSON-RPC and XML-RPC transports, preventing user-land code from silently disabling SSL
- **JSON-RPC request ID** — Replaced sequential counter with `bin2hex(random_bytes(8))` for multi-process-safe unique request IDs
- **Sync_Engine validation** — Added `json_last_error()` payload validation; invalid sync direction now throws `RuntimeException` instead of silently falling through to pull
- **Query_Service** — Replaced double COUNT+SELECT with `SQL_CALC_FOUND_ROWS` + `FOUND_ROWS()` for paginated queries, halving database round-trips
- **Partner_Service** — `get_or_create_batch()` now uses single `get_odoo_ids_batch()` query instead of N individual `get_odoo_id()` calls, eliminating N+1 entity map lookups. Constructor now accepts optional `Settings_Repository` for log level filtering
- **Dual_Accounting_Module_Base** — Moved `get_donor_name()` to handler delegation pattern (`handler_get_donor_name()`), keeping data extraction in handler classes
- **Module_Helpers** — Added `resolve_partner_from_user()` and `resolve_partner_from_email()` helpers, reducing ~15 duplicate partner resolution patterns across 11 modules to single-line calls
- **i18n** — Regenerated `.pot` template from all source files (328 → 443 msgid), translated 3 new strings in FR and ES, removed 65 obsolete entries, recompiled `.mo` — 442 translated strings (was 417)

### Added
- 35 new unit tests for Amelia, Bookly, and WC Memberships pull support (1823 total)
- **Credential caching** — `Odoo_Auth::get_credentials()` now caches decrypted credentials per-request, with `flush_credentials_cache()` for invalidation after saves
- **Database migration 2** — `idx_status_attempts` and `idx_created_at` indexes on `wp4odoo_sync_queue` for faster retry and cleanup queries

### Fixed
- **Webhook test endpoint** — `/webhook/test` now requires token authentication (was open with `__return_true`)
- **uninstall.php** — Options cleanup now uses `$wpdb->prepare()` for the DELETE query
- **Test bootstrap** — Fixed `WP4ODOO_VERSION` mismatch (`2.8.0` → `2.9.0`)

## [2.8.5] - 2026-02-12

### Changed
- **Events Calendar Module** — Now bidirectional: events and tickets support pull from Odoo (create/update/delete). Attendees remain push-only (originate in WordPress RSVP). `Events_Calendar_Handler` gains `parse_event_from_odoo()`, `save_event()`, `save_ticket()` methods. New settings: `pull_events`, `pull_tickets`
- **WC Subscriptions Module** — Now bidirectional: subscriptions support status pull from Odoo (update only — subscriptions originate in WooCommerce checkout). `WC_Subscriptions_Handler` gains reverse status/billing period maps, `parse_subscription_from_odoo()`, `save_subscription()` methods. New setting: `pull_subscriptions`
- **Dual-model detection refactored** — `has_odoo_model()` extracted into `Module_Helpers` trait, replacing 3 identical ~25-line implementations in `Events_Calendar_Module`, `WC_Subscriptions_Module`, and `Dual_Accounting_Module_Base`. Same behavior (in-memory cache + transient 1h + `ir.model` probe), single source of truth
- **LearnDash Module** — Now bidirectional: courses and groups support pull from Odoo (create/update/delete). Transactions and enrollments remain push-only. `LearnDash_Handler` gains `parse_course_from_odoo()`, `parse_group_from_odoo()`, `save_course()`, `save_group()` methods. New settings: `pull_courses`, `pull_groups`
- **LifterLMS Module** — Now bidirectional: courses and memberships support pull from Odoo (create/update/delete). Orders and enrollments remain push-only. `LifterLMS_Handler` gains `parse_course_from_odoo()`, `parse_membership_from_odoo()`, `save_course()`, `save_membership()`, `map_odoo_status_to_llms()` methods. New settings: `pull_courses`, `pull_memberships`
- **Sprout Invoices Module** — Now bidirectional: invoices and payments support pull from Odoo (create/update/delete). `Sprout_Invoices_Handler` gains `parse_invoice_from_odoo()`, `parse_payment_from_odoo()`, `save_invoice()`, `save_payment()`, `map_odoo_status_to_si()` methods. New settings: `pull_invoices`, `pull_payments`

### Added
- 93 new unit tests for Events Calendar, WC Subscriptions, LearnDash, LifterLMS, and Sprout Invoices pull support (1788 total)
- **`composer check` script** — Runs PHPCS, PHPUnit, and PHPStan (with cache clearing) in sequence, reproducing CI conditions locally to catch errors before push

## [2.8.0] - 2026-02-11

### Changed
- **Module_Helpers trait** — Extracted 9 helper methods from `Module_Base` into `Module_Helpers` trait: `auto_post_invoice()`, `ensure_entity_synced()`, `encode_synthetic_id()`, `decode_synthetic_id()`, `delete_wp_post()`, `log_unsupported_entity()`, `resolve_many2one_field()`, `partner_service()`, `check_dependency()`. Reduces `Module_Base` from 889 to 695 lines, keeping the base class focused on push/pull orchestration, entity mapping, and field mapping
- **Atomic queue deduplication** — `Sync_Queue_Repository::enqueue()` now wraps the check-then-insert pattern in a MySQL transaction with `SELECT … FOR UPDATE`, preventing concurrent hook fires from inserting duplicate pending jobs. Added `idx_dedup_wp` composite index for efficient gap locking. Uses `@@in_transaction` detection with SAVEPOINT fallback when already inside an outer transaction (e.g. WordPress test framework), avoiding implicit commits
- **Sync_Engine release_lock()** — Now uses `$wpdb->get_var()` to check the return value of `RELEASE_LOCK()` and logs a warning if the lock was not held or could not be released (e.g. database connection dropped during processing)
- **Entity_Map_Repository batch chunking** — `get_wp_ids_batch()` and `get_odoo_ids_batch()` now split large ID arrays into chunks of 500 via `array_chunk()`, preventing oversized SQL `IN` clauses that could exceed `max_allowed_packet`
- **Logger context truncation** — Context JSON is now truncated to 4KB (`MAX_CONTEXT_BYTES`) before insertion, preventing unbounded log table growth on high-volume sites with large context arrays
- **Handler initialization in __construct()** — `WooCommerce_Module` and `CRM_Module` now initialize all handlers (Product_Handler, Order_Handler, Contact_Manager, Lead_Manager, etc.) in `__construct()` instead of `boot()`, ensuring residual queue jobs on non-booted modules don't cause fatal errors
- **Circuit breaker probe mutex** — Half-open state now uses a `wp4odoo_cb_probe` transient mutex (60s TTL) to prevent multiple concurrent cron processes from all sending probe batches simultaneously during recovery
- **Queue sharding by module** — `Sync_Engine` now supports per-module advisory locks (`wp4odoo_sync_{module}`) via `process_module_queue()`, allowing parallel queue processing of different modules from separate WP-Cron workers. `LOCK_NAME` renamed to `LOCK_PREFIX`, `acquire_lock()` / `release_lock()` accept dynamic lock names
- **Debounce/coalescence** — `Queue_Manager::push()` now applies a 5-second debounce delay by default (configurable via `$debounce` parameter), setting a future `scheduled_at` so rapid-fire hook callbacks coalesce via the dedup mechanism. `pull()` remains immediate by default
- **Webhook idempotency keys** — `Webhook_Handler` now checks the `X-Odoo-Idempotency-Key` header first for deduplication, falling back to SHA-256 content hash when the header is absent. Enables server-side idempotency from Odoo automation rules
- **HTTP Connection keep-alive** — `Retryable_Http` trait now sets `Connection: keep-alive` header on all outgoing requests, enabling TCP connection reuse during batch processing and reducing latency on multi-call sequences

### Added
- **Schema migration system** — `Database_Migration` now tracks schema version via `wp4odoo_schema_version` option and runs numbered migrations on upgrade. `run_migrations()` iterates registered callbacks, applying only unapplied versions. Migration 1: adds `correlation_id CHAR(36)` columns and `idx_correlation` indexes to both `sync_queue` and `logs` tables
- **Correlation ID tracing** — End-to-end job tracing: `Sync_Queue_Repository::enqueue()` generates a UUID v4 `correlation_id` per job, `Logger` carries it via `set_correlation_id()` / `get_correlation_id()`, `Sync_Engine` propagates it during job processing. Enables filtering all log entries related to a single sync job
- **Queue health metrics** — `Sync_Queue_Repository::get_health_metrics()` returns real-time queue health: average processing latency (24h window), success rate (completed vs total), and pending job depth by module. Exposed via `Queue_Manager::get_health_metrics()` static wrapper
- **Batch RPC operations** — `Odoo_Client::create_batch()` creates multiple records in a single RPC call (Odoo's `create()` natively accepts a list of value dicts), `write_batch()` groups updates by identical value dicts and batches them. Reduces N round-trips to 1 for bulk operations
- **idx_dedup_odoo index** — New composite index `(module, entity_type, direction, status, odoo_id)` on `wp4odoo_sync_queue` for efficient deduplication queries filtering by `odoo_id` (mirrors the existing `idx_dedup_wp` for `wp_id`)
- **Encryption key rotation** — `Odoo_Auth::rotate_encryption_key()` allows re-encrypting stored API credentials after changing the encryption key material. Handles both sodium and OpenSSL paths
- **Backoff timing test** — New `test_backoff_delay_is_exponential()` in SyncEngineTest verifies exponential delay growth (2^n × 60s) across retry attempts
- **Module_Test_Case centralized reset** — `GLOBAL_STORES` constant and `reset_globals()` static method centralize global store initialization for all 23 test stores, eliminating copy-paste in individual test setUp() methods

## [2.7.5] - 2026-02-11

### Added
- **Sprout Invoices Module** — Sprout Invoices → Odoo push sync: invoices (`sa_invoice` CPT) as Odoo invoices (`account.move` with `invoice_line_ids` One2many tuples), payments (`sa_payment` CPT) as Odoo payments (`account.payment`). SI status mapping (temp/publish→draft, complete→posted, write-off→cancel), auto-posting for completed invoices, partner resolution via client→user chain, invoice auto-sync before payment push. Exclusive group `invoicing` (priority 10). `Sprout_Invoices_Handler`, `Sprout_Invoices_Hooks` trait, 46 new unit tests
- **WP-Invoice Module** — WP-Invoice → Odoo push sync: invoices (`wpi_object` CPT) as Odoo invoices (`account.move` with `invoice_line_ids` One2many tuples from `itemized_list`). WPI status mapping (active→draft, paid→posted, pending→draft), auto-posting for paid invoices, partner resolution from invoice user data. Exclusive group `invoicing` (priority 5, mutual exclusion with Sprout Invoices). `WP_Invoice_Handler`, `WP_Invoice_Hooks` trait, 32 new unit tests
- **WP Crowdfunding Module** — WP Crowdfunding → Odoo push sync: crowdfunding campaigns (WC products with `wpneo_*` meta) as service products (`product.product`). Funding goal as list price, structured description with funding info (goal, end date, min pledge). Crowdfunding detection via `_wpneo_funding_goal` meta key. Independent module (coexists with WooCommerce). `Crowdfunding_Handler`, `Crowdfunding_Hooks` trait, 22 new unit tests
- **Ecwid Module** — Ecwid → Odoo push sync via WP-Cron polling: products (`product.product`) and orders (`sale.order` with `order_line` One2many tuples) from Ecwid REST API. SHA-256 hash-based change detection (same pattern as Bookly), API credential settings (store ID + API token), partner resolution for orders via `Partner_Service`. Exclusive group `ecommerce` (priority 5). `Ecwid_Handler`, `Ecwid_Cron_Hooks` trait, 42 new unit tests
- **ShopWP Module** — ShopWP → Odoo push sync: Shopify products synced by ShopWP (`wps_products` CPT + `shopwp_variants` custom table) as products (`product.product`). Variant price/SKU from custom table, hook-based sync on `save_post_wps_products`. Exclusive group `ecommerce` (priority 5). `ShopWP_Handler`, `ShopWP_Hooks` trait, 25 new unit tests

## [2.7.0] - 2026-02-11

### Added
- **Events Calendar Module** — The Events Calendar + Event Tickets → Odoo push sync: events as Odoo events (`event.event`, auto-detected via `ir.model` probe) or calendar entries (`calendar.event` fallback), RSVP ticket types as service products (`product.product`), RSVP attendees as event registrations (`event.registration`, only with Odoo Events module). Dual-model detection with transient caching, event auto-sync before attendees, partner resolution via `Partner_Service`, independent module (coexists with all other modules). `Events_Calendar_Handler`, `Events_Calendar_Hooks` trait, 53 new unit tests
- **Circuit breaker** — `Circuit_Breaker` class pauses queue processing when Odoo is unreachable (3 consecutive all-fail batches → open, 5-min recovery delay → half-open probe). Transient-based state, integrated into `Sync_Engine::process_queue()`
- **Batch partner lookup** — `Partner_Service::get_or_create_batch()` resolves multiple emails in a single `search_read` RPC call, reducing N+1 to 1 when processing batches
- **Settings validation** — Defense-in-depth validation in `Settings_Repository` getters: protocol/direction/conflict_rule/interval/log_level enum enforcement, timeout/batch_size/retention_days clamping
- **Module_Test_Case** — Abstract test base class (`tests/helpers/Module_Test_Case.php`) with shared `setUp()` for `$wpdb` mock and global stores

### Changed
- **Membership module refactoring** — Extracted `Membership_Module_Base` abstract class from MemberPress, PMPro, and RCP modules. Shared `push_to_odoo()` orchestration (level auto-sync, invoice auto-posting), `load_wp_data()` dispatch with partner/level/price resolution. Each module now implements abstract methods for entity type names, handler delegation, and plugin-specific data extraction. ~370 lines deduplicated
- **Module_Base helpers** — Added `auto_post_invoice()` and `ensure_entity_synced()` protected methods to `Module_Base`, replacing 6 copies of `maybe_auto_post_invoice()` and ~8 copies of `ensure_*_synced()` across modules. Used by LearnDash, LifterLMS, WC Subscriptions, Booking_Module_Base, and Events_Calendar_Module
- **Synthetic ID helpers** — Added `Module_Base::encode_synthetic_id()` / `decode_synthetic_id()` static methods with `OverflowException` guard, replacing raw arithmetic (`user_id * 1_000_000 + course_id`) in LearnDash and LifterLMS modules
- **Setting key alignment** — Unified `auto_post_invoice` → `auto_post_invoices` in LearnDash and LifterLMS modules for consistency with all other modules
- **Dual_Accounting_Model inlining** — Merged `Dual_Accounting_Model` trait directly into `Dual_Accounting_Module_Base` (sole consumer), removing unnecessary indirection
- **Sync_Engine lock timeout** — Increased MySQL advisory lock timeout from 1s to 5s to reduce false positives on busy servers
- **Webhook X-Forwarded-For** — `get_client_ip()` now validates that the direct remote address is a private/reserved IP before trusting `X-Forwarded-For`, preventing spoofing on non-proxied setups

### Fixed
- **EDD on_download_save()** — Added missing `$settings['enabled']` guard to prevent sync attempts when the module is disabled
- **Amelia_Hooks trait docblock** — Corrected `@return` from `array` to `void` on `on_appointment_status_change()`
- **retry_failed() SQL injection** — Replaced string interpolation with `$wpdb->prepare()` for parameterized query

## [2.6.5] - 2026-02-11

### Added
- **WC Subscriptions Module** — WooCommerce Subscriptions → Odoo push sync: subscription products as service products (`product.product`), recurring subscriptions (`sale.subscription` on Odoo Enterprise 14-16, auto-detected via `ir.model` probe), renewal orders as invoices (`account.move` with optional auto-posting). Billing period mapping (day/week/month/year → Odoo recurring_rule_type), 7 subscription statuses → Odoo states, One2many line tuples, product auto-sync before dependent entities, independent module (coexists with WooCommerce module). `WC_Subscriptions_Handler`, `WC_Subscriptions_Hooks` trait, 67 new unit tests
- **RCP Module** — Restrict Content Pro → Odoo push sync: membership levels as products (`product.product`), payments as invoices (`account.move` with optional auto-posting), user memberships (`membership.membership_line`). Data access via RCP v3.0+ object classes (`RCP_Membership`, `RCP_Customer`, `RCP_Payments`). Exclusive group `memberships` (priority 12 — between MemberPress and PMPro). Level pricing: `recurring_amount` (recurring) or `initial_amount` (one-time). Payment filtering: only `complete` status synced. `RCP_Handler`, `RCP_Hooks` trait, 62 new unit tests
- **LifterLMS Module** — LifterLMS → Odoo push sync: courses and memberships as service products (`product.product`), orders as invoices (`account.move` with optional auto-posting), enrollments as sale orders (`sale.order`). CPT-based data access (`llms_course`, `llms_membership`, `llms_order`). Synthetic enrollment IDs (`user_id × 1M + course_id`), automatic parent product sync before dependent entities, partner resolution via `Partner_Service`. `LifterLMS_Handler`, `LifterLMS_Hooks` trait, 61 new unit tests
- **PMPro Module** — Paid Memberships Pro → Odoo push sync: membership levels as products (`product.product`), payment orders as invoices (`account.move` with optional auto-posting), user memberships (`membership.membership_line`). Data access via PMPro API functions and `$wpdb` on custom tables (no CPTs). Exclusive group `memberships` (priority 15 — between MemberPress and WC Memberships). Level pricing: `billing_amount` (recurring) or `initial_payment` (one-time). Order filtering: only `success`/`refunded` statuses synced. `PMPro_Handler`, `PMPro_Hooks` trait, 66 new unit tests
- **Forms Module** — Added support for 5 new form plugins: Contact Form 7, Fluent Forms, Formidable Forms, Ninja Forms, and Forminator. All 7 supported form plugins push submissions to Odoo `crm.lead` through the same pipeline. Per-plugin sync toggles, plugin-specific field extraction (CF7 tag basetypes, Fluent Forms key-based heuristics, Ninja Forms firstname/lastname concatenation), 51 new unit tests

## [2.6.0] - 2026-02-11

### Added
- **LearnDash Module** — LearnDash LMS → Odoo push sync: courses and groups as service products (`product.product`), transactions as invoices (`account.move` with optional auto-posting), enrollments as sale orders (`sale.order`). Synthetic enrollment IDs (`user_id × 1M + course_id`), automatic parent course sync before dependent entities, partner resolution via `Partner_Service`. `LearnDash_Handler`, `LearnDash_Hooks` trait, 69 new unit tests

## [2.5.0] - 2026-02-11

### Fixed
- **CRITICAL: Sync_Engine lock leak** — `process_queue()` now wraps batch processing in `try/finally` to guarantee MySQL advisory lock release even if `Failure_Notifier::check()` throws
- **5xx retry throws on exhaustion** — `Retryable_Http` now throws `RuntimeException` after all retries are exhausted on HTTP 5xx, instead of silently returning the error response (which could be misinterpreted as valid data)
- **Exponential backoff in Sync_Engine** — changed retry delay from linear (`attempts × 60s`) to exponential (`2^attempts × 60s`) for proper backoff behavior
- **Entity_Map_Repository atomic upsert** — replaced `REPLACE INTO` (DELETE + INSERT, resets AUTO_INCREMENT) with `INSERT ... ON DUPLICATE KEY UPDATE` for safe atomic upserts
- **Entity_Map_Repository cache invalidation** — `remove()` now falls back to a DB lookup when the forward cache is empty, ensuring the reverse cache entry is also cleared
- **XmlRPC kwargs cast** — added `(object)` cast to `$kwargs` in `execute_kw()` to match JsonRPC behavior and prevent empty-array serialization issues
- **generate_sync_hash() defensive check** — handles `wp_json_encode()` returning `false` gracefully by falling back to `serialize()`
- **Database index alignment** — changed `idx_odoo_lookup` from `(odoo_model, odoo_id)` to `(module, entity_type, odoo_id)` to match actual query patterns
- **Cron reschedule on settings change** — sync cron is now cleared and rescheduled when the sync interval setting changes in admin

### Added
- **Stale job recovery** — `Sync_Queue_Repository::recover_stale_processing()` resets jobs stuck in `processing` state for more than 10 minutes, called automatically at the start of each queue run
- **Autosave/revision guards** — added `wp_is_post_revision()` / `wp_is_post_autosave()` early returns to 5 hook traits: GiveWP, Charitable, SimplePay, WPRM, MemberPress
- **Log filter dropdown** — added 11 missing module options (system, edd, memberships, memberpress, givewp, charitable, simplepay, wprm, forms, amelia, bookly)
- **Memberships plan type** — membership plans now include `'type' => 'service'` to match MemberPress product type

### Removed
- **Error_Type::Config** — dead enum case merged into `Permanent` (was never used in practice)

## [2.4.0] - 2026-02-11

### Changed
- **Module_Base constructor**: `$id` and `$name` are now explicit constructor parameters instead of relying on property initializer ordering — impossible to forget, enforced by PHP
- **Transport base class**: extracted `Odoo_Transport_Base` abstract class from `Odoo_JsonRPC` and `Odoo_XmlRPC` — eliminates ~40 lines of duplicated properties, constructor, `get_uid()`, and authentication guard
- **Retryable_Http**: now retries on HTTP 5xx server errors (502, 503, etc.) in addition to WP_Error network failures — improves resilience against Odoo proxy/nginx transient errors
- **Retryable_Http visibility**: `http_post_with_retry()` changed from `private` to `protected` (required by Transport base class inheritance)
- **Bookly_Poller → Bookly_Cron_Hooks**: renamed trait for consistency with all other `*_Hooks` traits

### Added
- `Odoo_Transport_Base` abstract class (`includes/api/class-odoo-transport-base.php`) — shared base for JSON-RPC and XML-RPC transports
- `Odoo_Client::reset()` — clears connection state, allowing credential changes to take effect without creating a new instance (useful in WP-CLI and tests)
- **Automatic log cleanup** in WP-Cron: `Logger::cleanup()` runs every sync cycle (lightweight indexed DELETE based on retention settings)
- **Bulk operation timeout**: `Bulk_Handler` now enforces a 50-second time limit on import/export loops — prevents PHP max_execution_time fatal errors on large catalogs, with resumable messaging
- **Encryption key hint**: connection settings page now shows a note about `WP4ODOO_ENCRYPTION_KEY` constant for multi-environment and staging setups

## [2.3.0] - 2026-02-11

### Changed
- **Autoloading**: replaced manual `Dependency_Loader` (70+ `require_once`) with `spl_autoload_register` — converts `WP4Odoo\Foo_Bar` to `includes/class-foo-bar.php` with class-/trait-/interface- prefix fallback. Deleted `class-dependency-loader.php`
- **Sync_Result value object**: `push_to_odoo()` and `pull_from_odoo()` now return `Sync_Result` instead of `bool` — carries success/failure status, entity ID, error message, and error classification
- **Error_Type enum**: `Transient` (retry with backoff), `Permanent` (fail immediately), `Config` (alert admin) — Sync_Engine now uses smart retry based on error type instead of retrying all failures blindly
- **Query_Service injectable**: converted from static methods to instance methods with constructor injection in Admin_Ajax, Settings_Page, and CLI
- **Anti-loop flag**: replaced permanent `define('WP4ODOO_IMPORTING')` constant with resettable `static bool $importing` property on `Module_Base` — `pull_from_odoo()` uses `try/finally` to always clear the flag, enabling correct behavior in WP-CLI and webhook batch processing

### Added
- **WP-Cron reliability detection**: records last cron run timestamp via `Settings_Repository::touch_cron_run()`, warns on plugin settings page when cron hasn't fired in 3× the configured interval, with specific message for `DISABLE_WP_CRON` sites
- `Error_Type` backed enum (`includes/class-error-type.php`)
- `Sync_Result` value object (`includes/class-sync-result.php`)
- 11 new tests (1050 total, 1698 assertions): cron health (6), anti-loop flag (4), Query_Service instance (1)

## [2.2.0] - 2026-02-11

### Refactoring
- **Module_Base shared helpers**: `partner_service()` — lazy `Partner_Service` factory (was duplicated in 5 modules via trait + inline); `check_dependency()` — one-liner dependency status helper (was 10+ lines of boilerplate in every module's `get_dependency_status()`)
- **Dual_Accounting_Module_Base**: new abstract base class (`includes/modules/class-dual-accounting-module-base.php`) for GiveWP, Charitable, and SimplePay modules — extracts shared `push_to_odoo()` (model resolution + parent sync + auto-validate), `map_to_odoo()`, `load_wp_data()`, and child data loading (CPT validation, email→partner, parent→Odoo product resolution). 10 abstract methods for subclass configuration. Each module reduced from ~300 to ~80 lines
- **Booking_Module_Base**: new abstract base class (`includes/modules/class-booking-module-base.php`) for Amelia and Bookly modules — extracts shared `push_to_odoo()` (service auto-sync), `map_to_odoo()`, `load_wp_data()`, booking data loading (service name, customer→partner, event naming "Service — Customer"), and `ensure_service_synced()`. 11 abstract methods for subclass configuration. Each module reduced from ~340 to ~90 lines

## [2.0.0] - 2026-02-10

### Added

#### EDD Module — Easy Digital Downloads ↔ Odoo Bidirectional Sync
- New module: `EDD_Module` (`includes/modules/class-edd-module.php`) — bidirectional sync between Easy Digital Downloads 3.0+ and Odoo (downloads, orders, invoices)
- `EDD_Download_Handler` (`includes/modules/class-edd-download-handler.php`) — load/save/delete for EDD downloads (`download` post type), maps to Odoo `product.template`
- `EDD_Order_Handler` (`includes/modules/class-edd-order-handler.php`) — load/save for EDD orders (`edd_get_order` / `edd_update_order_status`), bidirectional status mapping: pending→draft, complete→sale, failed/refunded/abandoned/revoked→cancel
- `EDD_Hooks` trait (`includes/modules/trait-edd-hooks.php`) — 3 EDD hook callbacks with anti-loop guards: `on_download_save`, `on_download_delete`, `on_order_status_change` (with partner resolution on completion)
- Entity types: `download` → `product.template`, `order` → `sale.order`, `invoice` → `account.move`
- Mutual exclusivity: WC > EDD > Sales — only one commerce module active at a time (3-way priority in `Module_Registry`)
- Status mapping filterable via `apply_filters('wp4odoo_edd_order_status_map', $map)` and `apply_filters('wp4odoo_edd_odoo_status_map', $map)`
- Partner resolution via `Partner_Service` (EDD customer email → Odoo `res.partner`)
- Invoices via shared `Invoice_Helper` (same CPT as WooCommerce and Sales)
- Settings: `sync_downloads`, `sync_orders`, `auto_confirm_orders` checkboxes (all default: enabled)
- Dependency detection: `class_exists('Easy_Digital_Downloads')` — module available only when EDD is active
- `EDDModuleTest` — 22 tests: identity, Odoo models, settings, field mappings, reverse mappings, boot guard, dependency status
- `EDDDownloadHandlerTest` — 10 tests: load (nonexistent, wrong type, valid, default price), save (create, update, price meta), delete
- `EDDOrderHandlerTest` — 16 tests: load, save, 6 EDD→Odoo status mappings + unknown, 4 Odoo→EDD status mappings + unknown
- EDD stubs: `tests/stubs/edd-classes.php` — `Easy_Digital_Downloads`, `EDD_Download`, `EDD_Customer`, `EDD\Orders\Order` (namespaced), `edd_get_download()`, `edd_get_order()`, `edd_update_order_status()`

#### Exchange Rate Conversion — Multi-Currency Price Conversion
- New class: `Exchange_Rate_Service` (`includes/modules/class-exchange-rate-service.php`) — fetches active exchange rates from Odoo's `res.currency` model, caches them in a WordPress transient (1-hour TTL), and converts prices between currencies
- `convert(float, string, string): ?float` — converts an amount from one currency to another using Odoo rates; returns `null` on failure (missing rate, zero rate, connection error) for graceful fallback
- `Product_Handler` and `Variant_Handler` — when `convert_currency` is enabled and currencies differ, prices are now converted instead of skipped; falls back to skip behavior if conversion fails
- `WooCommerce_Module` — new `convert_currency` setting (checkbox, default: off); wires `Exchange_Rate_Service` into product and variant handlers
- `ExchangeRateServiceTest` — 14 tests: same currency, EUR↔USD, cross-currency, rounding, empty rates, missing currencies, zero rate, transient cache, Odoo connection failure, invalid records

#### Memberships Module — WC Memberships → Odoo Push Sync
- New module: `Memberships_Module` (`includes/modules/class-memberships-module.php`) — push-only sync from WooCommerce Memberships to Odoo's native `membership` module
- `Membership_Handler` (`includes/modules/class-membership-handler.php`) — loads plan and membership data from WC, maps 8 WC membership statuses to Odoo `membership.membership_line` states (active→paid, free_trial→free, complimentary→free, delayed→waiting, pending-cancel→paid, paused→waiting, cancelled→cancelled, expired→none)
- `Membership_Hooks` trait (`includes/modules/trait-membership-hooks.php`) — 3 WC hook callbacks with anti-loop guards: `on_membership_created`, `on_membership_status_changed`, `on_membership_saved`
- Entity types: `plan` → `product.product` (membership products), `membership` → `membership.membership_line`
- Plan auto-sync: `ensure_plan_synced()` automatically pushes the membership plan to Odoo before any membership line sync
- Status mapping filterable via `apply_filters('wp4odoo_membership_status_map', $map)`
- Partner resolution via `Partner_Service` (WP user → Odoo `res.partner`)
- Admin UI: WC Memberships detection in Modules tab with disabled toggle + warning when plugin not active
- Settings: `sync_plans` and `sync_memberships` checkboxes (both default: enabled)

#### Tests
- `MembershipsModuleTest` — 17 tests: module identity, Odoo model declarations, default settings, settings fields, field mappings (plan + membership), boot guard
- `MembershipHandlerTest` — 18 tests: load_plan, load_membership, all 8 status mappings, unknown status default, filterable status map
- WC Memberships stubs: `wc_memberships()`, `wc_memberships_get_user_membership()`, `wc_memberships_get_membership_plan()`, `WC_Memberships_User_Membership`, `WC_Memberships_Membership_Plan`
- `FailureNotifierTest` — 12 tests: counter reset/increment, email dispatch at threshold, cooldown, edge cases
- `CPTHelperTest` — 11 tests: register, load (various states), save (new/update, Many2one, scalar, with logger)
- `SalesModuleTest` — 23 tests: identity, Odoo models, settings, field mappings, reverse mappings, dependency status, boot

#### Forms Module — Gravity Forms + WPForms → Odoo CRM Leads
- New module: `Forms_Module` (`includes/modules/class-forms-module.php`) — push-only sync from Gravity Forms and WPForms to Odoo `crm.lead`
- `Form_Handler` (`includes/modules/class-form-handler.php`) — stateless field extraction with auto-detection by type (name, email, phone, textarea) and multilingual label matching for company detection (EN/FR/ES)
- Field auto-detection: GF name sub-fields (`.3` first + `.6` last), email as name fallback, source auto-set to `"Gravity Forms: {title}"` / `"WPForms: {title}"`
- Filterable via `apply_filters('wp4odoo_form_lead_data', $data, $source_type, $raw_data)` — return empty array to skip
- Action hook: `do_action('wp4odoo_form_lead_created', $wp_id, $lead_data)`
- Dependency status: requires at least one form plugin (GF or WPForms), info notices for missing plugin
- Settings: `sync_gravity_forms` and `sync_wpforms` checkboxes (both default: enabled)
- Reuses `Lead_Manager` for CPT persistence (shared with CRM module)
- `FormsModuleTest` — 16 tests: identity, models, settings, field mappings, dependency status, boot
- `FormHandlerTest` — 27 tests: GF extraction (12), WPForms extraction (8), label detection (7)
- GF/WPForms stubs: `GFAPI`, `GF_Field`, `wpforms()`

#### Admin UX — Sync Direction Badges
- `Module_Base::get_sync_direction()` — new method declaring each module's sync capability (`bidirectional`, `wp_to_odoo`, or `odoo_to_wp`)
- Module card in Modules tab now displays a color-coded direction badge (green=bidirectional, blue=WP→Odoo, orange=Odoo→WP)
- CRM and WooCommerce: bidirectional; Sales: Odoo→WP; Memberships and Forms: WP→Odoo

#### MemberPress Module — Recurring Subscriptions → Odoo Accounting
- New module: `MemberPress_Module` (`includes/modules/class-memberpress-module.php`) — push-only sync from MemberPress to Odoo: plans, transactions (as invoices), and subscriptions (as membership lines)
- `MemberPress_Handler` (`includes/modules/class-memberpress-handler.php`) — loads plans, transactions (pre-formatted as `account.move` with `invoice_line_ids` One2many tuples), and subscriptions; status mapping: txn (complete→posted, pending→draft, failed/refunded→cancel), sub (active→paid, suspended→waiting, cancelled→cancelled, expired→old, paused→waiting, stopped→cancelled)
- `MemberPress_Hooks` trait (`includes/modules/trait-memberpress-hooks.php`) — 3 hook callbacks with anti-loop guards: `on_plan_save`, `on_transaction_store`, `on_subscription_status_change`
- Entity types: `plan` → `product.product`, `transaction` → `account.move`, `subscription` → `membership.membership_line`
- Invoice auto-posting: completed transactions optionally auto-posted via Odoo `action_post`
- Status mapping filterable via `wp4odoo_mepr_txn_status_map` and `wp4odoo_mepr_sub_status_map`
- Mutually exclusive with WC Memberships module (same Odoo models)
- `MemberPressModuleTest` — 28 tests; `MemberPressHandlerTest` — 28 tests
- MemberPress stubs: `MeprProduct`, `MeprTransaction`, `MeprSubscription`

#### GiveWP Module — Donations → Odoo Accounting
- New module: `GiveWP_Module` (`includes/modules/class-givewp-module.php`) — push-only sync from GiveWP donations to Odoo accounting, with **full recurring donation support** (each recurring payment flows through the standard donation pipeline automatically)
- `GiveWP_Handler` (`includes/modules/class-givewp-handler.php`) — loads form and donation data from GiveWP CPTs (`give_forms`, `give_payment`), pre-formats for Odoo target model; status mapping: publish→completed, refunded→refunded, pending→pending (default: draft)
- `GiveWP_Hooks` trait (`includes/modules/trait-givewp-hooks.php`) — 2 hook callbacks with anti-loop guards: `on_form_save` (`save_post_give_forms`), `on_donation_status_change` (`give_update_payment_status`) — transparently handles both one-time and recurring donations
- **Dual Odoo model with runtime detection**: probes `ir.model` for OCA `donation.donation` module (cached 1h); if found, uses `donation.donation` with `line_ids`/`unit_price`/`donation_date` format; otherwise falls back to `account.move` with `invoice_line_ids`/`price_unit`/`invoice_date` format
- Auto-validation: completed donations automatically posted in Odoo — OCA: `validate` method, core: `action_post` method (configurable via `auto_validate_donations` setting)
- Form auto-sync: `ensure_form_synced()` pushes the donation form to Odoo as `product.product` before any dependent donation sync
- Guest donor support: donors without WP accounts resolved via `Partner_Service::get_or_create($email, $data, 0)`
- GiveWP Recurring Donations add-on detected at boot (`class_exists('Give_Recurring')`) with info log
- Status mapping filterable via `apply_filters('wp4odoo_givewp_donation_status_map', $map)`
- Settings: `sync_forms`, `sync_donations`, `auto_validate_donations` checkboxes (all default: enabled)
- Dependency detection: `defined('GIVE_VERSION')` — module available only when GiveWP is active
- `GiveWPModuleTest` — 22 tests: identity, Odoo models, settings, field mappings, dependency status, boot guard
- `GiveWPHandlerTest` — 20 tests: load_form, load_donation (account.move + OCA formats), status mapping, edge cases
- GiveWP stubs: `Give` class, `give()` function, `GIVE_VERSION` constant

#### WP Charitable Module — Donations → Odoo Accounting
- New module: `Charitable_Module` (`includes/modules/class-charitable-module.php`) — push-only sync from WP Charitable donations to Odoo accounting, with **full recurring donation support** (each recurring payment fires the same `transition_post_status` hook, so every instalment is pushed automatically)
- `Charitable_Handler` (`includes/modules/class-charitable-handler.php`) — loads campaign and donation data from WP Charitable CPTs (`campaign`, `donation`), pre-formats for Odoo target model; 6-entry status mapping: charitable-completed→completed, charitable-pending→pending, charitable-failed→draft, charitable-refunded→refunded, charitable-cancelled→draft, charitable-preapproval→pending
- `Charitable_Hooks` trait (`includes/modules/trait-charitable-hooks.php`) — 2 hook callbacks with anti-loop guards: `on_campaign_save` (`save_post_campaign`), `on_donation_status_change` (`transition_post_status` filtered for `donation` post type) — only syncs completed and refunded donations
- **Dual Odoo model with runtime detection**: same as GiveWP — probes `ir.model` for OCA `donation.donation` (shared transient `wp4odoo_has_donation_model`, 1h TTL)
- Auto-validation: completed donations (`charitable-completed` status) automatically posted in Odoo — OCA: `validate`, core: `action_post` (configurable via `auto_validate_donations` setting)
- Campaign auto-sync: `ensure_campaign_synced()` pushes campaign to Odoo as `product.product` before any dependent donation sync
- Guest donor support: donor email/name from post meta (`_charitable_donor_email`, `_charitable_donor_first_name`, `_charitable_donor_last_name`), resolved via `Partner_Service::get_or_create($email, $data, 0)`
- WP Charitable Recurring add-on detected at boot (`class_exists('Charitable_Recurring')`) with info log
- Status mapping filterable via `apply_filters('wp4odoo_charitable_donation_status_map', $map)`
- Settings: `sync_campaigns`, `sync_donations`, `auto_validate_donations` checkboxes (all default: enabled)
- Dependency detection: `class_exists('Charitable')` — module available only when WP Charitable is active
- No mutual exclusivity with GiveWP or any other module (separate Odoo models)
- `CharitableModuleTest` — 22 tests: identity, Odoo models, settings, field mappings, dependency status, boot guard
- `CharitableHandlerTest` — 23 tests: load_campaign, load_donation (account.move + OCA formats), 6 status mappings, edge cases
- Charitable stubs: `Charitable` class with `instance()` method

#### WP Simple Pay Module — Stripe Payments → Odoo Accounting
- New module: `SimplePay_Module` (`includes/modules/class-simplepay-module.php`) — push-only sync from WP Simple Pay Stripe payments to Odoo accounting, with **full recurring subscription support** (each Stripe invoice payment is captured via webhook and pushed automatically)
- `SimplePay_Handler` (`includes/modules/class-simplepay-handler.php`) — extracts payment data from Stripe webhook objects (PaymentIntent/Invoice), manages hidden `wp4odoo_spay` tracking CPT, pre-formats for Odoo target model; supports cents-to-major-unit conversion, email/name extraction from Stripe billing details, and form resolution from Stripe metadata
- `SimplePay_Hooks` trait (`includes/modules/trait-simplepay-hooks.php`) — 3 hook callbacks with anti-loop guards: `on_form_save` (`save_post_simple-pay`), `on_payment_succeeded` (`simpay_webhook_payment_intent_succeeded` for one-time payments), `on_invoice_payment_succeeded` (`simpay_webhook_invoice_payment_succeeded` for recurring subscriptions)
- **Hidden tracking CPT** (`wp4odoo_spay`): WP Simple Pay stores payments in Stripe only — this module creates internal tracking posts from Stripe webhook data to integrate with the Module_Base architecture (`show_ui => false`, cleaned up on uninstall)
- **Dual Odoo model with runtime detection**: same as GiveWP/Charitable — probes `ir.model` for OCA `donation.donation` (shared transient `wp4odoo_has_donation_model`, 1h TTL)
- **Deduplication by Stripe PaymentIntent ID**: prevents double-push when both `payment_intent.succeeded` and `invoice.payment_succeeded` fire for the same payment
- Auto-validation: all Stripe payments auto-validated in Odoo (Stripe webhook = already succeeded) — OCA: `validate`, core: `action_post` (configurable via `auto_validate_payments` setting)
- Form auto-sync: `ensure_form_synced()` pushes payment form to Odoo as `product.product` before any dependent payment sync
- Guest payer support: payer email/name from Stripe PaymentIntent billing details, resolved via `Partner_Service::get_or_create($email, $data, 0)`
- Settings: `sync_forms`, `sync_payments`, `auto_validate_payments` checkboxes (all default: enabled)
- Dependency detection: `defined('SIMPLE_PAY_VERSION')` — module available only when WP Simple Pay is active
- No mutual exclusivity with any other module
- `SimplePayModuleTest` — 22 tests: identity, Odoo models, settings, field mappings, dependency status, boot guard
- `SimplePayHandlerTest` — 30 tests: load_form, extract_from_payment_intent, extract_from_invoice, find_existing_payment, create_tracking_post, load_payment (account.move + OCA formats), edge cases
- SimplePay stubs: `SIMPLE_PAY_VERSION` constant

#### Amelia Module — Bookings → Odoo Calendar + Products
- New module: `Amelia_Module` (`includes/modules/class-amelia-module.php`) — push-only sync from Amelia Booking to Odoo: services as `product.product` (type service) and appointments as `calendar.event`
- `Amelia_Handler` (`includes/modules/class-amelia-handler.php`) — data access via `$wpdb` queries on Amelia's custom tables (`amelia_services`, `amelia_appointments`, `amelia_customer_bookings`, `amelia_users`), since Amelia does not use WordPress CPTs
- `Amelia_Hooks` trait (`includes/modules/trait-amelia-hooks.php`) — 4 hook callbacks with anti-loop guards: `on_booking_saved` (only approved bookings), `on_booking_canceled`, `on_booking_rescheduled`, `on_service_saved`
- Entity types: `service` → `product.product`, `appointment` → `calendar.event`
- Appointment data enrichment: service name + customer resolution via `Partner_Service` → Odoo `partner_ids` M2M command `[[4, id, 0]]`
- Event naming: composed as "Service — Customer" (e.g., "Massage 60min — Jane Doe")
- Service auto-sync: `ensure_service_synced()` pushes the service to Odoo before any dependent appointment sync
- Partner resolution via `Partner_Service::get_or_create($email, $data, 0)` for Amelia customers
- Settings: `sync_services` and `sync_appointments` checkboxes (both default: enabled)
- Dependency detection: `defined('AMELIA_VERSION')` — module available only when Amelia Booking is active
- No mutual exclusivity with any other module
- `AmeliaModuleTest` — 24 tests: identity, Odoo models, settings, field mappings (service + appointment), map_to_odoo, dependency status, boot guard
- `AmeliaHandlerTest` — 15 tests: load_service, load_appointment, get_customer_data, get_service_id_for_appointment, table name verification
- Amelia stubs: `AMELIA_VERSION` constant

#### Bookly Module — Bookings → Odoo Calendar + Products (Polling)
- New module: `Bookly_Module` (`includes/modules/class-bookly-module.php`) — push-only sync from Bookly to Odoo: services as `product.product` (type service) and customer_appointments as `calendar.event`
- `Bookly_Handler` (`includes/modules/class-bookly-handler.php`) — data access via `$wpdb` queries on Bookly's custom tables (`bookly_services`, `bookly_appointments`, `bookly_customer_appointments`, `bookly_customers`), includes batch queries for polling
- `Bookly_Cron_Hooks` trait (`includes/modules/trait-bookly-cron-hooks.php`) — WP-Cron-based polling every 5 minutes (`wp4odoo_bookly_poll`), replaces hooks since Bookly has NO WordPress hooks for booking lifecycle events. Uses SHA-256 hash comparison against `entity_map` for change detection
- Entity types: `service` → `product.product`, `booking` → `calendar.event`
- `Entity_Map_Repository::get_module_entity_mappings()` — new batch method returning `[wp_id => {odoo_id, sync_hash}]` for efficient polling without N+1 queries
- Booking data enrichment: service name + customer resolution via `Partner_Service` → Odoo `partner_ids` M2M command `[[4, id, 0]]`
- Event naming: composed as "Service — Customer" (e.g., "Haircut — Jane Doe")
- Service auto-sync: `ensure_service_synced()` pushes the service to Odoo before any dependent booking sync
- Status mapping: approved/done → sync, pending/waitlisted → skip, cancelled/rejected/no-show → delete if previously synced
- Settings: `sync_services` and `sync_bookings` checkboxes (both default: enabled)
- Dependency detection: `class_exists('Bookly\Lib\Plugin')` — module available only when Bookly is active
- Cron cleanup on deactivation (`wp4odoo.php`) and uninstall (`uninstall.php`)
- No mutual exclusivity with any other module
- `BooklyModuleTest` — 24 tests: identity, Odoo models, settings, field mappings (service + booking), map_to_odoo, dependency status, boot guard, poll
- `BooklyHandlerTest` — 22 tests: load_service, load_booking, get_customer_data, get_service_id_for_booking, get_all_services, get_active_bookings, table name verification
- Bookly stubs: `Bookly\Lib\Plugin` namespaced class

#### WP Recipe Maker Module — Recipes → Odoo Products
- New module: `WPRM_Module` (`includes/modules/class-wprm-module.php`) — push-only sync from WP Recipe Maker recipes to Odoo as service products (`product.product`)
- `WPRM_Handler` (`includes/modules/class-wprm-handler.php`) — loads recipe data from `wprm_recipe` CPT and meta fields (`wprm_summary`, `wprm_prep_time`, `wprm_cook_time`, `wprm_total_time`, `wprm_servings`, `wprm_servings_unit`, `wprm_cost`), builds structured description with times and servings info
- `WPRM_Hooks` trait (`includes/modules/trait-wprm-hooks.php`) — 1 hook callback with anti-loop guard: `on_recipe_save` (`save_post_wprm_recipe`)
- Single entity type: `recipe` → `product.product` (service type, simplest module pattern)
- Settings: `sync_recipes` checkbox (default: enabled)
- Dependency detection: `defined('WPRM_VERSION')` — module available only when WP Recipe Maker is active
- No mutual exclusivity with any other module
- `WPRMModuleTest` — 15 tests: identity, Odoo models, settings, field mappings, dependency status, boot guard, map_to_odoo
- `WPRMHandlerTest` — 12 tests: load_recipe (name, type, price, cost meta, summary, HTML stripping, times, servings, empty meta), not found edge cases
- WPRM stubs: `WPRM_VERSION` constant

#### Refactoring
- `Module_Base`: 3 new shared helpers — `mark_importing()` (replaces inline `define()`), `delete_wp_post()` (safe post deletion with null/false check), `log_unsupported_entity()` (centralized warning logging)
- `CRM_Module`, `Sales_Module`, `WooCommerce_Module`: refactored to use new `Module_Base` helpers, removed duplicated code
- `uninstall.php`: added CPT cleanup — deletes all `wp4odoo_lead`, `wp4odoo_order`, `wp4odoo_invoice` posts on plugin uninstall
- **Dual Accounting Model extraction**: `Dual_Accounting_Model` trait (`includes/modules/trait-dual-accounting-model.php`) — extracts 5 identical methods from GiveWP, Charitable, and SimplePay modules: `has_donation_model()` (OCA detection with transient cache), `resolve_accounting_model()` (runtime model switching), `ensure_parent_synced()` (auto-push form/campaign before child), `auto_validate()` (OCA validate / core action_post), `partner_service()` (lazy Partner_Service). Removes ~250 lines of duplication
- **Odoo Accounting Formatter**: `Odoo_Accounting_Formatter` (`includes/modules/class-odoo-accounting-formatter.php`) — extracts 2 static methods: `for_donation_model()` (OCA donation.donation format) and `for_account_move()` (core invoice format). Replaces private formatting methods in GiveWP_Handler, Charitable_Handler, and SimplePay_Handler. Removes ~115 lines of duplication

#### Security
- `Webhook_Handler`: hardened `get_client_ip()` — parses only first IP from X-Forwarded-For, validates with `filter_var(FILTER_VALIDATE_IP)`, falls back to REMOTE_ADDR

### Changed

#### Dependency Injection — Service Locator Removal
- `Module_Base` — constructor now requires `\Closure $client_provider`; `client()` uses the injected closure instead of `WP4Odoo_Plugin::instance()->client()` (removes hidden service locator pattern)
- 8 module constructors updated to forward `$client_provider` to parent: `CRM_Module`, `Memberships_Module`, `MemberPress_Module`, `GiveWP_Module`, `Charitable_Module`, `SimplePay_Module`, `WPRM_Module`, `Forms_Module`
- 3 modules inherit automatically (no constructor): `Sales_Module`, `WooCommerce_Module`, `EDD_Module`
- `Module_Registry` — acts as factory: creates a single `$client_provider` closure and distributes it to all modules; `wp4odoo_register_modules` hook now passes `$client_provider` as 2nd argument for third-party modules
- `Sync_Engine` — constructor now requires `\Closure $module_resolver`; `process_job()` uses the injected closure instead of `WP4Odoo_Plugin::instance()->get_module()` (removes singleton access)
- `wp4odoo.php` and `class-cli.php` — pass module resolver closure to `Sync_Engine`
- Tests — added `wp4odoo_test_client_provider()` and `wp4odoo_test_module_resolver()` helpers in bootstrap; updated all module and engine tests

#### Dependency Injection — Static Repositories → Injected Instances
- `Entity_Map_Repository` — converted from pure-static class to injectable instance; 7 methods now non-static, per-request cache is per-instance (test-isolable)
- `Sync_Queue_Repository` — converted from pure-static class to injectable instance; 9 methods now non-static
- `Module_Base` — constructor now requires `Entity_Map_Repository $entity_map`; 4 wrapper methods (`get_mapping`, `get_wp_mapping`, `save_mapping`, `remove_mapping`) delegate to `$this->entity_map` instead of static calls; new protected getter `entity_map()` for subclasses
- 8 module constructors updated to forward `$entity_map` to parent
- `Module_Registry` — creates one shared `Entity_Map_Repository` instance and distributes it to all modules; `wp4odoo_register_modules` hook now passes `$entity_map` as 3rd argument
- `Partner_Service` — constructor now requires `Entity_Map_Repository $entity_map`; 4 static calls replaced with instance calls
- `Variant_Handler` — constructor now requires `Entity_Map_Repository $entity_map`; 2 static calls replaced with instance calls
- `Bulk_Handler` — constructor now requires `Entity_Map_Repository $entity_map`; 2 static calls replaced with instance calls
- `Sync_Engine` — constructor now requires `Sync_Queue_Repository $queue_repo`; 4 internal static calls replaced with instance calls; 4 static wrappers (`enqueue`, `get_stats`, `cleanup`, `retry_failed`) removed
- `Queue_Manager` — now owns a lazy `Sync_Queue_Repository` instance internally; absorbed the 3 convenience methods from Sync_Engine (`get_stats`, `retry_failed`, `cleanup`)
- `CLI`, `Admin_Ajax`, `Settings_Page` — all `Sync_Engine::get_stats/retry_failed/cleanup` calls migrated to `Queue_Manager::`
- Tests — added `wp4odoo_test_entity_map()` and `wp4odoo_test_queue_repo()` helpers; updated ~20 test files for instance-based repositories

#### Dependency Injection — Centralized Settings Repository
- New class: `Settings_Repository` (`includes/class-settings-repository.php`) — single source of truth for all `wp4odoo_*` option keys, default values, and typed accessors; injectable via constructor (same DI pattern as `Entity_Map_Repository` and `Sync_Queue_Repository`)
- 10 option key constants (`OPT_CONNECTION`, `OPT_SYNC_SETTINGS`, `OPT_LOG_SETTINGS`, `OPT_WEBHOOK_TOKEN`, etc.) replace ~40 scattered string literals across the codebase
- Typed accessors: `get_connection()`, `get_sync_settings()`, `get_log_settings()` merge stored values with defaults; `is_module_enabled()`, `set_module_enabled()`, `get_module_settings()`, `save_module_settings()`, `get_module_mappings()` for per-module options; `get_webhook_token()`, `save_webhook_token()`, failure tracking, onboarding/checklist state
- `seed_defaults()` method replaces `Database_Migration::set_default_options()` hardcoded values
- Static default accessors (`connection_defaults()`, `sync_defaults()`, `log_defaults()`) for use by sanitization callbacks
- `WP4Odoo_Plugin` — new `$settings` property and `settings()` getter; distributes to `Module_Registry`, `Sync_Engine`, `Webhook_Handler`
- `Module_Base` — 3rd constructor parameter `Settings_Repository $settings`; `get_settings()` and `get_field_mapping()` delegate to repository
- `Logger` — optional 2nd parameter `?Settings_Repository $settings`; uses repository when available, falls back to static cache for standalone usage (e.g., `Odoo_Auth`)
- `Sync_Engine`, `Failure_Notifier`, `Webhook_Handler` — constructor now requires `Settings_Repository`; all `get_option()`/`update_option()` replaced with typed repository calls
- Admin layer (`Settings_Page`, AJAX traits, `Admin`) — uses `wp4odoo()->settings()` for all option access
- `CLI` — uses `wp4odoo()->settings()` for module status and toggle
- `Odoo_Auth` — uses `Settings_Repository::OPT_CONNECTION` constant (static class, no DI)
- `tab-modules.php` — `get_option()` calls replaced with `wp4odoo()->settings()->is_module_enabled()`
- 8 module constructors updated to forward `$settings` to parent
- `SettingsRepositoryTest` — 30 unit tests: typed accessors, default merging, module helpers, seed_defaults, non-array handling, constant prefixes
- Tests — added `wp4odoo_test_settings()` helper; updated ~20 test files; added `wp4odoo()` function stub
- PHPStan bootstrap and plugin-stub updated with `settings()` method and `wp4odoo()` function

#### Declarative Mutual Exclusivity
- `Module_Base` — new `$exclusive_group` and `$exclusive_priority` properties with public getters; modules now self-declare their exclusivity constraints instead of relying on hardcoded logic in the registry
- 5 modules declare exclusive groups: `WooCommerce_Module` (commerce/30), `EDD_Module` (commerce/20), `Sales_Module` (commerce/10), `Memberships_Module` (memberships/20), `MemberPress_Module` (memberships/10)
- `Module_Registry` — removed all hardcoded if/elseif exclusivity chains from `register_all()`; `register()` now checks `$exclusive_group` and `$exclusive_priority` to skip booting lower-priority modules; new `has_booted_in_group()`, `get_active_in_group()`, `get_conflicts()` helpers; new `$booted` tracking array
- `trait-ajax-module-handlers.php` — `toggle_module()` auto-disables conflicting modules when enabling one in the same exclusive group; returns `auto_disabled` array and warning message in AJAX response
- `tab-modules.php` — `data-exclusive-group` attribute on module cards; conflict warning notice displayed when a peer in the same group is active
- `admin.js` — handles `auto_disabled` in AJAX response: unchecks toggles and hides settings of auto-disabled modules
- `WooCommerce_Module` and `EDD_Module` — removed hardcoded info notices about mutual exclusivity from `get_dependency_status()`
- `wp4odoo.php` — exposed `module_registry()` public getter on `WP4Odoo_Plugin`
- `ModuleRegistryTest` — 16 new tests: boot/no-boot, exclusive group blocking, priority resolution, `get_active_in_group()`, `get_conflicts()`
- 10 existing module tests updated with `test_exclusive_group` and `test_exclusive_priority` assertions

- Plugin version bumped from 1.9.8 to 2.0.0
- PHPUnit: 952 unit tests, 1538 assertions — all green (was 915/1473)
- PHPStan: 0 errors on 76 files (was 75 — added `class-settings-repository.php`)
- Translations: 417 translated strings (FR + ES), 0 fuzzy, 0 untranslated
- `Dependency_Loader` — added 24 `require_once` (2 forms + 1 exchange rate + 4 EDD + 3 MemberPress + 3 GiveWP + 3 Charitable + 3 SimplePay + 3 WPRM + 2 shared accounting module files)
- `Module_Registry` — registers `Forms_Module` when Gravity Forms or WPForms is active; extended mutual exclusivity from 2-way (WC/Sales) to 3-way (WC/EDD/Sales); registers `MemberPress_Module` when MemberPress is active (mutually exclusive with WC Memberships); registers `GiveWP_Module` when GiveWP is active; registers `Charitable_Module` when WP Charitable is active; registers `SimplePay_Module` when WP Simple Pay is active; registers `WPRM_Module` when WP Recipe Maker is active (no mutual exclusivity)
- `tests/bootstrap.php` — added forms stubs and 2 new source file requires; added EDD stubs, global store, and 4 EDD source requires; added MemberPress stubs and 3 source requires; added GiveWP stubs and 3 source requires; added Charitable stubs and 3 source requires; added SimplePay stubs and 3 source requires; added WPRM stubs and 3 source requires
- `phpstan-bootstrap.php` — added GFAPI, GF_Field, and wpforms() stubs; added EDD class/function stubs (separate `phpstan-edd-stubs.php` for namespace isolation); added MemberPress class stubs (MeprProduct, MeprTransaction, MeprSubscription); added GiveWP stubs (Give class, give() function, GIVE_VERSION constant); added Charitable stub (Charitable class); added SimplePay stub (SIMPLE_PAY_VERSION constant); added WPRM stub (WPRM_VERSION constant)
- `tests/stubs/wp-functions.php` — `get_post_meta()` and `update_post_meta()` now use `$GLOBALS['_wp_post_meta']` store for realistic test behavior
- `README.md` — added Memberships module to features, module table, Required Odoo apps table; added WooCommerce 7.1+ and WC Memberships 1.12+ to Requirements
- `Dependency_Loader` — added 3 `require_once` for membership module files
- `Module_Registry` — registers `Memberships_Module` when WooCommerce is active
- `tab-modules.php` — WC Memberships detection and disabled toggle with warning notice
- `tests/bootstrap.php` — added WC Memberships globals and 3 new source file requires
- `phpstan-bootstrap.php` — added WC Memberships function and class stubs

#### Documentation
- `ARCHITECTURE.md` — complete rewrite: updated from 3-module overview to comprehensive documentation covering all 11 modules, shared accounting infrastructure (`Dual_Accounting_Model` trait, `Odoo_Accounting_Formatter`), complete directory structure (~75 source files), hooks & filters reference, mutual exclusivity rules, updated test counts (879/1436), PHPStan file count (75)

## [1.9.8] - 2026-02-10

### Added

#### Entity_Map_Repository — Per-request cache
- Static lookup cache for `get_odoo_id()` and `get_wp_id()` with reverse population and invalidation on `save()` / `remove()`
- `flush_cache()` method for testing and bulk operations
- Batch methods (`get_wp_ids_batch()`, `get_odoo_ids_batch()`) also populate the cache

#### Webhook — Payload deduplication
- SHA-256 hash deduplication in `Webhook_Handler::handle_webhook()` (5-minute transient window)
- Duplicate payloads return HTTP 200 `{success: true, deduplicated: true}` without re-enqueuing

#### Sync_Engine — Failure notification
- Admin email notification after 5 consecutive batch failures (1-hour cooldown between emails)
- `wp4odoo_consecutive_failures` option auto-resets on any successful job

#### Sync_Engine — Dry run mode
- `set_dry_run(bool)` method — logs job details without calling `push_to_odoo()` / `pull_from_odoo()`
- WP-CLI: `wp wp4odoo sync run --dry-run` flag

#### Bulk_Handler — Progress tracking
- `import_products()` now returns `total` (via `search_count()`) alongside `enqueued`
- Updated i18n messages: "%1$d product(s) enqueued for import (%2$d found in Odoo)"
- Admin JS: `#wp4odoo-bulk-feedback` span shows "Processing..." then result message

#### Tests
- 11 new tests: 5 cache (EntityMapRepositoryTest), 1 dedup (WebhookHandlerTest), 3 notification + 1 dry-run (SyncEngineTest), 1 CLI dry-run (CLITest)
- Transient stubs made stateful (`$GLOBALS['_wp_transients']`)
- `wp_mail()` stub added (`$GLOBALS['_wp_mail_calls']`)

#### Integration Tests (wp-env)
- **`@wordpress/env`** Docker-based test environment with WordPress + WooCommerce + MySQL
- `phpunit-integration.xml` + `tests/bootstrap-integration.php` — separate config/bootstrap for integration suite
- `DatabaseMigrationTest` — 7 tests: table creation (sync_queue, entity_map, logs), column verification, unique key, idempotency, default options seeding
- `EntityMapRepositoryTest` — 7 tests: save/get round-trips, null lookups, REPLACE INTO, remove, batch methods
- `SyncQueueRepositoryTest` — 10 tests: enqueue, dedup, priority ordering, scheduled_at, status updates, cancel, cleanup, retry, stats
- `SyncEngineLockTest` — 2 tests: empty queue returns 0, advisory lock release
- `npm run test:integration` — runs inside wp-env container via `wp-env run tests-cli`

#### Quality Tooling
- **PHPCS + WordPress Coding Standards** — `wp-coding-standards/wpcs` 3.3 with `.phpcs.xml.dist` config; WordPress-Extra standard with project-specific exclusions; auto-fixed 594+ violations via PHPCBF
- **Code Coverage** — PHPUnit with PCOV in CI, Clover XML output, Codecov upload

### Changed

- Plugin version bumped from 1.9.7 to 1.9.8
- PHPUnit: 436 unit tests, 855 assertions — all green
- Integration: 26 tests via wp-env (WordPress + WooCommerce + MySQL)
- PHPStan: 0 errors on 47 files (was 44 — added 3 extracted files)
- CI: 4 jobs — PHP 8.2/8.3 (PHPCS + PHPUnit + PHPStan), Code Coverage (PCOV), wp-env Integration Tests

#### Refactoring — WooCommerce_Module Hook Extraction
- New file: `includes/modules/trait-woocommerce-hooks.php` — `WooCommerce_Hooks` trait with 4 WC hook callbacks (`on_product_save`, `on_product_delete`, `on_new_order`, `on_order_status_changed`)
- `WooCommerce_Module` now `use WooCommerce_Hooks;` — hook methods removed from class body

#### Refactoring — CRM_Module Hook Extraction
- New file: `includes/modules/trait-crm-user-hooks.php` — `CRM_User_Hooks` trait with 3 WP user hook callbacks (`on_user_register`, `on_profile_update`, `on_delete_user`)
- `CRM_Module` now `use CRM_User_Hooks;` — hook methods removed from class body

#### Refactoring — Sync_Engine Failure Notification Extraction
- New file: `includes/class-failure-notifier.php` — `Failure_Notifier` class encapsulating consecutive failure tracking, threshold check (5 failures), admin email notification (1-hour cooldown)
- `Sync_Engine` now delegates failure notification to `Failure_Notifier::check()` instead of inline logic

#### Refactoring — Settings_Page Tab Dispatcher
- `Settings_Page` — new `render_tab(string $slug)` dispatcher method replaces switch/case block in `page-settings.php` template
- `page-settings.php` reduced from 43 to 26 lines (switch → single `$this->render_tab()` call)

#### Documentation
- `ARCHITECTURE.md` — new "Error Handling Convention" section (4-tier strategy), CPT_Helper vs Lead_Manager architecture note in CRM section
- `README.md` — added `--dry-run` to WP-CLI examples

#### New Files
- `package.json` — npm config with `@wordpress/env` for Docker-based testing
- `.wp-env.json` — WordPress + WooCommerce test environment config
- `phpunit-integration.xml` — PHPUnit config for integration test suite
- `tests/bootstrap-integration.php` — WP test framework bootstrap
- `tests/Integration/DatabaseMigrationTest.php` — 7 integration tests
- `tests/Integration/EntityMapRepositoryTest.php` — 7 integration tests
- `tests/Integration/SyncQueueRepositoryTest.php` — 10 integration tests
- `tests/Integration/SyncEngineLockTest.php` — 2 integration tests
- `tests/Integration/index.php` — directory index protection

#### Git
- Removed `vendor/` from tracking (1783 files) — installed via `composer install`
- Updated `.gitignore` — added `node_modules/`, `package-lock.json`, `.wp-env.override.json`

## [1.9.7] - 2026-02-10

### Added

#### Odoo module availability detection
- `Odoo_Auth::probe_models()` — queries `ir.model` registry to check which Odoo models exist on the connected instance
- `test_connection()` AJAX — now collects all enabled module models and reports available/missing after successful auth; returns `model_warning` in response
- `toggle_module()` AJAX — when enabling a module, checks required Odoo models via `Odoo_Client::search_read()` on `ir.model`; returns non-blocking `warning` if models are missing
- `Admin_Ajax::format_missing_model_warning()` — WP_DEBUG-aware message: shows model names + Odoo module hints in debug mode, generic guidance in production
- `Admin_Ajax::ODOO_MODULE_HINT` constant — maps Odoo model names to their parent module (e.g., `crm.lead` → CRM, `sale.order` → Sales)

#### Admin JS — Warning display
- `bindTestConnection` now shows `model_warning` as a yellow warning notice after successful connection test
- `bindModuleToggles` now shows `warning` as a yellow warning notice after successful module toggle

#### Tests
- 9 new tests: 6 for `probe_models()` (OdooAuthTest) + 3 for model warning behaviour (AdminAjaxTest)
- `wp_remote_post` stub enhanced with response queue (`$GLOBALS['_wp_remote_responses']`) for multi-call tests
- Test bootstrap now loads `Odoo_JsonRPC` and `Odoo_XmlRPC` (needed for `Odoo_Client::ensure_connected()` in end-to-end tests)

### Changed

- `Odoo_Auth::test_connection()` — new `$check_models` parameter (6th, optional `array`)
- Plugin version bumped from 1.9.6 to 1.9.7
- PHPUnit: 425 tests, 844 assertions — all green
- PHPStan: 0 errors on 44 files

#### Documentation
- `README.md` — new Compatibility section: version × hosting type matrix (On-Premise, Odoo.sh, Odoo Online, One App Free), required Odoo apps per module table, Community/Enterprise note

## [1.9.6] - 2026-02-10

### Changed

#### Refactoring — Admin_Ajax
- `Admin_Ajax` refactored from monolithic class (477 lines, 15 handlers) into coordinator + 3 domain traits:
  - `Ajax_Monitor_Handlers` — queue management + log viewing (7 handlers)
  - `Ajax_Module_Handlers` — module settings + bulk operations (4 handlers)
  - `Ajax_Setup_Handlers` — connection testing + onboarding (4 handlers)
- `Admin_Ajax` now ~95 lines (coordinator, hook registration, `verify_request()`, `get_post_field()`)
- `verify_request()` and `get_post_field()` visibility changed from `private` to `protected` (trait access)

#### Consistency
- `wp4odoo-fr_FR.po` — fixed stale `Project-Id-Version` (was 1.0.2, now 1.9.6)
- `wp4odoo-es_ES.po` — fixed stale `Project-Id-Version` (was 1.9.4, now 1.9.6)

- Plugin version bumped from 1.9.5 to 1.9.6
- PHPUnit: 416 tests, 811 assertions — all green (zero test changes needed)
- PHPStan: 0 errors on 44 files (was 41)

## [1.9.5] - 2026-02-10

### Added

#### Translations — Spanish (es_ES)
- New `wp4odoo-es_ES.po` / `.mo` — complete Spanish translation (252 strings, 0 fuzzy, 0 untranslated)

### Fixed

#### Consistency
- `tests/bootstrap.php` — fixed stale `WP4ODOO_VERSION` constant (was 1.9.2, now matches plugin version)
- `README.md` — fixed i18n string count (was 249, now 252 matching actual `.po` count since 1.9.3)
- `ARCHITECTURE.md` — fixed `logo.avif` → `logo-v2.avif` to match actual file; corrected individual test counts for 10 files; added missing `WebhookHandlerTest` (16 tests) entry; added Spanish language files to directory tree; removed stale internal tooling reference from directory tree

### Changed

#### Documentation
- `README.md` — multilingual support now prominently highlighted: intro paragraph mentions "Ships in **3 languages**", feature bullet renamed from "Internationalized" to "Multilingual (3 languages)" with Gettext mention and translation-ready note
- `ARCHITECTURE.md` — removed internal tooling reference from directory tree

- Plugin version bumped from 1.9.4 to 1.9.5
- PHPUnit: 416 tests, 811 assertions — all green
- PHPStan: 0 errors on 41 files
- Translations: 252 strings × 2 languages (FR, ES), 0 fuzzy, 0 untranslated

## [1.9.4] - 2026-02-10

### Added

#### Tests — Admin_Ajax Coverage (33 tests)
- `AdminAjaxTest` — 33 tests covering all 15 AJAX handlers: permission denial (403), dismiss/confirm onboarding actions, retry_failed, cleanup_queue, cancel_job, purge_logs, queue_stats, toggle_module, fetch_logs (pagination + filters + serialization), fetch_queue (pagination + serialization), save_module_settings (sanitization per field type: checkbox, number, select, text), test_connection (missing credentials, POST fields, stored API key fallback), bulk_import/export (WC module guard)
- Fake module helper (`register_fake_module`) for testing `save_module_settings` with 4 field types

#### Tests — Repository & Bulk Coverage (27 tests)
- `SyncQueueRepositoryTest` — 12 new tests: fetch_pending, update_status (with extra fields), cleanup (prepare + status filter), retry_failed, get_pending (module filter, entity_type filter)
- `EntityMapRepositoryTest` — 9 new tests: get_wp_ids_batch (empty, map, no matches, placeholders, single), get_odoo_ids_batch (empty, map, no matches, placeholders)
- `BulkSyncTest` — 5 new tests: batch wp_ids/odoo_ids map lookups, action determination from batch maps (import + export)

### Changed

#### Performance — `get_option()` Caching
- `Logger` — static `$settings_cache` + `get_log_settings()` method; eliminates repeated `get_option('wp4odoo_log_settings')` calls across Logger instances; `reset_cache()` for test isolation
- `Module_Base` — instance `$mapping_cache` for `get_field_mapping()`; avoids re-reading `get_option()` for the same entity type within a request
- `Sync_Engine` — static local `$settings` cache in `process_queue()` for sync settings

#### Robustness
- `Module_Base::resolve_many2one_field()` — empty records guard before accessing `$records[0][$field]`
- `Partner_Service::get_or_create()` — `is_connected()` check before Odoo API calls; returns null with error log when client is disconnected
- `Webhook_Handler` — added `args` schema (module, entity_type, odoo_id, action) to `/webhook` POST route for REST API validation

#### Assets
- Added `assets/images/logo-v2.avif` — plugin logo displayed in README

#### Translations
- Added Spanish translation (`wp4odoo-es_ES.po` / `.mo`) — 252 strings, 0 fuzzy, 0 untranslated
- Updated i18n string count in README.md from 249 to 252

#### Consistency Fixes
- `tests/bootstrap.php` — fixed stale `WP4ODOO_VERSION` constant (was 1.9.2, now 1.9.4)
- `ARCHITECTURE.md` — corrected individual test counts for 10 files (FieldMapper 49, Logger 33, SettingsPage 20, ContactRefiner 19, WebhookHandler 16, SyncEngine 15, QueryService 15, CLI 15, OdooAuth 14, OdooClient 14, SyncQueueRepository 31); added missing `WebhookHandlerTest` entry; fixed `logo.avif` → `logo-v2.avif`; added Spanish language files to directory tree

#### Documentation
- `ARCHITECTURE.md` — added AdminAjaxTest (33 tests) to directory tree, updated total (416/811); added `assets/images/` listing (architecture.svg, logo-v2.avif)
- `README.md` — updated test counts (416/811), added plugin logo image, updated i18n count (252 strings, FR + ES)

#### Verification
- PHPUnit: 416 tests, 811 assertions — all green (was 356/704)
- PHPStan: 0 errors on 41 files
- Translations: 252 strings × 2 languages (FR, ES), 0 fuzzy, 0 untranslated
- Plugin version bumped from 1.9.3 to 1.9.4

## [1.9.3] - 2026-02-10

### Fixed

#### Uninstall — Option Cleanup
- `uninstall.php` — fixed stale prefix `odoo_wpc_%` (from pre-rebrand era) → `wp4odoo_%`; plugin options were not being deleted on uninstall

#### i18n — Untranslatable Admin Labels
- `Settings_Page` — tab labels were hardcoded in a PHP `const` (cannot contain function calls); replaced `TABS` constant with `TAB_SLUGS` array + `get_tabs()` method using `__()` for runtime translation
- `tab-connection.php` — protocol `<option>` labels ("JSON-RPC (Odoo 17+)", "XML-RPC (Odoo 14+)") now wrapped with `esc_html_e()`

#### Performance — Bulk Import/Export
- `Bulk_Handler` — import and export now paginated in chunks of 500 (was loading all IDs into memory at once); uses `do/while` with offset/page to prevent memory exhaustion on large catalogs
- `Entity_Map_Repository` — 2 new batch methods `get_wp_ids_batch()` and `get_odoo_ids_batch()` for single-query lookups of multiple IDs; eliminates N+1 pattern in bulk operations (was 1 query per product)
- `Bulk_Handler` — import/export now use batch entity_map lookups instead of per-product queries

### Changed

- `ARCHITECTURE.md` — updated directory tree comments for Entity_Map_Repository and Bulk_Handler
- PHPUnit: 356 tests, 704 assertions — all green
- PHPStan: 0 errors on 41 files
- Plugin version bumped from 1.9.2 to 1.9.3

#### Translations
- Regenerated `.pot`, merged `.po`, recompiled `.mo` — 252 translated strings, 0 fuzzy, 0 untranslated (was 249)
- 3 new French translations: Connection tab label, JSON-RPC and XML-RPC protocol labels

## [1.9.2] - 2026-02-10

### Added

#### Tests — Wave 2 (Core Infrastructure + Admin)
- `LoggerTest` — 9 tests for Logger (log levels, context, DB writes)
- `SyncEngineTest` — 8 tests for Sync_Engine (locking, batch processing, backoff, timeout)
- `OdooAuthTest` — 8 tests for Odoo_Auth (encryption, decryption, connection test)
- `OdooClientTest` — 9 tests for Odoo_Client (CRUD methods, lazy connection, error handling)
- `QueryServiceTest` — 6 tests for Query_Service (pagination, filters, empty results)
- `ContactRefinerTest` — 12 tests for Contact_Refiner (name composition/splitting, country/state resolution)
- `SettingsPageTest` — 8 tests for Settings_Page (tabs, sanitize callbacks, settings registration)
- `CLITest` — 16 tests for CLI (all commands: status, test, sync, queue, module)

#### Tests — Wave 3 (Module Handlers)
- `OrderHandlerTest` — 9 tests for Order_Handler (load, save, status mapping, unknown status)
- `ProductHandlerTest` — 7 tests for Product_Handler (load, save, currency guard, delete)
- `ContactManagerTest` — 12 tests for Contact_Manager (load, save, dedup, role sync check)
- `LeadManagerTest` — 10 tests for Lead_Manager (load, save, render, AJAX submission)
- `WebhookHandlerTest` — new test file
- Extensive new stubs in `tests/bootstrap.php`: WP_User, get_userdata, get_user_by, user meta, post functions, WC_Order, wp_remote_post, and more (~40 new function/class stubs)

### Changed

#### Transport — Retryable_Http Trait Extraction
- New file: `includes/api/trait-retryable-http.php` — `Retryable_Http` trait providing `http_post_with_retry()` with 3 attempts, exponential backoff (2^attempt × 500ms), and random jitter (0-1000ms)
- `Odoo_JsonRPC` — now `use Retryable_Http`; retry logic extracted from inline loop in `rpc_call()`
- `Odoo_XmlRPC` — now `use Retryable_Http`; retry logic extracted from inline loop in `xmlrpc_call()`
- `Dependency_Loader` — added `require_once` for `trait-retryable-http.php` before transport classes
- Both transports log warnings on retry and errors after exhaustion with attempt count

#### Sync_Engine — Dynamic Batch Timeout
- Added `BATCH_TIME_LIMIT = 55` constant — queue processing breaks when elapsed time exceeds 55 seconds, deferring remaining jobs to the next cron tick (prevents WP-Cron timeout)

#### Contact_Refiner — Many2one Optimization
- `refine_from_odoo()` now extracts state name directly from Odoo's Many2one tuple `[id, "Name"]` via `Field_Mapper::many2one_to_name()` instead of making an additional API call — eliminates N+1 query pattern on contact pull

#### Order_Handler — Filterable Status Mapping
- `map_odoo_status_to_wc()` changed from match expression to array-based lookup with `apply_filters('wp4odoo_order_status_map', $default_map)` — allows themes/plugins to customize Odoo→WC status mapping

#### Odoo Response Validation — Enhanced Error Context
- `Odoo_JsonRPC` — RPC error logs now include `model` and `method` context from execute_kw calls; `debug` field truncated to 500 chars
- `Odoo_XmlRPC` — XML-RPC fault logs now include `model` and `method` context from execute_kw calls

#### Refactoring — Test Bootstrap Split
- `tests/bootstrap.php` reduced from ~988 lines to ~100 lines (slim orchestrator: constants, global stores, stub loading, plugin class requires)
- Stub code extracted into 6 dedicated files in `tests/stubs/`:
  - `wp-classes.php` — WP_Error, WP_REST_Request, WP_REST_Response, WP_User, WP_CLI, AJAX test exception classes
  - `wp-functions.php` — ~60 WordPress function stubs (options, hooks, sanitization, users, posts, media, HTTP)
  - `wc-classes.php` — WC_Product, WC_Order, WC_Product_Variable, WC_Product_Variation, WC_Product_Attribute + 5 WC function stubs
  - `wp-db-stub.php` — WP_DB_Stub ($wpdb mock with call recording)
  - `plugin-stub.php` — WP4Odoo_Plugin test singleton with module registration and reset
  - `wp-cli-utils.php` — WP_CLI\Utils\format_items namespace stub

#### Documentation
- `ARCHITECTURE.md` — updated directory tree (6 stub files, trait file, 13 new test files), updated transport pattern section with Retryable_Http, updated test counts (356/704), PHPStan file count (41)
- `README.md` — updated PHPStan count (41), added retry trait mention in Dual Transport feature

#### Translations
- Regenerated `.pot`, merged `.po`, recompiled `.mo`

#### Verification
- PHPUnit: 356 tests, 704 assertions — all green (was 138/215)
- PHPStan: 0 errors on 41 files (was 40 — added trait file)
- Plugin version bumped from 1.9.0 to 1.9.2

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

## [1.8.1] - 2026-02-10

### Changed

#### Documentation
- `README.md` — removed ASCII architecture diagram (replaced by `assets/images/architecture.svg` image)

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