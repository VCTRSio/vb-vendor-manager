# vb-vendor-manager Expand (Linking) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give vendors first-class cross-plugin linking — vault evidence on documents + credentials, a staff account-rep link, a PII-free `VendorDirectory` read seam — and retire the hand-duplicated channels block by adopting the host `ChannelDirectory` seam. All Additive, zero core edits.

**Architecture:** Consume host seams via `app()` only (`EntityReferenceService`, `VaultDirectory`, `StaffDirectory`, `ChannelDirectory`, `TenantContext`). Relation vocabulary is a plugin-local class (`link()` does not validate against core `EntityRelation`). Optional-plugin seams are guarded and degrade gracefully. Read enrichment resolves linked artifacts at read time.

**Tech Stack:** PHP 8 / Laravel (plugin runtime), Pest (`scripts/test-in-app.sh`), React via host bridge + `R.createElement` (no JSX), vitest.

## Global Constraints

- **Core Change Firewall:** ZERO edits under `/home/carmelo/Work/VCTRS/vctrbase-php`. After every task, `git -C ../../vctrbase-php status --porcelain` MUST be empty. Plugin `tests/` are firewall-exempt.
- **Namespace:** `Vctrs\Plugins\VbVendorManager` (NOT the legacy `VendorManager`).
- **No sibling-plugin model imports.** Only host seams via `app()`. Guard optional seams with `class_exists(...) && app()->bound(...)`; on unavailable, return empty/`null`/skip — never throw.
- **Additive only.** No RLS/tenant-isolation changes. Plugin owns its own schema (new columns ship as new dated additive migrations, idempotent via `Schema::hasColumn`).
- **Test harness:** run from the plugin repo with **bash**: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/<File>.php`. The harness syncs `tests/` into `tests/Feature/Plugins/VbVendorManager/`, so the path arg carries that prefix; the file you create lives at `tests/<File>.php`. `vm_bootstrap.php` provides `vmFeatureUser($overrides)` (installs+boots the signed plugin, returns a user for `actingAs`), `vmBindTenant`, `vmRunMigrations`, `pluginTestUser`, `PLUGIN_TEST_TENANT`. New feature tests MUST mirror the permission-override setup in the existing `VendorDocumentTest.php` / `VendorCredentialTest.php` / `VendorReadTest.php` (they exercise the same `vendor.*` gates).
- **Response envelope:** raw `response()->json(['data' => [...]], $status)` — no `ApiResponse` helper. Match the existing `['data' => ['document' => ...]]` shape.
- **`dist/entry.js` is gitignored — rebuild via `npm run build`, never `git add` it.**

## Host seam signatures (confirmed at MAIN HEAD)

- `app(App\Support\EntityReferenceService::class)`:
  - `link(string $tt,string $tid,string $srcType,string $srcId,string $tgtType,string $tgtId,string $relation,?string $createdBy=null): string` (idempotent firstOrCreate)
  - `unlink(string $tt,string $tid,string $srcType,string $srcId,string $tgtType,string $tgtId,string $relation): void`
  - `forSource(string $tt,string $tid,string $srcType,string $srcId): array` (list of `{target_type,target_id,relation}`)
- `app(Vctrs\Plugins\Vault\VaultDirectory::class)`: `eligibleDocuments(string $tt,string $tid,int $limit=100): array`; `lookup(string $tt,string $tid,string $id): ?array`. Fields: `id,title,document_class,current_version`.
- `app(Vctrs\Plugins\StaffHub\StaffDirectory::class)`: `listAssignable(string $tt,string $tid,?string $dept=null,?string $search=null,int $limit=100): array`; `lookup(string $tt,string $tid,string $id): ?array`. Each row has `id`, `display_name`, `department_name` (NO email).
- `app('Vctrs\\Plugins\\Channels\\ChannelDirectory')->getOrCreateVendorChannel(string $rooftopId,string $vendorId,string $vendorName,?string $createdBy=null): Channel`.

---

### Task 1: `VendorRelation` vocab + adopt the `ChannelDirectory` seam

**Files:**
- Create: `src/Support/VendorRelation.php`
- Modify: `src/Http/Controllers/VendorOnboardingController.php` (replace the hand-rolled channels block, lines 58-112, with one seam call)
- Test: `tests/VendorChannelSeamTest.php` (new)

**Interfaces:**
- Produces: `Vctrs\Plugins\VbVendorManager\Support\VendorRelation` with consts `EVIDENCE='evidence'`, `ACCOUNT_REP='account_rep'`, `DOC_SOURCE_TYPE='vb-vendor-manager.document'`, `CRED_SOURCE_TYPE='vb-vendor-manager.credential'`, `PROFILE_SOURCE_TYPE='vb-vendor-manager.profile'`, `VAULT_TARGET_TYPE='vault.document'`, `STAFF_TARGET_TYPE='staff.employee'`. Consumed by Tasks 2, 3, 4.

- [ ] **Step 1: Write `VendorRelation`**

Create `src/Support/VendorRelation.php`:

```php
<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager\Support;

/**
 * Plugin-local relation vocabulary for App\Support\EntityReferenceService edges.
 * Values intentionally match core App\Support\EntityRelation's shared vocabulary
 * ('evidence'); link() does not validate against the core enum, so we redeclare here
 * to keep this Expand pass self-contained and zero-core.
 */
final class VendorRelation
{
    /** vendor document / credential → the vault document that is its evidence. */
    public const EVIDENCE = 'evidence';

    /** vendor profile → the staff employee who is its internal account rep. */
    public const ACCOUNT_REP = 'account_rep';

    public const DOC_SOURCE_TYPE = 'vb-vendor-manager.document';

    public const CRED_SOURCE_TYPE = 'vb-vendor-manager.credential';

    public const PROFILE_SOURCE_TYPE = 'vb-vendor-manager.profile';

    public const VAULT_TARGET_TYPE = 'vault.document';

    public const STAFF_TARGET_TYPE = 'staff.employee';
}
```

- [ ] **Step 2: Write the failing channels-seam test**

Create `tests/VendorChannelSeamTest.php`. It binds a fake `ChannelDirectory` (the real class lives on the host and is present in the test worktree) via `app()->instance(...)` to assert the controller calls the seam exactly once with the right args on approval, and does NOT hand-create Channel rows. Mirror `VendorOnboardingTest.php`'s setup (same `vmFeatureUser` + `vendor.onboard.rooftop` grant + a seeded pending vendor).

```php
<?php

declare(strict_types=1);

use Vctrs\Plugins\Channels\ChannelDirectory;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

it('adopts ChannelDirectory::getOrCreateVendorChannel on vendor approval', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.write.rooftop', '+vb-vendor-manager.onboard.rooftop']);

    $vendor = VendorProfile::create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'company_name' => 'Acme Parts', 'category' => 'aftermarket', 'status' => 'pending',
    ]);

    $calls = [];
    app()->instance(ChannelDirectory::class, new class($calls) extends ChannelDirectory {
        public function __construct(public array &$calls) {}

        public function getOrCreateVendorChannel(string $rooftopId, string $vendorId, string $vendorName, ?string $createdBy = null): \Vctrs\Plugins\Channels\Models\Channel
        {
            $this->calls[] = [$rooftopId, $vendorId, $vendorName, $createdBy];

            return new \Vctrs\Plugins\Channels\Models\Channel;
        }
    });

    $res = $this->actingAs($user)->postJson("/dashboard/vendor/api/{$vendor->id}/onboarding", [
        'step' => 'approved',
    ]);

    $res->assertOk();
    expect($calls)->toHaveCount(1)
        ->and($calls[0][1])->toBe((string) $vendor->id)
        ->and($calls[0][2])->toBe('Acme Parts');
});
```

- [ ] **Step 3: Run it — expect FAIL**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorChannelSeamTest.php`
Expected: FAIL — the current controller never calls the seam (it hand-rolls Channel writes), so `$calls` is empty.

- [ ] **Step 4: Replace the hand-rolled channels block with the seam call**

In `src/Http/Controllers/VendorOnboardingController.php`, replace the entire block currently at lines 42-112 (the big comment + the `if ($v['step'] === 'approved' && ... class_exists('...\\Models\\Channel')) { try { ... hand-rolled ... } catch }`) with:

```php
        // Vendor-approval channel (best-effort). The host channels plugin exports
        // ChannelDirectory::getOrCreateVendorChannel — the exact seam this plugin's
        // README asked for — so we consume it via late-bound class name (no compile-time
        // coupling, PHPStan stays quiet) instead of hand-duplicating the Channel/member/
        // welcome-message writes. Guarded: only when channels is installed and the active
        // tenant is a rooftop; wrapped in try/catch so a channel failure never blocks
        // onboarding.
        if ($v['step'] === 'approved' && $ctx->activeTenantType() === 'rooftop'
            && class_exists('Vctrs\\Plugins\\Channels\\ChannelDirectory')) {
            try {
                app('Vctrs\\Plugins\\Channels\\ChannelDirectory')->getOrCreateVendorChannel(
                    $ctx->activeTenantId(),
                    (string) $vendor->id,
                    (string) $vendor->company_name,
                    $uid !== '' ? $uid : null,
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }
```

Leave everything else in `advance()` (the onboarding-row create, the status update, the `FeedEventRequested` block, the return) unchanged.

- [ ] **Step 5: Run it — expect PASS**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorChannelSeamTest.php`
Expected: PASS. Then run `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorOnboardingTest.php` to confirm no onboarding regression.

- [ ] **Step 6: Commit**

```bash
git add src/Support/VendorRelation.php src/Http/Controllers/VendorOnboardingController.php tests/VendorChannelSeamTest.php
git commit -m "feat(linking): VendorRelation vocab + adopt ChannelDirectory seam (Expand T1)"
```

---

### Task 2: Vault evidence ×2 (server)

**Files:**
- Create: `src/Http/Controllers/Concerns/ResolvesVaultEvidence.php` (shared trait)
- Modify: `src/Http/Controllers/VendorDocumentController.php` (edge on add + `vaultDocuments()` picker + `setEvidence()`)
- Modify: `src/Http/Controllers/VendorCredentialController.php` (edge on add + `setEvidence()`)
- Modify: `src/Http/Controllers/VendorReadController.php` (`get()` read enrichment)
- Modify: `src/routes.php` (3 routes)
- Test: `tests/VendorVaultEvidenceTest.php` (new)

**Interfaces:**
- Consumes: `VendorRelation` (T1), `EntityReferenceService`, `VaultDirectory`.
- Produces: `GET dashboard/vendor/api/vault-documents` → `{documents:[{id,title,document_class,current_version}]}` (empty when vault unavailable). `POST …/documents` and `…/credentials` write a `<source> → vault.document 'evidence'` edge when `vaultDocumentId` is present. `PATCH …/documents/{id}/evidence` and `…/credentials/{id}/evidence` set/re-point/clear the column + reconcile the edge. `VendorReadController::get()` document + credential rows each carry an `evidence` key (`{id,title,document_class,current_version}` or `null`).

- [ ] **Step 1: Write the shared trait**

Create `src/Http/Controllers/Concerns/ResolvesVaultEvidence.php`:

```php
<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers\Concerns;

use App\Support\EntityReferenceService;
use App\Support\TenantContext;
use Vctrs\Plugins\Vault\VaultDirectory;
use Vctrs\Plugins\VbVendorManager\Support\VendorRelation;

trait ResolvesVaultEvidence
{
    protected function vaultDirectoryAvailable(): bool
    {
        return class_exists(VaultDirectory::class) && app()->bound(VaultDirectory::class);
    }

    /**
     * @return array{id: string, title: string, document_class: string, current_version: int}|null
     */
    protected function resolveEvidence(?string $vaultDocumentId, TenantContext $ctx): ?array
    {
        if ($vaultDocumentId === null || $vaultDocumentId === '' || ! $this->vaultDirectoryAvailable()) {
            return null;
        }

        return app(VaultDirectory::class)->lookup($ctx->activeTenantType(), $ctx->activeTenantId(), $vaultDocumentId);
    }

    /**
     * Idempotently reconcile a source → vault.document 'evidence' edge: unlink the old
     * target when it changed, link the new target when it is a non-empty string.
     */
    protected function reconcileEvidenceEdge(TenantContext $ctx, string $sourceType, string $sourceId, ?string $previousVaultId, ?string $newVaultId): void
    {
        $refs = app(EntityReferenceService::class);
        $tt = $ctx->activeTenantType();
        $tid = $ctx->activeTenantId();
        $createdBy = $ctx->userId() !== '' ? $ctx->userId() : null;

        if ($previousVaultId !== null && $previousVaultId !== '' && $previousVaultId !== $newVaultId) {
            $refs->unlink($tt, $tid, $sourceType, $sourceId, VendorRelation::VAULT_TARGET_TYPE, $previousVaultId, VendorRelation::EVIDENCE);
        }
        if ($newVaultId !== null && $newVaultId !== '') {
            $refs->link($tt, $tid, $sourceType, $sourceId, VendorRelation::VAULT_TARGET_TYPE, $newVaultId, VendorRelation::EVIDENCE, $createdBy);
        }
    }
}
```

- [ ] **Step 2: Write the failing vault-evidence test**

Create `tests/VendorVaultEvidenceTest.php`. Bind a fake `VaultDirectory` so the tests don't need the vault schema; seed a vendor + documents/credentials directly; drive the endpoints over HTTP. Mirror `VendorDocumentTest.php`'s permission grants.

```php
<?php

declare(strict_types=1);

use App\Support\EntityReferenceService;
use App\Support\TenantContext;
use Vctrs\Plugins\Vault\VaultDirectory;
use Vctrs\Plugins\VbVendorManager\Models\VendorCredential;
use Vctrs\Plugins\VbVendorManager\Models\VendorDocument;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;
use Vctrs\Plugins\VbVendorManager\Support\VendorRelation;

require_once __DIR__.'/vm_bootstrap.php';

function vmFakeVault(): void
{
    app()->instance(VaultDirectory::class, new class extends VaultDirectory {
        public function lookup(string $tenantType, string $tenantId, string $id): ?array
        {
            return ['id' => $id, 'title' => 'COI '.substr($id, 0, 4), 'document_class' => 'certificate', 'current_version' => 3];
        }

        public function eligibleDocuments(string $tenantType, string $tenantId, int $limit = 100): array
        {
            return [['id' => '11111111-1111-4111-8111-111111111111', 'title' => 'COI Acme', 'document_class' => 'certificate', 'current_version' => 1]];
        }
    });
}

function vmDocEdges(string $docId): array
{
    $ctx = app(TenantContext::class);

    return app(EntityReferenceService::class)->forSource($ctx->activeTenantType(), $ctx->activeTenantId(), VendorRelation::DOC_SOURCE_TYPE, $docId);
}

it('lists eligible vault documents from the picker route', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.write.rooftop', '+vb-vendor-manager.manage.rooftop']);
    vmFakeVault();

    $res = $this->actingAs($user)->getJson('/dashboard/vendor/api/vault-documents');

    $res->assertOk();
    expect($res->json('data.documents'))->toHaveCount(1)
        ->and($res->json('data.documents.0.title'))->toBe('COI Acme');
});

it('returns an empty picker list when vault is unavailable', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.write.rooftop', '+vb-vendor-manager.manage.rooftop']);
    // no vmFakeVault(): the real VaultDirectory is unbound/absent in the harness worktree.

    $res = $this->actingAs($user)->getJson('/dashboard/vendor/api/vault-documents');

    $res->assertOk();
    expect($res->json('data.documents'))->toBe([]);
});

it('writes an evidence edge when a document is added with a vaultDocumentId', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.write.rooftop']);
    vmFakeVault();
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active']);
    $docId = '22222222-2222-4222-8222-222222222222';

    $res = $this->actingAs($user)->postJson("/dashboard/vendor/api/{$vendor->id}/documents", [
        'documentType' => 'coi', 'vaultDocumentId' => $docId,
    ]);
    $res->assertCreated();

    $edges = vmDocEdges((string) $res->json('data.document.id'));
    expect($edges)->toHaveCount(1)
        ->and($edges[0]['target_id'])->toBe($docId)
        ->and($edges[0]['relation'])->toBe(VendorRelation::EVIDENCE);
});

it('re-points and clears the evidence edge via setEvidence', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.write.rooftop']);
    vmFakeVault();
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active']);
    $doc = VendorDocument::create(['vendor_id' => $vendor->id, 'document_type' => 'coi', 'vault_document_id' => null]);
    $doc1 = '33333333-3333-4333-8333-333333333333';
    $doc2 = '44444444-4444-4444-8444-444444444444';

    $this->actingAs($user)->patchJson("/dashboard/vendor/api/documents/{$doc->id}/evidence", ['vaultDocumentId' => $doc1])->assertOk();
    expect(array_column(vmDocEdges((string) $doc->id), 'target_id'))->toBe([$doc1]);

    $this->actingAs($user)->patchJson("/dashboard/vendor/api/documents/{$doc->id}/evidence", ['vaultDocumentId' => $doc2])->assertOk();
    expect(array_column(vmDocEdges((string) $doc->id), 'target_id'))->toBe([$doc2]);

    $this->actingAs($user)->patchJson("/dashboard/vendor/api/documents/{$doc->id}/evidence", ['vaultDocumentId' => null])->assertOk();
    expect(vmDocEdges((string) $doc->id))->toBe([]);
});

it('enriches get() document + credential rows with resolved evidence', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.write.rooftop', '+vb-vendor-manager.manage.rooftop']);
    vmFakeVault();
    $docId = '55555555-5555-4555-8555-555555555555';
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active']);
    VendorDocument::create(['vendor_id' => $vendor->id, 'document_type' => 'coi', 'vault_document_id' => $docId]);
    VendorCredential::create(['vendor_id' => $vendor->id, 'credential_type' => 'other', 'credential_name' => 'Lic', 'vault_document_id' => null]);

    $res = $this->actingAs($user)->getJson("/dashboard/vendor/api/{$vendor->id}");

    $res->assertOk();
    expect($res->json('data.documents.0.evidence.title'))->toBe('COI '.substr($docId, 0, 4))
        ->and($res->json('data.credentials.0.evidence'))->toBeNull();
});
```

- [ ] **Step 3: Run it — expect FAIL**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorVaultEvidenceTest.php`
Expected: FAIL — routes 404 / no evidence key.

- [ ] **Step 4: Add the routes**

In `src/routes.php`, inside the group, add (place the two static `GET`s before the `GET /api/{id}` catch-all is unnecessary — `{id}` is constrained to `[0-9a-f-]{36}` so "vault-documents" cannot match — but add them near the other document/credential routes for clarity):

```php
    Route::get('/api/vault-documents', [VendorDocumentController::class, 'vaultDocuments'])
        ->middleware('can:vendor.manage.rooftop')->name('api.vault.documents');
    Route::patch('/api/documents/{id}/evidence', [VendorDocumentController::class, 'setEvidence'])
        ->middleware('can:vendor.documents.write.rooftop')->where('id', '[0-9a-f-]{36}')->name('api.documents.evidence');
    Route::patch('/api/credentials/{id}/evidence', [VendorCredentialController::class, 'setEvidence'])
        ->middleware('can:vendor.manage.rooftop')->where('id', '[0-9a-f-]{36}')->name('api.credentials.evidence');
```

- [ ] **Step 5: Wire `VendorDocumentController`**

Add `use` imports: `Illuminate\Support\Facades\DB;`, `App\Support\EntityReferenceService;` (only if referenced directly — the trait handles edges, so not needed here), `Vctrs\Plugins\VbVendorManager\Http\Controllers\Concerns\ResolvesVaultEvidence;`, `Vctrs\Plugins\VbVendorManager\Support\VendorRelation;`, `Vctrs\Plugins\Vault\VaultDirectory;`. Add `use ResolvesVaultEvidence;` inside the class.

In `add()`, wrap the create + edge in a transaction and write the edge. Replace the create block (currently lines 35-43, the `AuditContext::tag` through the `VendorDocument::create([...])`) so the create happens inside `DB::transaction` and, after it, the edge is reconciled:

```php
        $ctx = app(TenantContext::class);
        $doc = DB::transaction(function () use ($vendor, $v, $uid, $ctx) {
            AuditContext::tag('document.add');
            $doc = VendorDocument::create([
                'vendor_id' => $vendor->id,
                'document_type' => $v['documentType'],
                'document_name' => $v['documentName'] ?? null,
                'vault_document_id' => $v['vaultDocumentId'] ?? null,
                'expires_at' => ! empty($v['expiresAt']) ? Carbon::parse($v['expiresAt']) : null,
                'uploaded_by' => $uid,
            ]);

            $this->reconcileEvidenceEdge($ctx, VendorRelation::DOC_SOURCE_TYPE, (string) $doc->id, null, $v['vaultDocumentId'] ?? null);

            return $doc;
        });
```

Keep the COI-sync / W9 update and the `FeedEventRequested` block after the transaction, unchanged. (`$uid` is already fetched above; keep it.)

Add the two new methods:

```php
    public function vaultDocuments(): JsonResponse
    {
        if (! $this->vaultDirectoryAvailable()) {
            return response()->json(['data' => ['documents' => []]]);
        }
        $ctx = app(TenantContext::class);
        $docs = app(VaultDirectory::class)->eligibleDocuments($ctx->activeTenantType(), $ctx->activeTenantId());

        return response()->json(['data' => ['documents' => $docs]]);
    }

    public function setEvidence(Request $request, string $id): JsonResponse
    {
        $v = $request->validate(['vaultDocumentId' => ['nullable', 'uuid']]);
        $doc = VendorDocument::query()->findOrFail($id);
        $ctx = app(TenantContext::class);
        $previous = $doc->vault_document_id;
        $new = $v['vaultDocumentId'] ?? null;

        DB::transaction(function () use ($doc, $new, $ctx, $previous) {
            $doc->update(['vault_document_id' => $new]);
            $this->reconcileEvidenceEdge($ctx, VendorRelation::DOC_SOURCE_TYPE, (string) $doc->id, $previous, $new);
        });

        return response()->json(['data' => ['document' => $doc->fresh(), 'evidence' => $this->resolveEvidence($new, $ctx)]]);
    }
```

- [ ] **Step 6: Wire `VendorCredentialController`**

Add the same trait `use` + imports (`DB`, `ResolvesVaultEvidence`, `VendorRelation`, `TenantContext`). Wrap `add()`'s create in a transaction and reconcile the edge (previous = null, new = `$v['vaultDocumentId'] ?? null`, source type `VendorRelation::CRED_SOURCE_TYPE`). Add `setEvidence(Request $request, string $id)` identical in shape to the document one but using `VendorCredential`, `VendorRelation::CRED_SOURCE_TYPE`, and returning `['credential' => $cred->fresh(), 'evidence' => ...]`.

- [ ] **Step 7: Enrich `VendorReadController::get()`**

Add `use ResolvesVaultEvidence;` + imports (`TenantContext`, `VendorRelation`, `VaultDirectory`). In `get()`, after fetching, map an `evidence` key onto each document and credential:

```php
        $ctx = app(TenantContext::class);
        $documents = $vendor->documents()->whereNull('deleted_at')->orderByDesc('created_at')->get()
            ->map(function ($d) use ($ctx) {
                $row = $d->toArray();
                $row['evidence'] = $this->resolveEvidence($d->vault_document_id, $ctx);

                return $row;
            });
        $credentials = $vendor->credentials()->orderByDesc('created_at')->get()
            ->map(function ($c) use ($ctx) {
                $row = $c->toArray();
                $row['evidence'] = $this->resolveEvidence($c->vault_document_id, $ctx);

                return $row;
            });

        return response()->json(['data' => [
            'vendor' => $out,
            'documents' => $documents,
            'onboardingHistory' => $vendor->onboardingSteps()->orderBy('created_at')->get(),
            'credentials' => $credentials,
        ]]);
```

- [ ] **Step 8: Run it — expect PASS; then commit**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorVaultEvidenceTest.php`
Expected: PASS (all 5 cases). Also run `VendorDocumentTest.php` + `VendorCredentialTest.php` + `VendorReadTest.php` for no regression.

```bash
git add src/Http/Controllers/ src/routes.php tests/VendorVaultEvidenceTest.php
git commit -m "feat(linking): vault evidence on documents + credentials — picker, edges, read enrichment (Expand T2)"
```

---

### Task 3: Staff account-rep (build)

**Files:**
- Create: `database/migrations/2026_08_04_000001_add_account_rep_to_vendor_profiles.php`
- Create: `src/Http/Controllers/VendorAccountRepController.php`
- Modify: `src/Models/VendorProfile.php` (add `account_rep_employee_id` to SAFE_FIELDS + `@property`)
- Modify: `src/Http/Controllers/VendorReadController.php` (`get()` + `list()` rep enrichment)
- Modify: `src/routes.php` (2 routes)
- Test: `tests/VendorAccountRepTest.php` (new)

**Interfaces:**
- Consumes: `VendorRelation` (T1), `EntityReferenceService`, `StaffDirectory`.
- Produces: nullable `vendor_profiles.account_rep_employee_id` (uuid). `GET dashboard/vendor/api/assignable-staff` → `{employees:[{id,display_name}]}` (empty when staff-hub unavailable). `PUT dashboard/vendor/api/{vendorId}/account-rep` accepts `{employeeId: uuid|null}`, sets the column + reconciles a `profile→staff.employee 'account_rep'` edge. `get()` returns an `accountRep` key (`{id,display_name}` or `null`).

- [ ] **Step 1: Write the additive migration**

Create `database/migrations/2026_08_04_000001_add_account_rep_to_vendor_profiles.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, dated, idempotent: adds the internal account-rep pointer to vendor_profiles.
 * Nullable uuid, no FK (the rep is a staff-hub employee, a different plugin's table —
 * the authoritative link is the entity_references 'account_rep' edge; this column is the
 * fast read pointer). No RLS change — RLS is table-level and already enforced.
 */
return new class extends Migration
{
    private const T = 'vendor_profiles';

    public function up(): void
    {
        if (! Schema::hasTable(self::T) || Schema::hasColumn(self::T, 'account_rep_employee_id')) {
            return;
        }
        Schema::table(self::T, function (Blueprint $table) {
            $table->uuid('account_rep_employee_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::T) && Schema::hasColumn(self::T, 'account_rep_employee_id')) {
            Schema::table(self::T, function (Blueprint $table) {
                $table->dropColumn('account_rep_employee_id');
            });
        }
    }
};
```

- [ ] **Step 2: Add the column to `VendorProfile::SAFE_FIELDS`**

In `src/Models/VendorProfile.php`, add `'account_rep_employee_id',` to the `SAFE_FIELDS` array (immediately after `'category', 'status',`) and add a `@property string|null $account_rep_employee_id` line to the docblock.

- [ ] **Step 3: Write the failing account-rep test**

Create `tests/VendorAccountRepTest.php`. Bind a fake `StaffDirectory`; seed a vendor; drive the endpoints. Mirror `VendorReadTest.php` permission grants (`vendor.manage.rooftop` for the assign + picker).

```php
<?php

declare(strict_types=1);

use App\Support\EntityReferenceService;
use App\Support\TenantContext;
use Vctrs\Plugins\StaffHub\StaffDirectory;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;
use Vctrs\Plugins\VbVendorManager\Support\VendorRelation;

require_once __DIR__.'/vm_bootstrap.php';

function vmFakeStaff(): void
{
    app()->instance(StaffDirectory::class, new class extends StaffDirectory {
        public function listAssignable(string $tenantType, string $tenantId, ?string $departmentId = null, ?string $search = null, int $limit = 100): array
        {
            return [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'display_name' => 'Dana Rep']];
        }

        public function lookup(string $tenantType, string $tenantId, string $id): ?array
        {
            return ['id' => $id, 'display_name' => 'Dana Rep'];
        }
    });
}

function vmProfileEdges(string $vendorId): array
{
    $ctx = app(TenantContext::class);

    return app(EntityReferenceService::class)->forSource($ctx->activeTenantType(), $ctx->activeTenantId(), VendorRelation::PROFILE_SOURCE_TYPE, $vendorId);
}

it('lists assignable staff (empty when staff-hub unavailable)', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.manage.rooftop']);

    $this->actingAs($user)->getJson('/dashboard/vendor/api/assignable-staff')
        ->assertOk()->assertJsonPath('data.employees', []); // no fake bound → unavailable

    vmFakeStaff();
    $res = $this->actingAs($user)->getJson('/dashboard/vendor/api/assignable-staff');
    $res->assertOk();
    expect($res->json('data.employees.0.display_name'))->toBe('Dana Rep');
});

it('assigns a rep: sets the column, writes the edge, enriches get()', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.manage.rooftop']);
    vmFakeStaff();
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active']);
    $empId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    $this->actingAs($user)->putJson("/dashboard/vendor/api/{$vendor->id}/account-rep", ['employeeId' => $empId])->assertOk();

    expect((string) $vendor->fresh()->account_rep_employee_id)->toBe($empId)
        ->and(array_column(vmProfileEdges((string) $vendor->id), 'target_id'))->toBe([$empId]);

    $res = $this->actingAs($user)->getJson("/dashboard/vendor/api/{$vendor->id}");
    expect($res->json('data.accountRep.display_name'))->toBe('Dana Rep');
});

it('clears a rep: nulls the column and unlinks the edge', function () {
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.manage.rooftop']);
    vmFakeStaff();
    $empId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active', 'account_rep_employee_id' => $empId]);
    // establish the edge first
    $this->actingAs($user)->putJson("/dashboard/vendor/api/{$vendor->id}/account-rep", ['employeeId' => $empId])->assertOk();

    $this->actingAs($user)->putJson("/dashboard/vendor/api/{$vendor->id}/account-rep", ['employeeId' => null])->assertOk();

    expect($vendor->fresh()->account_rep_employee_id)->toBeNull()
        ->and(vmProfileEdges((string) $vendor->id))->toBe([]);
});
```

- [ ] **Step 4: Run it — expect FAIL**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorAccountRepTest.php`
Expected: FAIL — routes 404 / column missing.

- [ ] **Step 5: Add the routes**

In `src/routes.php`, add:

```php
    Route::get('/api/assignable-staff', [VendorAccountRepController::class, 'assignableStaff'])
        ->middleware('can:vendor.manage.rooftop')->name('api.assignable.staff');
    Route::put('/api/{vendorId}/account-rep', [VendorAccountRepController::class, 'assign'])
        ->middleware('can:vendor.manage.rooftop')->where('vendorId', '[0-9a-f-]{36}')->name('api.account.rep');
```

Add the `use Vctrs\Plugins\VbVendorManager\Http\Controllers\VendorAccountRepController;` import at the top.

- [ ] **Step 6: Write `VendorAccountRepController`**

Create `src/Http/Controllers/VendorAccountRepController.php`:

```php
<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\EntityReferenceService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Vctrs\Plugins\StaffHub\StaffDirectory;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;
use Vctrs\Plugins\VbVendorManager\Support\VendorRelation;

class VendorAccountRepController extends Controller
{
    private function staffDirectoryAvailable(): bool
    {
        return class_exists(StaffDirectory::class) && app()->bound(StaffDirectory::class);
    }

    public function assignableStaff(): JsonResponse
    {
        if (! $this->staffDirectoryAvailable()) {
            return response()->json(['data' => ['employees' => []]]);
        }
        $ctx = app(TenantContext::class);
        $rows = app(StaffDirectory::class)->listAssignable($ctx->activeTenantType(), $ctx->activeTenantId());
        $employees = array_map(fn (array $e) => ['id' => $e['id'], 'display_name' => $e['display_name'] ?? ''], $rows);

        return response()->json(['data' => ['employees' => $employees]]);
    }

    public function assign(Request $request, string $vendorId): JsonResponse
    {
        $v = $request->validate(['employeeId' => ['nullable', 'uuid']]);
        $vendor = VendorProfile::query()->whereNull('deleted_at')->findOrFail($vendorId);
        $ctx = app(TenantContext::class);
        $previous = $vendor->account_rep_employee_id;
        $new = $v['employeeId'] ?? null;

        DB::transaction(function () use ($vendor, $new, $ctx, $previous) {
            VendorProfile::query()->whereKey($vendor->id)->update(['account_rep_employee_id' => $new, 'updated_at' => now()]);

            $refs = app(EntityReferenceService::class);
            $tt = $ctx->activeTenantType();
            $tid = $ctx->activeTenantId();
            if ($previous !== null && $previous !== '' && $previous !== $new) {
                $refs->unlink($tt, $tid, VendorRelation::PROFILE_SOURCE_TYPE, (string) $vendor->id, VendorRelation::STAFF_TARGET_TYPE, $previous, VendorRelation::ACCOUNT_REP);
            }
            if ($new !== null && $new !== '') {
                $refs->link($tt, $tid, VendorRelation::PROFILE_SOURCE_TYPE, (string) $vendor->id, VendorRelation::STAFF_TARGET_TYPE, $new, VendorRelation::ACCOUNT_REP, $ctx->userId() !== '' ? $ctx->userId() : null);
            }
        });

        return response()->json(['data' => ['accountRepEmployeeId' => $new]]);
    }
}
```

- [ ] **Step 7: Enrich `VendorReadController` with the resolved rep**

Add imports (`StaffDirectory`). Add a private helper and call it in `get()`:

```php
    private function resolveAccountRep(?string $employeeId): ?array
    {
        if ($employeeId === null || $employeeId === '' || ! (class_exists(StaffDirectory::class) && app()->bound(StaffDirectory::class))) {
            return null;
        }
        $ctx = app(TenantContext::class);

        return app(StaffDirectory::class)->lookup($ctx->activeTenantType(), $ctx->activeTenantId(), $employeeId);
    }
```

In `get()`, add `'accountRep' => $this->resolveAccountRep($vendor->account_rep_employee_id),` to the returned `data` array. (The list() view keeps `account_rep_employee_id` via SAFE_FIELDS; do NOT add a per-row `StaffDirectory::lookup` loop there — resolving names in the list is out of scope and would be N+1. The detail view carries the resolved rep.)

- [ ] **Step 8: Run it — expect PASS; then commit**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorAccountRepTest.php`
Expected: PASS (all 3 cases). Run `VendorMigrationsTest.php` + `VendorReadTest.php` for no regression.

```bash
git add database/migrations/ src/Http/Controllers/VendorAccountRepController.php src/Models/VendorProfile.php src/Http/Controllers/VendorReadController.php src/routes.php tests/VendorAccountRepTest.php
git commit -m "feat(linking): staff account-rep — additive column, assign endpoint, edge, read enrichment (Expand T3)"
```

---

### Task 4: `VendorDirectory` read seam

**Files:**
- Create: `src/VendorDirectory.php`
- Modify: `src/VendorManagerServiceProvider.php` (singleton bind in `register()`)
- Test: `tests/VendorDirectoryTest.php` (new)

**Interfaces:**
- Produces: `Vctrs\Plugins\VbVendorManager\VendorDirectory` bound as a singleton — `lookup(string $tt,string $tid,string $id): ?array` and `listActive(string $tt,string $tid,?string $category=null,int $limit=100): array`, both projecting only the narrow PII-free fields `['id','company_name','category','status','has_active_contract','coi_expiry_date']`.

- [ ] **Step 1: Write the failing directory test**

Create `tests/VendorDirectoryTest.php` (unit-style: build tables with `vmRunMigrations()`, bind tenant, seed directly — no HTTP/install needed):

```php
<?php

declare(strict_types=1);

use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;
use Vctrs\Plugins\VbVendorManager\VendorDirectory;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    vmRunMigrations();
    vmBindTenant('00000000-0000-4000-8000-000000000001');
});

it('lists active vendors with only PII-free fields', function () {
    VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active', 'contact_email' => 'x@y.z', 'contact_phone' => '555', 'notes' => 'secret']);
    VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Pending Co', 'category' => 'oem', 'status' => 'pending']);

    $rows = app(VendorDirectory::class)->listActive('rooftop', PLUGIN_TEST_TENANT);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toHaveKeys(['id', 'company_name', 'category', 'status', 'has_active_contract', 'coi_expiry_date'])
        ->and($rows[0])->not->toHaveKey('contact_email')
        ->and($rows[0])->not->toHaveKey('contact_phone')
        ->and($rows[0])->not->toHaveKey('notes');
});

it('filters listActive by category and excludes soft-deleted', function () {
    VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Oem Co', 'category' => 'oem', 'status' => 'active']);
    VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Mkt Co', 'category' => 'marketing', 'status' => 'active']);
    $deleted = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Gone Co', 'category' => 'oem', 'status' => 'active']);
    $deleted->delete();

    $rows = app(VendorDirectory::class)->listActive('rooftop', PLUGIN_TEST_TENANT, 'oem');

    expect($rows)->toHaveCount(1)->and($rows[0]['company_name'])->toBe('Oem Co');
});

it('lookup returns null for an unknown id and PII-free fields for a known one', function () {
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active', 'contact_email' => 'x@y.z']);

    expect(app(VendorDirectory::class)->lookup('rooftop', PLUGIN_TEST_TENANT, '00000000-0000-4000-8000-0000000000ff'))->toBeNull();
    $row = app(VendorDirectory::class)->lookup('rooftop', PLUGIN_TEST_TENANT, (string) $vendor->id);
    expect($row)->not->toBeNull()->and($row)->not->toHaveKey('contact_email');
});
```

- [ ] **Step 2: Run it — expect FAIL**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorDirectoryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write `VendorDirectory`**

Create `src/VendorDirectory.php`:

```php
<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager;

use App\Support\SystemContext;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

/**
 * PII-free outbound read seam for other plugins/core. Deliberately narrower than
 * VendorProfile::SAFE_FIELDS — never exposes contact_email / contact_phone / notes.
 * Returns plain arrays, never Eloquent models. Tenant is passed explicitly and applied
 * with withoutTenantScope so cross-tenant callers cannot leak (DB FORCE-RLS remains the
 * real guard).
 */
class VendorDirectory
{
    /** @var array<int, string> */
    private const FIELDS = ['id', 'company_name', 'category', 'status', 'has_active_contract', 'coi_expiry_date'];

    /**
     * @return array{id: string, company_name: string, category: string, status: string, has_active_contract: bool, coi_expiry_date: mixed}|null
     */
    public function lookup(string $tenantType, string $tenantId, string $id): ?array
    {
        return SystemContext::runAsTenant($tenantType, $tenantId, function () use ($tenantType, $tenantId, $id): ?array {
            $vendor = VendorProfile::withoutTenantScope()
                ->where('tenant_type', $tenantType)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->find($id, self::FIELDS);

            return $vendor?->only(self::FIELDS);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(string $tenantType, string $tenantId, ?string $category = null, int $limit = 100): array
    {
        return SystemContext::runAsTenant($tenantType, $tenantId, function () use ($tenantType, $tenantId, $category, $limit): array {
            $q = VendorProfile::withoutTenantScope()
                ->where('tenant_type', $tenantType)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('status', 'active');
            if ($category !== null && $category !== '') {
                $q->where('category', $category);
            }

            return $q->orderBy('company_name')->limit($limit)->get(self::FIELDS)
                ->map(fn (VendorProfile $v) => $v->only(self::FIELDS))
                ->all();
        });
    }
}
```

(If `SystemContext::runAsTenant` is unavailable in the plugin's host contract, mirror exactly how `StaffDirectory`/`VaultDirectory` wrap their reads — check the host class the implementer can read at `../../vctrbase-php/plugins/staff-hub/src/StaffDirectory.php`. The recon confirms `StaffDirectory` uses `SystemContext::runAsTenant`.)

- [ ] **Step 4: Bind the singleton**

In `src/VendorManagerServiceProvider.php` `register()`, add before the `Route::group(...)` line:

```php
        app()->singleton(VendorDirectory::class, fn () => new VendorDirectory);
```

(`VendorDirectory` is in the same namespace as the provider — no import needed.)

- [ ] **Step 5: Run it — expect PASS; then commit**

Run: `bash scripts/test-in-app.sh tests/Feature/Plugins/VbVendorManager/VendorDirectoryTest.php`
Expected: PASS (all 3 cases).

```bash
git add src/VendorDirectory.php src/VendorManagerServiceProvider.php tests/VendorDirectoryTest.php
git commit -m "feat(linking): VendorDirectory PII-free read seam (lookup / listActive) (Expand T4)"
```

---

### Task 5: UI — DetailView vault + rep enrichment

**Files:**
- Modify: `ui/entry.tsx`
- Modify: `ui/__tests__/entry.test.tsx`
- Build: regenerate `dist/entry.js` via `npm run build` (gitignored — NOT committed)

**Interfaces:**
- Consumes: `GET /vault-documents` → `{documents:[{id,title,document_class,current_version}]}`; `GET /assignable-staff` → `{employees:[{id,display_name}]}`; document/credential rows from `/{id}` now carry `evidence` (`{title,current_version}` or `null`); the vendor payload carries `account_rep_employee_id` + a sibling `accountRep` (`{display_name}` or `null`); `PATCH /documents/{id}/evidence` + `PATCH /credentials/{id}/evidence` accept `{vaultDocumentId}`; `PUT /{vendorId}/account-rep` accepts `{employeeId}`.

- [ ] **Step 1: Inspect the current UI + test idiom**

Re-read `ui/entry.tsx` (306 lines) and `ui/__tests__/entry.test.tsx`. Match the `R.createElement` idiom (no JSX), the `getJson` fetch helper, and the host-injected `ui` component kit exactly. Note: the file has only GET reads today — you must add a `sendJson(url, method, body)` helper (same headers as `getJson` + `'Content-Type':'application/json'`, `X-XSRF-TOKEN` from the cookie if the existing tests/host expect it — mirror whatever `getJson` does for auth; the endpoints are session-authed `web` routes), and a `reload` counter in `DetailView` so a mutation re-fetches `/{id}`.

- [ ] **Step 2: Add failing vitest cases**

In `ui/__tests__/entry.test.tsx`, mirror the existing mock style. Add cases: (a) a document/credential row whose `evidence` is present renders the certificate affordance (its `title`); (b) choosing a vault document from the per-row picker issues `PATCH …/documents/{id}/evidence` (or credentials) with `{vaultDocumentId}`; (c) the header renders the resolved `accountRep.display_name` when present; (d) choosing a staff member from the rep picker issues `PUT …/{vendorId}/account-rep` with `{employeeId}`. Stub `/vault-documents` and `/assignable-staff` via the existing fetch mock.

- [ ] **Step 3: Run vitest — expect FAIL**

Run: `npm test`
Expected: the new cases FAIL.

- [ ] **Step 4: Implement the UI enrichment**

In `DetailView`: add a `reload` state counter (bump it after any successful mutation; add it to the `useEffect` dep array alongside `id`). Fetch `/vault-documents` and `/assignable-staff` once (guard: they may return empty — hide the pickers when empty). In the Documents and Credentials `DetailSection` `renderItem`, when `it.evidence` is present append a small affordance (`Certificate: <evidence.title> (v<evidence.current_version>)`) and render a `<select>` (controlled by `it.vault_document_id ?? ''`) whose options are the vault-documents list; on change call `sendJson('PATCH', …/documents|credentials/{it.id}/evidence, {vaultDocumentId: val || null})` then bump `reload`. In the header, render `Rep: <accountRep.display_name>` when `data.accountRep` is present, and a `<select>` (controlled by `vendor.account_rep_employee_id ?? ''`) of assignable staff; on change call `sendJson('PUT', …/{id}/account-rep, {employeeId: val || null})` then bump `reload`. Keep the file's `R.createElement` idiom; no new dependencies.

- [ ] **Step 5: Run vitest — expect PASS; then build**

Run: `npm test` → all pass. Then `npm run build` → succeeds (this repo has no `tsc`; the build is the compile gate). Confirm `git status` shows `dist/` untracked/ignored, NOT staged.

- [ ] **Step 6: Commit (source + test only, NOT dist)**

```bash
git add ui/entry.tsx ui/__tests__/entry.test.tsx
git commit -m "feat(ui): vault-evidence pickers + account-rep picker on vendor detail (Expand T5)"
```

---

## Final gate (controller runs after T5 review is clean)

1. Bump `manifest.json` `version` 1.0.2 → **1.1.0**.
2. Prepend a `## [1.1.0]` CHANGELOG entry: **Added** (vault evidence linking on documents + credentials with picker + edges + read enrichment; staff account-rep assignment + edge; `VendorDirectory` PII-free read seam) + **Changed** (onboarding channel auto-create now consumes the host `ChannelDirectory` seam instead of a hand-duplicated block — no behavior change). Commit as `chore(release): vb-vendor-manager v1.1.0 — cross-plugin linking (Expand)`.
3. Run the full suite: `bash scripts/test-in-app.sh` — expect green except any PROVEN-pre-existing failures (verify any failure is base-identical before accepting it). Re-run the signing tests (`SignedInstallBootTest`, `SigningByteCompatTest`) after the version bump. Run `npm test` + `npm run build`.
4. Whole-branch Opus review over `main..HEAD` (`scripts/review-package $(git merge-base main HEAD) HEAD`). Then STOP for owner Touchpoint 5 (build-zip + sign angusfox + publish v1.1.0). Do NOT merge/push/release.
