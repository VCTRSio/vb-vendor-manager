<?php

use App\Events\FeedEventRequested;
use App\Events\TaskRequested;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Event;
use Vctrs\Plugins\VendorManager\Jobs\VendorEscalationCheckJob;
use Vctrs\Plugins\VendorManager\Jobs\VendorExpiryCheckJob;
use Vctrs\Plugins\VendorManager\Models\VendorCredential;
use Vctrs\Plugins\VendorManager\Models\VendorDocument;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
    $this->vendor = VendorProfile::create(['company_name' => 'J', 'category' => 'oem', 'status' => 'active']);
});

it('fires task + feed for a COI expiring inside the first threshold and stamps last_alert', function () {
    Event::fake([TaskRequested::class, FeedEventRequested::class]);
    $doc = VendorDocument::create(['vendor_id' => $this->vendor->id, 'document_type' => 'coi', 'expires_at' => now()->addDays(5)]);

    (new VendorExpiryCheckJob('rooftop', PLUGIN_TEST_TENANT))->handle();

    Event::assertDispatched(TaskRequested::class);
    Event::assertDispatched(FeedEventRequested::class);
    // Core iterates COI thresholds [coiAlertDays1=60, coiAlertDays2=30, coiAlertDays3=7]
    // in order and breaks on the FIRST match. days=5 matches 60 first, so '60' is stamped
    // (see VCTRbase packages/plugins/vendor-manager/inngest.ts lines 107-143).
    expect($doc->fresh()->last_alert_days_sent)->toBe('60');
});

it('fires a credential alert once', function () {
    Event::fake([TaskRequested::class, FeedEventRequested::class]);
    $cred = VendorCredential::create(['vendor_id' => $this->vendor->id, 'credential_type' => 'bg77', 'credential_name' => 'BG', 'expires_at' => now()->addDays(10)]);

    (new VendorExpiryCheckJob('rooftop', PLUGIN_TEST_TENANT))->handle();

    expect($cred->fresh()->expiry_alert_sent_at)->not->toBeNull();
    Event::assertDispatched(TaskRequested::class);
});

it('escalates documents expiring within 7 days', function () {
    Event::fake([FeedEventRequested::class]);
    VendorDocument::create(['vendor_id' => $this->vendor->id, 'document_type' => 'coi', 'expires_at' => now()->addDays(3)]);

    (new VendorEscalationCheckJob('rooftop', PLUGIN_TEST_TENANT))->handle();

    Event::assertDispatched(FeedEventRequested::class, fn ($e) => $e->eventType === 'vendor.expiry_escalation');
});
