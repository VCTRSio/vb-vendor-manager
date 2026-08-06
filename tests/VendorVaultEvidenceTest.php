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
    app()->instance(VaultDirectory::class, new class extends VaultDirectory
    {
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
    $user = vmFeatureUser();
    vmFakeVault();

    $res = $this->actingAs($user)->getJson('/dashboard/vendor/api/vault-documents');

    $res->assertOk();
    expect($res->json('data.documents'))->toHaveCount(1)
        ->and($res->json('data.documents.0.title'))->toBe('COI Acme');
});

it('returns an empty picker list when vault is unavailable', function () {
    $user = vmFeatureUser();
    // no vmFakeVault(): the real VaultDirectory is unbound in the harness worktree.

    $res = $this->actingAs($user)->getJson('/dashboard/vendor/api/vault-documents');

    $res->assertOk();
    expect($res->json('data.documents'))->toBe([]);
});

it('writes an evidence edge when a document is added with a vaultDocumentId', function () {
    $user = vmFeatureUser();
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
    $user = vmFeatureUser();
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
    $user = vmFeatureUser();
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

// ── Direct authorization guards on the evidence PATCH routes ─────────────────────
// These prove the deny DIRECTLY on the evidence routes (not merely on a sibling
// route that shares the same permission slug). The document route is gated by
// `vendor.documents.write.rooftop`; the credential route by `vendor.manage.rooftop`.

it('denies setEvidence on a document without documents.write permission', function () {
    vmFeatureUser(); // install + boot the plugin routes for this tenant
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active']);
    $doc = VendorDocument::create(['vendor_id' => $vendor->id, 'document_type' => 'coi', 'vault_document_id' => null]);

    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.documents.write.rooftop']))
        ->patchJson("/dashboard/vendor/api/documents/{$doc->id}/evidence", ['vaultDocumentId' => null])
        ->assertForbidden();
});

it('denies setEvidence on a credential without manage permission', function () {
    vmFeatureUser(); // install + boot the plugin routes for this tenant
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active']);
    $cred = VendorCredential::create(['vendor_id' => $vendor->id, 'credential_type' => 'other', 'credential_name' => 'Lic', 'vault_document_id' => null]);

    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.manage.rooftop']))
        ->patchJson("/dashboard/vendor/api/credentials/{$cred->id}/evidence", ['vaultDocumentId' => null])
        ->assertForbidden();
});
