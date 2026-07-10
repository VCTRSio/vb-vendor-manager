<?php

use App\Support\TenantContext;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;
use Vctrs\Plugins\VendorManager\Services\VendorService;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser()->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
});

it('returns dashboard stats', function () {
    VendorProfile::create(['company_name' => 'A', 'category' => 'oem', 'status' => 'active', 'has_active_contract' => false]);
    VendorProfile::create(['company_name' => 'B', 'category' => 'oem', 'status' => 'pending']);

    $this->actingAs(pluginTestUser())
        ->getJson('/dashboard/vendor/api/stats')
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.pending', 1)
        ->assertJsonPath('data.noContractCount', 1);
});

it('lists vendors with compliance status and category filter', function () {
    VendorProfile::create(['company_name' => 'Zeta', 'category' => 'oem', 'status' => 'active', 'w9_on_file' => true, 'coi_expiry_date' => now()->addDays(200)]);
    VendorProfile::create(['company_name' => 'Alpha', 'category' => 'facility', 'status' => 'active']);

    $res = $this->actingAs(pluginTestUser())
        ->getJson('/dashboard/vendor/api/list?category=oem')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    expect($res->json('data.items.0.company_name'))->toBe('Zeta')
        ->and($res->json('data.items.0.complianceStatus'))->toBe('compliant');
});

it('gets a single vendor with related collections', function () {
    $v = app(VendorService::class)->createVendor([
        'company_name' => 'Solo',
        'contact_name' => null, 'contact_email' => null, 'contact_phone' => null,
        'category' => 'oem', 'notes' => null,
    ], $this->uid);

    $this->actingAs(pluginTestUser())
        ->getJson("/dashboard/vendor/api/{$v->id}")
        ->assertOk()
        ->assertJsonPath('data.vendor.company_name', 'Solo')
        ->assertJsonPath('data.documents', [])
        ->assertJsonCount(1, 'data.onboardingHistory');
});

it('denies stats without vendor.view permission', function () {
    $u = pluginTestUser('rooftop_owner', ['-vendor.view.rooftop']);
    $this->actingAs($u)->getJson('/dashboard/vendor/api/stats')->assertForbidden();
});
