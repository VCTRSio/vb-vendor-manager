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
        $ctx = app(TenantContext::class);
        $uid = $ctx->userId();

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
        }

        // Vendor-approval channel (best-effort). The host channels plugin exports
        // ChannelDirectory::getOrCreateVendorChannel — the exact seam this plugin's
        // README asked for — so we consume it via late-bound class name (no compile-time
        // coupling, PHPStan stays quiet) instead of hand-duplicating the Channel/member/
        // welcome-message writes. Guarded: only when channels is installed and the active
        // tenant is a rooftop; wrapped in try/catch so a channel failure never blocks
        // onboarding.
        if ($v['step'] === 'approved' && $ctx->activeTenantType() === 'rooftop'
            && class_exists('Vctrs\\Plugins\\Channels\\ChannelDirectory')) {
            try {
                app('Vctrs\\Plugins\\Channels\\ChannelDirectory')->getOrCreateVendorChannel(
                    $ctx->activeTenantId(),
                    (string) $vendor->id,
                    (string) $vendor->company_name,
                    $uid !== '' ? $uid : null,
                );
            } catch (\Throwable $e) {
                report($e);
            }
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
