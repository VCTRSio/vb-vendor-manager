<?php

use App\Support\TenantContext;
use Vctrs\Plugins\VbVendorManager\Models\VendorOnboarding;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
    $this->vendor = VendorProfile::create(['company_name' => 'On', 'category' => 'oem', 'status' => 'pending']);
});

it('advances onboarding to approved and activates the vendor', function () {
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$this->vendor->id}/onboarding", ['step' => 'approved', 'notes' => 'ok'])
        ->assertOk()->assertJsonPath('data.step', 'approved');

    expect($this->vendor->fresh()->status)->toBe('active');
    expect(VendorOnboarding::where('vendor_id', $this->vendor->id)->where('step', 'approved')->exists())->toBeTrue();
});

it('rejecting sets status rejected', function () {
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$this->vendor->id}/onboarding", ['step' => 'rejected'])
        ->assertOk();
    expect($this->vendor->fresh()->status)->toBe('rejected');
});

it('denies advance without onboard permission', function () {
    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.onboard.rooftop']))
        ->postJson("/dashboard/vendor/api/{$this->vendor->id}/onboarding", ['step' => 'approved'])
        ->assertForbidden();
});
