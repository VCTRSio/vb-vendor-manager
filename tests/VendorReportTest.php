<?php

use App\Support\TenantContext;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
});

it('reports contract totals for active vendors', function () {
    VendorProfile::create(['company_name' => 'A', 'category' => 'oem', 'status' => 'active', 'has_active_contract' => true, 'contract_value_monthly' => '100.00', 'contract_value_annual' => '1200.00']);
    VendorProfile::create(['company_name' => 'B', 'category' => 'oem', 'status' => 'active', 'has_active_contract' => false]);
    VendorProfile::create(['company_name' => 'C', 'category' => 'oem', 'status' => 'pending', 'has_active_contract' => true, 'contract_value_monthly' => '999.00']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->getJson('/dashboard/vendor/api/reports/contract')
        ->assertOk()
        ->assertJsonPath('data.totalMonthly', '100.00')
        ->assertJsonPath('data.totalAnnual', '1200.00')
        ->assertJsonPath('data.withoutContractCount', 1);
});

it('denies report without reports.view permission', function () {
    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.reports.view.rooftop']))
        ->getJson('/dashboard/vendor/api/reports/contract')->assertForbidden();
});
