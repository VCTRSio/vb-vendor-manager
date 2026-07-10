<?php

use App\Support\TenantContext;
use Vctrs\Plugins\VbVendorManager\Models\VendorDocument;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
    $vendor = VendorProfile::create(['company_name' => 'AD', 'category' => 'oem', 'status' => 'active']);
    $this->doc = VendorDocument::create(['vendor_id' => $vendor->id, 'document_type' => 'coi']);
});

it('admin soft-deletes and restores a document', function () {
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->delete("/dashboard/vendor/documents/{$this->doc->id}/admin", ['reason' => 'expired'])
        ->assertRedirect();
    expect($this->doc->fresh()->deleted_at)->not->toBeNull();

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->post("/dashboard/vendor/documents/{$this->doc->id}/admin/restore")
        ->assertRedirect();
    expect($this->doc->fresh()->deleted_at)->toBeNull();
});

it('denies admin document ops without admin.manage permission', function () {
    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.admin.manage.rooftop']))
        ->delete("/dashboard/vendor/documents/{$this->doc->id}/admin")
        ->assertForbidden();
});
