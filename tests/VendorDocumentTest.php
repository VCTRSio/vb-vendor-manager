<?php

use App\Models\AuditEvent;
use App\Support\TenantContext;
use Vctrs\Plugins\VendorManager\Models\VendorDocument;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
    $this->vendor = VendorProfile::create(['company_name' => 'D', 'category' => 'oem', 'status' => 'active']);
});

it('adds a COI document and syncs coi expiry to the profile', function () {
    $expiry = now()->addDays(120);
    $res = $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$this->vendor->id}/documents", [
            'documentType' => 'coi', 'expiresAt' => $expiry->toIso8601String(),
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.document.document_type', 'coi');

    expect($this->vendor->fresh()->coi_expiry_date->toDateString())->toBe($expiry->toDateString());

    // A3: doc create is tagged document.add (COI-sync mass update in between must not
    // consume the tag).
    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'document.add')
        ->where('resource_type', 'vendor_documents')
        ->where('resource_id', $res->json('data.document.id'))
        ->exists())->toBeTrue();
});

it('marks w9_on_file when a w9 is added', function () {
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$this->vendor->id}/documents", ['documentType' => 'w9'])
        ->assertStatus(201);
    expect($this->vendor->fresh()->w9_on_file)->toBeTrue();
});

it('lists and removes documents, re-syncing COI on removal', function () {
    $doc = VendorDocument::create(['vendor_id' => $this->vendor->id, 'document_type' => 'coi', 'expires_at' => now()->addDays(50)]);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->getJson("/dashboard/vendor/api/{$this->vendor->id}/documents")
        ->assertOk()->assertJsonCount(1, 'data.documents');

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->deleteJson("/dashboard/vendor/api/documents/{$doc->id}")
        ->assertOk()->assertJsonPath('data.removed', true);

    expect(VendorDocument::find($doc->id))->toBeNull();

    // A4: delete is tagged document.remove.
    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'document.remove')
        ->where('resource_type', 'vendor_documents')
        ->where('resource_id', $doc->id)
        ->exists())->toBeTrue();
});

it('denies add without documents.write permission', function () {
    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.documents.write.rooftop']))
        ->postJson("/dashboard/vendor/api/{$this->vendor->id}/documents", ['documentType' => 'coi'])
        ->assertForbidden();
});
