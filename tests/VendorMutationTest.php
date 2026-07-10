<?php

use App\Models\AuditEvent;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;
use App\Events\FeedEventRequested;
use Vctrs\Plugins\VbVendorManager\Models\VendorOnboarding;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
});

it('creates a vendor with a document_submission onboarding step and fires a feed event', function () {
    Event::fake([FeedEventRequested::class]);

    $res = $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/dashboard/vendor/api', [
            'companyName' => 'Initech', 'category' => 'technology',
        ])
        ->assertStatus(201)
        ->assertJsonPath('data.vendor.company_name', 'Initech')
        ->assertJsonPath('data.vendor.status', 'pending');

    $id = $res->json('data.vendor.id');
    expect(VendorOnboarding::where('vendor_id', $id)->first()->step)->toBe('document_submission');
    Event::assertDispatched(FeedEventRequested::class);

    // A2: the profile-create audit row is tagged vendor.create (not the default create).
    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'vendor.create')
        ->where('resource_type', 'vendor_profiles')
        ->where('resource_id', $id)
        ->exists())->toBeTrue();
});

it('updates vendor contract + oem certifications', function () {
    $v = VendorProfile::create(['company_name' => 'X', 'category' => 'oem', 'status' => 'active']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->putJson("/dashboard/vendor/api/{$v->id}", [
            'hasActiveContract' => true,
            'contractValueMonthly' => '1000.00',
            'oemCertifications' => ['BG77', 'AFG'],
        ])
        ->assertOk()
        ->assertJsonPath('data.vendor.has_active_contract', true)
        ->assertJsonPath('data.vendor.oem_certifications_json', ['BG77', 'AFG']);

    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'vendor.update')
        ->where('resource_type', 'vendor_profiles')
        ->where('resource_id', $v->id)
        ->exists())->toBeTrue();
});

it('audits a no-op update (locks A1 always-dirty updated_at bump)', function () {
    $v = VendorProfile::create(['company_name' => 'X', 'category' => 'oem', 'status' => 'active']);

    // Set a field to its current value → without the A1 updated_at bump the model
    // would be clean and the observer would not fire.
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->putJson("/dashboard/vendor/api/{$v->id}", ['companyName' => 'X'])
        ->assertOk();

    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'vendor.update')
        ->where('resource_type', 'vendor_profiles')
        ->where('resource_id', $v->id)
        ->exists())->toBeTrue();
});

it('changes vendor status', function () {
    $v = VendorProfile::create(['company_name' => 'X', 'category' => 'oem', 'status' => 'pending']);

    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$v->id}/status", ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.vendor.status', 'inactive');

    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'vendor.status_change')
        ->where('resource_type', 'vendor_profiles')
        ->where('resource_id', $v->id)
        ->exists())->toBeTrue();
});

it('audits a no-op status set equal to current status (locks A1)', function () {
    $v = VendorProfile::create(['company_name' => 'X', 'category' => 'oem', 'status' => 'pending']);

    // Setting status to its current value is a no-op field-wise; A1's updated_at
    // bump keeps the model dirty so the observer still fires an audit row.
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$v->id}/status", ['status' => 'pending'])
        ->assertOk()
        ->assertJsonPath('data.vendor.status', 'pending');

    expect(AuditEvent::withoutGlobalScopes()
        ->where('procedure', 'vendor.status_change')
        ->where('resource_type', 'vendor_profiles')
        ->where('resource_id', $v->id)
        ->exists())->toBeTrue();
});

it('denies create without vendor.manage permission', function () {
    $u = pluginTestUser('rooftop_owner', ['-vendor.manage.rooftop']);
    $this->actingAs($u)->postJson('/dashboard/vendor/api', ['companyName' => 'Y', 'category' => 'oem'])
        ->assertForbidden();
});

it('422 on invalid category', function () {
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson('/dashboard/vendor/api', ['companyName' => 'Y', 'category' => 'nope'])
        ->assertStatus(422);
});
