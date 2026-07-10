<?php

use App\Models\AuditEvent;
use App\Support\TenantContext;
use Vctrs\Plugins\VbVendorManager\Models\VendorCredential;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
    $this->vendor = VendorProfile::create(['company_name' => 'C', 'category' => 'oem', 'status' => 'active']);
});

it('adds, lists and removes credentials', function () {
    $res = $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$this->vendor->id}/credentials", [
            'credentialType' => 'bg77', 'credentialName' => 'BG77 Cert',
        ])->assertStatus(201)->assertJsonPath('data.credential.credential_name', 'BG77 Cert');

    $id = $res->json('data.credential.id');

    // A5-credentials: create is audited with procedure credential.add.
    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'credential.add')
        ->where('resource_type', 'vendor_credentials')
        ->where('resource_id', $id)
        ->exists())->toBeTrue();

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->getJson("/dashboard/vendor/api/{$this->vendor->id}/credentials")
        ->assertOk()->assertJsonCount(1, 'data.credentials');

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->deleteJson("/dashboard/vendor/api/credentials/{$id}")
        ->assertOk()->assertJsonPath('data.removed', true);

    expect(VendorCredential::find($id))->toBeNull();

    // A5-credentials: delete is audited with procedure credential.remove.
    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'credential.remove')
        ->where('resource_type', 'vendor_credentials')
        ->where('resource_id', $id)
        ->exists())->toBeTrue();
});

it('denies add credential without manage permission', function () {
    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.manage.rooftop']))
        ->postJson("/dashboard/vendor/api/{$this->vendor->id}/credentials", ['credentialType' => 'bg77', 'credentialName' => 'x'])
        ->assertForbidden();
});
