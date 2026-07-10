<?php

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Events\FeedEventRequested;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\VbVendorManager\Models\VendorOnboarding;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

class VendorOnboardingController extends Controller
{
    public function advance(Request $request, string $vendorId): JsonResponse
    {
        $v = $request->validate([
            'step' => ['required', 'in:document_submission,compliance_review,approved,rejected'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $vendor = VendorProfile::query()->findOrFail($vendorId);
        $uid = app(TenantContext::class)->userId();

        VendorOnboarding::create([
            'vendor_id' => $vendor->id,
            'step' => $v['step'],
            'notes' => $v['notes'] ?? null,
            'reviewed_by' => $uid,
        ]);

        $newStatus = match ($v['step']) {
            'approved' => 'active',
            'rejected' => 'rejected',
            default => null,
        };
        if ($newStatus !== null) {
            VendorProfile::query()->whereKey($vendor->id)->update(['status' => $newStatus, 'updated_at' => now()]);
            // Channel auto-create on approval: core wraps this in try/catch and calls a
            // channels service to getOrCreate a vendor channel when the active tenant type
            // is 'rooftop'. The PHP channels plugin exports NO public service contract with
            // a getOrCreate-style vendor-channel method (only ChannelSystemMessenger::post,
            // which requires an already-resolvable channel slug), so this is skipped and
            // flagged in docs/superpowers/core-flags-vendor-manager.md. Behavior-preserving
            // since core also wraps the call in try/catch.
        }

        // onboarding.step is not audited via the observer — see docs/superpowers/core-flags-vendor-manager.md (accepted divergence).

        try {
            event(new FeedEventRequested(
                tenantType: $vendor->tenant_type,
                tenantId: $vendor->tenant_id,
                actorType: 'user',
                actorId: $uid,
                sourceType: 'vb-vendor-manager',
                sourceId: (string) $vendor->id,
                pluginNamespace: 'vb-vendor-manager',
                eventType: 'vendor.onboarding.step',
                summary: 'Vendor "'.$vendor->company_name.'" onboarding: '.str_replace('_', ' ', $v['step']).'.',
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['data' => ['step' => $v['step']]]);
    }
}
