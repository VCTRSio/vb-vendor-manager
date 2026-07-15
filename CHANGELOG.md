# Changelog

All notable changes to Vendor Manager are documented here.

## [1.0.2] - 2026-07-14

### Security
- **Fail-closed tenant RLS on all five `vendor_*` tables (closes a cross-tenant leak).**
  v1.0.0/v1.0.1 shipped `vendor_profiles`, `vendor_documents`, `vendor_credentials`,
  `vendor_onboarding`, and `vendor_settings` with **no row-level security**. That was
  harmless when the plugin was first extracted (RLS was not yet enforced), but the core
  now runs as the non-superuser `app_user` under `FORCE ROW LEVEL SECURITY`, and its
  `ALTER DEFAULT PRIVILEGES` auto-grants full DML on any later-created table. A clean
  external install is never swept by core's `enforce_real_rls`, so every vendor table was
  fully readable/writable across **all** tenants — a silent cross-tenant leak. Each
  genesis migration now reproduces core's fail-closed policy verbatim (`ENABLE` +
  `<table>_tenant_isolation` keyed off the `app.tenant_*` GUCs + `FORCE`), plus a
  self-grant to `app_user` when that role exists.
- **RLS is applied OUTSIDE the `Schema::hasTable()` adopt-existing guard.** The genesis
  guard early-returns for a host that already owns the tables, so RLS placed inside it
  would never reach an incumbent (adopted) table — exactly the hosts already exposed. The
  migrations were restructured so `applyRls()` runs whether the table was just created or
  adopted (idempotent: `DROP POLICY IF EXISTS` + `CREATE POLICY`, `ENABLE`/`FORCE` are
  no-ops when already set). `down()` now issues `NO FORCE` + `DROP POLICY` before dropping.
- **Regression coverage.** `tests/VendorRlsIsolationTest.php` proves (a) cross-tenant
  isolation on all five tables in both directions under the `app_user`/FORCE-RLS
  connection, and (b) that the adopt-existing re-run installs the policy + FORCE on a
  forged v1.0.1-style incumbent that started with no RLS.

### Notes
- Schema and behavior are otherwise unchanged; this is a security-only follow-on. No new
  columns, indexes, or composite FKs. Operators upgrading from v1.0.1 simply re-run the
  plugin migrations (the adopt path stamps RLS onto the existing tables in place).

## [1.0.1] - 2026-07-10

### Added
- **Adopt-existing migrations proven for both hosts.** `tests/VendorMigrationsTest.php`
  now covers the existing-host adopt path in addition to the fresh-create path: the
  genesis `if (Schema::hasTable(...)) return;` guard makes re-running the migrations a
  no-op that PRESERVES incumbent rows (a sentinel row survives a second `up()` pass), so
  a host that already owns the vendor tables adopts them rather than dropping/recreating.
  A column-set drift safety net was added to the fresh-create case.
- **Onboarding channel auto-create (best-effort).** `VendorOnboardingController::advance()`
  now mirrors the Next.js core's `getOrCreateVendorChannel`: when a vendor is approved and
  the active tenant is a rooftop, it get-or-creates the vendor's private channel and, on
  first creation, seeds an owner member + a welcome message. This is a **NEW SOFT (optional)
  dependency on the channels plugin** — the block is guarded by `class_exists(...)`,
  references channels classes only via fully-qualified string names (no compile-time
  coupling), and is wrapped in `try/catch` so a channel failure never blocks onboarding.
  **REVISIT:** the clean fix is a `ChannelDirectory::getOrCreateVendorChannel(...)` contract
  exported by the channels plugin (see the "Channels soft-dependency" note in `README.md`).

### Notes
- Upgrade policy documented: the genesis `hasTable` guard is first-install idempotency only;
  future schema changes ship as new, additive, dated migrations — never by editing genesis.

## [1.0.0] - 2026-07-09

### Added
- First-party PHP-native plugin scaffold extracted from the VCTRbase monorepo (`plugins/vendor-manager`), reshaped as a standalone signed release repo in the `Vctrs\Plugins\VbVendorManager` namespace.
- Vendor directory & profiles — CRUD, status lifecycle, soft-delete/restore (`VendorProfile`, `VendorService`).
- Onboarding workflow — stepwise onboarding creation and advancement (`VendorOnboarding`, `OnboardingController`, `VendorOnboardingController`).
- Documents & credentials — per-vendor document and credential add/list/remove with admin soft-delete/restore (`VendorDocument`, `VendorCredential`).
- Compliance settings — COI/contract/credential alert windows and COI/W-9 requirements (admin settings, `vendor.settings.write.rooftop`).
- Vendor API keys — issue/list/revoke per-vendor keys (`vendor.api.manage.rooftop`).
- Reports — contract report endpoint (`vendor.reports.view.rooftop`).
- Dashboard widgets: active vendors, expiring documents, vendors by category, recently onboarded.
- Scheduled jobs: daily expiry check (`0 8 * * *`) and daily escalation check (`0 9 * * *`).
- Module UI mode (`uiMode: "module"`) — ships an ESM entry (`dist/entry.js`) rendered at `/dashboard/plugins/vb-vendor-manager/view`.
- Release tooling copied from the vb-prana-buzz skeleton: signed release artifact via `tools/sign.php` + `tools/verify.php`.
