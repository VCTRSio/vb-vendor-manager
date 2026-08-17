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
    app()->instance(StaffDirectory::class, new class extends StaffDirectory
    {
        public function listAssignable(string $tenantType, string $tenantId, ?string $departmentId = null, ?string $search = null, int $limit = 100): array
        {
            return [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'display_name' => 'Dana Rep']];
        }

        public function lookup(string $tenantType, string $tenantId, string $id, bool $includePii = false): ?array
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

// Direct authorization guard on the account-rep assign route itself (gated by
// `vendor.manage.rooftop`) — proven on THIS route, not on a sibling that shares
// the slug.
it('denies account-rep assign without manage permission', function () {
    vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.manage.rooftop']); // install + boot routes
    $vendor = VendorProfile::create(['tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT, 'company_name' => 'Acme', 'category' => 'oem', 'status' => 'active']);

    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.manage.rooftop']))
        ->putJson("/dashboard/vendor/api/{$vendor->id}/account-rep", ['employeeId' => null])
        ->assertForbidden();
});
