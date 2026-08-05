# vb-vendor-manager Expand (Linking) — Design

**Date:** 2026-08-04
**Plugin:** `vb-vendor-manager` (namespace `Vctrs\Plugins\VbVendorManager`), v1.0.2 → **v1.1.0**
**Branch:** `feat/expand-linking` (base `main` `3f11897`)
**Recon:** `../../vctrbase-php/.superpowers/sdd/vendor-manager-recon.md` (facts + file:lines)
**Research:** `../../vctrbase-php/docs/research/marketplace/vb-vendor-manager.md`

## Goal

Give vendors first-class cross-plugin linking without touching the host core. Wire the
two dead `vault_document_id` pointers to the vault, build a staff account-rep link,
publish a PII-free `VendorDirectory` read seam, and retire the plugin's one flagged
impurity (the hand-duplicated channels block) by adopting the host `ChannelDirectory`
seam that already ships.

## Scope (owner-approved)

**In (all Additive, zero-core):** A. Vault evidence ×2 · B. Channels seam adoption ·
C. `VendorDirectory` read seam · D. Staff account-rep edge (build).
**Deferred:** E. Expiry-scheduler adoption (vendor's jobs are strictly richer than the
core `ExpiryAlertScheduler` contract — settings-driven multi-tier COI thresholds, a Feed
event per alert, and a separate escalation job with no core analogue; adopting would lose
capability, same verdict as oem-cert). Logged to the deferred-work backlog.

## Global constraints

- **Core Change Firewall — ZERO edits to `../../vctrbase-php`.** Audit
  `git -C ../../vctrbase-php status --porcelain` empty after every task. Docs/tests live
  in the plugin repo (firewall-exempt).
- **Additive only.** No relaxation of RLS/tenant isolation. No direct imports of sibling
  plugin models — consume only the sanctioned host seams via `app()`:
  `App\Support\EntityReferenceService`, `Vctrs\Plugins\Vault\VaultDirectory`,
  `Vctrs\Plugins\StaffHub\StaffDirectory`, `Vctrs\Plugins\Channels\ChannelDirectory`,
  `App\Support\TenantContext`. Optional-plugin seams are guarded (`class_exists` +
  `app()->bound(...)`); when a seam is unavailable the feature degrades (empty list /
  null / skip), never throws.
- **Namespace is `Vctrs\Plugins\VbVendorManager`** (the legacy in-tree `VendorManager`
  is a different plugin the harness deletes from the throwaway worktree).
- `EntityReferenceService::link()` does NOT validate the relation string against core
  `EntityRelation`; relation vocabulary is therefore a **plugin-local** class, no core
  const needed (same pattern as `OemCertRelation`/`RecallRelation`/`LoanerRelation`).

## Host seam signatures (confirmed at MAIN HEAD)

- `EntityReferenceService::link($tt,$tid,$srcType,$srcId,$tgtType,$tgtId,$relation,$createdBy=null): string`
  (idempotent firstOrCreate); `forSource($tt,$tid,$srcType,$srcId): list`; `unlink(...): void`;
  `resolveManyForSources($tt,$tid,$srcType,array $srcIds)`.
- `VaultDirectory::eligibleDocuments($tt,$tid,$limit=100): array`; `lookup($tt,$tid,$id): ?array`;
  SAFE_FIELDS `[id,title,document_class,current_version]`. Read-only.
- `StaffDirectory::listAssignable($tt,$tid,?$dept,?$search,$limit=100): array`;
  `lookup($tt,$tid,$id): ?array` (returns `StaffHubEmployee::SAFE_FIELDS` — has `display_name`,
  `department_name`; NO email).
- `ChannelDirectory::getOrCreateVendorChannel(string $rooftopId, string $vendorId, string $vendorName, ?string $createdBy = null): Channel`
  (`plugins/channels/src/ChannelDirectory.php:30`; runs inside `SystemContext::runAsTenant`,
  idempotent lookup-or-create + owner member + welcome message).

## Component A — Vault evidence ×2

Both `vendor_documents.vault_document_id` (migration `:33`, written raw at
`VendorDocumentController::add():40`) and `vendor_credentials.vault_document_id`
(migration `:36`, `VendorCredentialController::add():34`) are dead pointers: accepted from
the request, never resolved against the vault.

- **Relation vocab:** `VendorRelation::EVIDENCE = 'evidence'`; source types
  `DOC_SOURCE_TYPE = 'vb-vendor-manager.document'`, `CRED_SOURCE_TYPE = 'vb-vendor-manager.credential'`;
  target `VAULT_TARGET_TYPE = 'vault.document'`.
- **Picker route:** `GET dashboard/vendor/api/vault-documents` (gated `vendor.manage.rooftop`)
  → `{documents:[{id,title,document_class,current_version}]}` via `VaultDirectory::eligibleDocuments`,
  guarded — returns `{documents:[]}` when vault unavailable.
- **Edge on add:** in both `add()` methods, when `vaultDocumentId` is a non-empty string,
  write the `evidence` edge (source = the new document/credential id → `vault.document`),
  wrapped with the row create in one `DB::transaction`.
- **Set-evidence endpoint (per type):** `PATCH dashboard/vendor/api/documents/{id}/evidence`
  and `.../credentials/{id}/evidence` (gated `vendor.documents.write.rooftop` /
  `vendor.manage.rooftop`) accepting `{vaultDocumentId: uuid|null}`. Captures the previous
  id, and in one transaction updates the column + reconciles the edge (unlink old when it
  changed, link new when non-empty). This is the UI's link/re-point/clear control — the
  plugin has no update-document endpoint today, so this is the round-trip path (mirrors
  oem-cert's `update()` reconcile).
- **Read enrichment:** `VendorReadController::get()` attaches a resolved `evidence`
  object (`{id,title,document_class,current_version}` or `null`) to each document and
  credential row via `VaultDirectory::lookup`, guarded/null-safe.

## Component B — Channels seam adoption (full purge)

`VendorOnboardingController::advance()` currently hand-duplicates the channels
create-or-get + member + welcome-message logic via string-class names
(`VendorOnboardingController.php:61-107`), guarded by `class_exists` + try/catch. The host
already ships `ChannelDirectory::getOrCreateVendorChannel` (the exact seam the plugin's own
comment asks for).

- Replace lines 61-107 with a single late-bound call:
  `app('Vctrs\\Plugins\\Channels\\ChannelDirectory')->getOrCreateVendorChannel($rooftopId, (string)$vendor->id, (string)$vendor->company_name, $ctx->userId() ?: null)`.
- Keep the `class_exists('Vctrs\\Plugins\\Channels\\ChannelDirectory')` guard + try/catch
  so a missing channels plugin still never blocks onboarding. Deletes ~45 lines. No
  behavior change (the seam is byte-for-byte the same lookup/seed) — note as a refactor in
  the CHANGELOG.

## Component C — VendorDirectory read seam

New PII-free outbound contract, mirroring `VaultDirectory`/`StaffDirectory`.

- `src/VendorDirectory.php` (namespace `Vctrs\Plugins\VbVendorManager`), singleton-bound in
  `VendorManagerServiceProvider::register()`.
- **Narrowed** SAFE_FIELDS (deliberately tighter than `VendorProfile::SAFE_FIELDS`, dropping
  `contact_email`/`contact_phone`/`notes`): `['id','company_name','category','status','has_active_contract','coi_expiry_date']`.
- Methods: `lookup($tt,$tid,$id): ?array` (single vendor, active-scoped, narrow fields) and
  `listActive($tt,$tid,?string $category = null, int $limit = 100): array`. Both use
  `VendorProfile::withoutTenantScope()->where('tenant_type',$tt)->where('tenant_id',$tid)`
  + the plugin's active/not-soft-deleted scope, projecting only the narrow field set.

## Component D — Staff account-rep (build)

`vendor_profiles` has no rep/owner column, so this builds the capability.

- **Additive plugin migration** (dated, follows the plugin's documented additive-migration
  convention): add nullable `account_rep_employee_id` (uuid) to `vendor_profiles`. Add it to
  `VendorProfile` fillable + SAFE_FIELDS. Plugin owns its own schema → zero-core.
- **Relation vocab:** `VendorRelation::ACCOUNT_REP = 'account_rep'`; source type
  `PROFILE_SOURCE_TYPE = 'vb-vendor-manager.profile'`; target `STAFF_TARGET_TYPE = 'staff.employee'`.
- **Assign endpoint:** `PUT dashboard/vendor/api/{vendorId}/account-rep` (gated
  `vendor.manage.rooftop`) accepting `{employeeId: uuid|null}`. In one transaction: set
  `account_rep_employee_id`, and reconcile the `profile→staff.employee 'account_rep'` edge
  (unlink previous when changed, link new when non-empty).
- **Staff picker route:** `GET dashboard/vendor/api/assignable-staff` (gated
  `vendor.manage.rooftop`) → `{employees:[{id,display_name}]}` via
  `StaffDirectory::listAssignable`, guarded — `{employees:[]}` when staff-hub unavailable.
- **Read enrichment:** resolve the rep's `display_name` **live** via `StaffDirectory::lookup`
  (no name-cache column → no drift). `VendorReadController::get()` attaches an `accountRep`
  object (`{id,display_name}` or `null`). `list()` resolves reps without N+1 — one
  `StaffDirectory::listAssignable` call built into an id→display_name map (or skip rep in
  list and show it only in detail; implementer picks the simpler correct path, no per-row
  lookup loop).

## UI (read-only today → enriched DetailView)

`ui/entry.tsx` (305 lines, `R.createElement` idiom, host-injected React/ui kit, no JSX) is a
read/browse surface with no manage forms. This pass enriches `DetailView` only — no full
add-form build:

- Each document / credential row: show resolved evidence (`Certificate: <title> (v<n>)`)
  when linked; a vault picker (from `GET vault-documents`) that calls the set-evidence PATCH
  to link/re-point/clear.
- Header: the resolved account rep (`Rep: <display_name>`) and a staff picker (from
  `GET assignable-staff`) that calls the assign endpoint.
- Pickers hide gracefully when their seam returns an empty list. Match the file's existing
  `getJson`/`R.createElement` idiom; no new dependencies. `dist/entry.js` rebuilt via
  `npm run build`, NOT committed (gitignored).

## Testing

Harness `bash scripts/test-in-app.sh [path]` (worktree `../../vctrbase-php-vendor-test` @
MAIN HEAD, DB `vctrs_test_vendor`, bootstrap `vm_bootstrap.php`). New Pest feature tests per
component (edge write/reconcile, picker routes, VendorDirectory field-narrowing + active
scoping, channels-seam adoption, read enrichment); UI vitest for the pickers + resolved
displays. Re-run signing tests (`SignedInstallBootTest`, `SigningByteCompatTest`) after the
version bump. Fake the optional seams (Vault/Staff/Channel directories) via anonymous
subclasses bound with `app()->instance(...)` so tests don't depend on sibling-plugin schema.

## Non-goals / deferred

- Expiry-scheduler adoption (E) — deferred (capability-losing).
- Vendor self-service portal, COI OCR/verification, DMS/AP reconciliation, structured
  OEM-cert picker — data-entry/ownership opportunities from the research doc, out of scope
  for a linking pass; candidates for the deferred-work backlog.
- Inventory-hub vendor-as-source linking — a plausible future edge once a consuming surface
  exists; not in this pass.

## SDD shape (~5 tasks + final gate)

1. **T1** — `VendorRelation` vocab + Channels seam adoption (B, full purge).
2. **T2** — Vault evidence ×2 server (A): picker route + edge-on-add + set-evidence PATCH ×2 + read enrichment.
3. **T3** — Staff account-rep build (D): additive migration + assignable-staff route + assign endpoint + edge + live read enrichment.
4. **T4** — `VendorDirectory` read seam (C) + singleton bind.
5. **T5** — UI DetailView enrichment (vault pickers ×2 + rep picker + resolved displays) + vitest + build.

Final gate: manifest v1.1.0 + CHANGELOG (Added/Changed) + full suite + re-run signing tests
+ whole-branch Opus review → stop for owner Touchpoint 5.
