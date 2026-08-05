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
