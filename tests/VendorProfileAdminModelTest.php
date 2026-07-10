<?php

use App\Audit\AuditableRegistry;
use App\Plugins\Contracts\AdminManageableModel;
use App\Plugins\PluginModel;
use Illuminate\Support\Str;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

// Install + boot the signed plugin so its tables exist and its models are
// registered in AuditableRegistry (the ServiceProvider::register() runs).
beforeEach(function () {
    vmInstallSignedAndBoot(vmBindTenant(pluginTestUser()->id));
});

function makeVendorProfile(array $overrides = []): VendorProfile
{
    return VendorProfile::withoutTenantScope()->create(array_merge([
        'tenant_type' => 'rooftop',
        'tenant_id' => PLUGIN_TEST_TENANT,
        'company_name' => 'Test Vendor LLC',
        'category' => 'technology',
        'status' => 'active',
        'api_key_hash' => 'secrethash',
    ], $overrides));
}

function vendorActorId(): string
{
    return (string) Str::uuid();
}

it('is a PluginModel and an AdminManageableModel', function () {
    expect(new VendorProfile)->toBeInstanceOf(PluginModel::class)
        ->and(new VendorProfile)->toBeInstanceOf(AdminManageableModel::class);
});

it('registers the vendor_profiles table for auditing', function () {
    expect(AuditableRegistry::hasTable('vendor_profiles'))->toBeTrue();
});

it('SAFE_FIELDS does not contain api_key_hash', function () {
    expect(VendorProfile::SAFE_FIELDS)->not->toContain('api_key_hash');
});

it('SAFE_FIELDS does not contain api_key_revoked_at', function () {
    expect(VendorProfile::SAFE_FIELDS)->not->toContain('api_key_revoked_at');
});

it('applyAdminEdit updates fields, stamps editor, increments edit_count', function () {
    $actorId = vendorActorId();
    $v = makeVendorProfile();
    $v->applyAdminEdit(['status' => 'inactive'], $actorId);
    $v->refresh();
    expect($v->status)->toBe('inactive')
        ->and($v->edited_by_id)->toBe($actorId)
        ->and($v->edit_count)->toBe(1)
        ->and($v->edited_at)->not->toBeNull();
});

it('softDeleteWithReason then scopeActive hides it, restore brings it back', function () {
    $actorId = vendorActorId();
    $v = makeVendorProfile(['company_name' => 'Delete Me Vendor']);
    $v->softDeleteWithReason('test removal', $actorId);
    expect(VendorProfile::withoutTenantScope()->active()->whereKey($v->id)->exists())->toBeFalse();
    $v->refresh();
    expect($v->deleted_by_id)->toBe($actorId)->and($v->delete_reason)->toBe('test removal');
    $v->restoreSoftDeleted();
    expect(VendorProfile::withoutTenantScope()->active()->whereKey($v->id)->exists())->toBeTrue();
});
