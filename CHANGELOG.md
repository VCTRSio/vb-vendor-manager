# Changelog

All notable changes to Vendor Manager are documented here.

## [1.1.2] - 2026-08-17

Source-hygiene release. No behaviour change, no schema change, no stored-data change.

### Changed

- **Relation vocabulary now aliases core.** `VendorRelation::EVIDENCE` and `::ACCOUNT_REP`
  reference `App\Support\EntityRelation`'s constants instead of redeclaring their string
  literals (`EVIDENCE` was always core vocabulary; `ACCOUNT_REP` was promoted in Track-B
  S1). The promoted values are byte-identical, so this is a no-op on disk — every existing
  `entity_references.relation` row is unaffected and no migration is required. The
  `*_SOURCE_TYPE` / `*_TARGET_TYPE` constants stay plugin-local: they are entity-type
  identifiers, not relations, and core has no registry for them.
  `EntityReferenceService::link()` still does not validate against the registry, so this
  remains a canonical vocabulary rather than a gate.

### Fixed

- **Test mocks track the current core `StaffDirectory` seam.** Track-B S4 added a fourth
  `bool $includePii = false` parameter to `StaffDirectory::lookup()`. The anonymous
  `StaffDirectory` subclass in `VendorAccountRepTest` still declared the old 3-argument
  signature, which raised a fatal "declaration must be compatible" error against core
  ≥ `3eaaca0`. Signature updated. The `VaultDirectory` and `ChannelDirectory` mocks were
  checked and already match current core. Test-only — no shipped source was affected.

### Added

- `VendorRelationVocabTest` pins the literal on-disk relation strings, so a future change
  to a core constant's value cannot silently rewrite the edges this plugin writes.

## [1.1.1] - 2026-08-05

Fast-follow cleanup on the 1.1.0 cross-plugin linking pass. Additive, zero core changes.

### Fixed
- **Picker mutations surface failures instead of swallowing them.** The CSRF-aware
  `sendJson` helper's callers in the detail view (vault-evidence pickers on documents
  and credentials, and the account-rep picker) previously `.catch(() => {})`'d a
  rejected write, leaving the user with no feedback. They now log to the console and
  render the shared error banner, and no longer bump/reload on failure — so the UI
  never flips to a false "saved" state on a rejected mutation.

### Changed
- **Style: `ordered_imports`.** Re-ordered the controller `use` statements in
  `src/routes.php` so the file is Pint-clean (`VendorApiKeyController` was out of
  alphabetical order).

### Added
- **Direct authorization tests on the evidence and account-rep routes.** Added tests
  asserting that a user lacking the route's `can:` grant gets 403 directly on
  `PATCH .../documents/{id}/evidence` (`vendor.documents.write.rooftop`),
  `PATCH .../credentials/{id}/evidence` (`vendor.manage.rooftop`), and
  `PUT .../{vendorId}/account-rep` (`vendor.manage.rooftop`) — no longer relying only
  on sibling routes that share the same permission slug.

## [1.1.0] - 2026-08-04

Cross-plugin linking (Expand pass). All additive, zero core changes.

### Added
- **Vault evidence on documents and credentials.** The two `vault_document_id`
  pointers are now live links to the host vault. A picker route
  (`GET dashboard/vendor/api/vault-documents`) lists eligible vault documents; adding a
  document or credential with a `vaultDocumentId` writes a `source → vault.document`
  `evidence` entity-reference edge (create + edge in one transaction); new
  `PATCH .../documents/{id}/evidence` and `PATCH .../credentials/{id}/evidence` endpoints
  link, re-point, and clear the evidence (column update + edge reconcile in one
  transaction). The detail read resolves each row's linked certificate
  (`{title, current_version, …}`) live. The vault seam is optional — every path degrades
  to an empty list / null when the vault plugin is not installed.
- **Staff account-rep assignment.** A new nullable `account_rep_employee_id` column
  (additive, idempotent migration; no cross-plugin foreign key) plus
  `PUT dashboard/vendor/api/{vendorId}/account-rep` and a staff picker
  (`GET dashboard/vendor/api/assignable-staff`). Assigning a rep sets the column and
  reconciles a `profile → staff.employee` `account_rep` edge in one transaction; the
  rep's display name is resolved live from the staff-hub directory at read time (no cached
  name → no drift). The staff seam is optional and degrades to empty / null.
- **`VendorDirectory` PII-free read seam** (`Vctrs\Plugins\VbVendorManager\VendorDirectory`,
  singleton-bound) — `lookup()` and `listActive()` for other plugins/core, projecting only
  `id, company_name, category, status, has_active_contract, coi_expiry_date` (deliberately
  narrower than `SAFE_FIELDS`; never exposes contact email/phone/notes), tenant-scoped and
  soft-delete-aware, returning plain arrays.
- **Detail-view UI:** per-row vault-evidence pickers on documents and credentials, a
  header account-rep picker, and inline display of the resolved certificate and rep.

### Changed
- **Vendor-approval channel creation now consumes the host `ChannelDirectory` seam.**
  `VendorOnboardingController` previously hand-duplicated the channels plugin's
  create-or-get + member + welcome-message logic; it now calls
  `ChannelDirectory::getOrCreateVendorChannel` (the exact seam the channels plugin
  exports), guarded by `class_exists` + try/catch so a missing channels plugin still never
  blocks onboarding. No behavior change — the hand-rolled block was removed, not left
  alongside.

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
