<?php

use App\Support\TenantContext;
use Vctrs\Plugins\VbVendorManager\Models\VendorDocument;
use Vctrs\Plugins\VbVendorManager\Models\VendorOnboarding;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;
use Vctrs\Plugins\VbVendorManager\Services\VendorService;

require_once __DIR__.'/vm_bootstrap.php';

function bindVendorTenant(): string
{
    $u = pluginTestUser();
    vmInstallSignedAndBoot(vmBindTenant($u->id));

    return $u->id;
}

it('returns manifest default settings when no tenant override exists', function () {
    bindVendorTenant();
    $s = app(VendorService::class)->resolveSettings();

    expect($s['coiAlertDays1'])->toBe(60)
        ->and($s['coiAlertDays2'])->toBe(30)
        ->and($s['coiAlertDays3'])->toBe(7)
        ->and($s['contractAlertDays'])->toBe(30)
        ->and($s['credentialAlertDays'])->toBe(30)
        ->and($s['requireCoi'])->toBeTrue()
        ->and($s['requireW9'])->toBeTrue();
});

it('computes compliance status across the core matrix', function () {
    bindVendorTenant();
    $svc = app(VendorService::class);
    $settings = $svc->resolveSettings();

    $mk = fn (array $a) => new VendorProfile($a);

    // inactive → non_compliant regardless
    expect($svc->complianceStatus($mk(['status' => 'pending', 'w9_on_file' => true, 'coi_expiry_date' => now()->addYear()]), $settings))->toBe('non_compliant');
    // active, missing W9 → non_compliant
    expect($svc->complianceStatus($mk(['status' => 'active', 'w9_on_file' => false, 'coi_expiry_date' => now()->addYear()]), $settings))->toBe('non_compliant');
    // active, no COI → non_compliant
    expect($svc->complianceStatus($mk(['status' => 'active', 'w9_on_file' => true, 'coi_expiry_date' => null]), $settings))->toBe('non_compliant');
    // active, COI expired → non_compliant
    expect($svc->complianceStatus($mk(['status' => 'active', 'w9_on_file' => true, 'coi_expiry_date' => now()->subDay()]), $settings))->toBe('non_compliant');
    // active, COI expires within 30d → warning
    expect($svc->complianceStatus($mk(['status' => 'active', 'w9_on_file' => true, 'coi_expiry_date' => now()->addDays(10)]), $settings))->toBe('warning');
    // active, COI far out → compliant
    expect($svc->complianceStatus($mk(['status' => 'active', 'w9_on_file' => true, 'coi_expiry_date' => now()->addDays(200)]), $settings))->toBe('compliant');
});

it('syncs coi expiry from the latest non-expired COI document', function () {
    bindVendorTenant();
    $svc = app(VendorService::class);

    $vendor = VendorProfile::create(['company_name' => 'Acme', 'category' => 'oem', 'status' => 'active']);
    VendorDocument::create(['vendor_id' => $vendor->id, 'document_type' => 'coi', 'expires_at' => now()->addDays(90)]);
    VendorDocument::create(['vendor_id' => $vendor->id, 'document_type' => 'coi', 'expires_at' => now()->subDays(5)]);

    $svc->syncCoiExpiry($vendor->id);

    expect($vendor->fresh()->coi_expiry_date->toDateString())->toBe(now()->addDays(90)->toDateString());
});

it('createVendor inserts profile + first onboarding step document_submission', function () {
    $uid = bindVendorTenant();
    $svc = app(VendorService::class);

    $vendor = $svc->createVendor([
        'company_name' => 'Globex',
        'contact_name' => null, 'contact_email' => null, 'contact_phone' => null,
        'category' => 'technology', 'notes' => null,
    ], $uid);

    expect($vendor->status)->toBe('pending');
    $step = VendorOnboarding::where('vendor_id', $vendor->id)->first();
    expect($step->step)->toBe('document_submission')
        ->and($step->reviewed_by)->toBe($uid);
});
