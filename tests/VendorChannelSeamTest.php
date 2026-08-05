<?php

declare(strict_types=1);

use Vctrs\Plugins\Channels\ChannelDirectory;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

it('adopts ChannelDirectory::getOrCreateVendorChannel on vendor approval', function () {
    // Mirror VendorOnboardingTest's proven grant: the onboarding route is gated by
    // `can:vendor.onboard.rooftop` (see src/routes.php), which the rooftop_owner role
    // already grants — add it explicitly here so the grant is self-documenting.
    $user = vmFeatureUser(['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.write.rooftop', '+vendor.onboard.rooftop']);

    $vendor = VendorProfile::create([
        'tenant_type' => 'rooftop', 'tenant_id' => PLUGIN_TEST_TENANT,
        'company_name' => 'Acme Parts', 'category' => 'aftermarket', 'status' => 'pending',
    ]);

    $calls = [];
    app()->instance(ChannelDirectory::class, new class($calls) extends ChannelDirectory
    {
        public function __construct(public array &$calls) {}

        public function getOrCreateVendorChannel(string $rooftopId, string $vendorId, string $vendorName, ?string $createdBy = null): \Vctrs\Plugins\Channels\Models\Channel
        {
            $this->calls[] = [$rooftopId, $vendorId, $vendorName, $createdBy];

            return new \Vctrs\Plugins\Channels\Models\Channel;
        }
    });

    $res = $this->actingAs($user)->postJson("/dashboard/vendor/api/{$vendor->id}/onboarding", [
        'step' => 'approved',
    ]);

    $res->assertOk();
    expect($calls)->toHaveCount(1)
        ->and($calls[0][1])->toBe((string) $vendor->id)
        ->and($calls[0][2])->toBe('Acme Parts');
});
