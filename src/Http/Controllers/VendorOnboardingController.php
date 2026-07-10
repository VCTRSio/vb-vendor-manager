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

        // Channel auto-create on approval (best-effort) — mirrors the Next.js core's
        // getOrCreateVendorChannel: when a vendor is approved and the ACTIVE tenant is a
        // rooftop, get-or-create the vendor's private channel and (on first creation) seed
        // an owner member + a welcome message. This is a NEW SOFT/DIRECT dependency on the
        // channels plugin — REVISIT: the clean fix is a
        // `ChannelDirectory::getOrCreateVendorChannel(...)` singleton exported by the
        // channels plugin (mirroring StaffDirectory), which would remove the string-class
        // coupling below (see docs/superpowers/core-flags-vendor-manager.md in the core
        // repo + the "Channels soft-dependency (revisit)" note in this plugin's README).
        //
        // The block is fully guarded: it only runs when the channels plugin is installed
        // (class_exists) and references the channels classes ONLY via fully-qualified string
        // names, so there is no compile-time coupling and PHPStan stays quiet. Everything is
        // wrapped in try/catch — faithful to core, a channel failure must NEVER block
        // onboarding. In the standalone test harness the channels plugin is absent, so
        // class_exists() is false and this is a no-op (documented cross-plugin coverage gap).
        if ($v['step'] === 'approved' && $ctx->activeTenantType() === 'rooftop'
            && class_exists('Vctrs\\Plugins\\Channels\\Models\\Channel')) {
            try {
                $channelClass = 'Vctrs\\Plugins\\Channels\\Models\\Channel';
                $channelMemberClass = 'Vctrs\\Plugins\\Channels\\Models\\ChannelMember';
                $channelMessageClass = 'Vctrs\\Plugins\\Channels\\Models\\ChannelMessage';

                $rooftopId = $ctx->activeTenantId();
                $slug = 'vendor-'.substr((string) $vendor->id, 0, 8);

                $channel = $channelClass::query()
                    ->where('tenant_type', 'rooftop')
                    ->where('tenant_id', $rooftopId)
                    ->where('slug', $slug)
                    ->first();

                if ($channel === null) {
                    $channel = $channelClass::create([
                        'tenant_type' => 'rooftop',
                        'tenant_id' => $rooftopId,
                        'slug' => $slug,
                        'name' => $vendor->company_name,
                        'description' => 'Private channel between the store and '.$vendor->company_name.'.',
                        'kind' => 'vendor',
                        'icon_key' => 'lock',
                        'vendor_id' => $vendor->id,
                        'created_by' => $uid,
                    ]);

                    // Newly created: add the creator as an owner member (firstOrCreate so a
                    // duplicate is harmless) and seed a single welcome message. Core seeds the
                    // member + welcome ONLY in the create branch, so we do the same here.
                    $channelMemberClass::firstOrCreate(
                        ['channel_id' => $channel->id, 'user_id' => $uid],
                        ['role' => 'owner'],
                    );

                    $channelMessageClass::create([
                        'channel_id' => $channel->id,
                        'tenant_type' => 'rooftop',
                        'tenant_id' => $rooftopId,
                        'author_user_id' => null,
                        'source_plugin' => 'vendor-manager',
                        'body' => '🤝 This channel is between the store and **'.$vendor->company_name.'**. Use it for compliance docs, scheduling, and day-to-day coordination. Anyone in the channel can post; archived messages stay in the audit trail.',
                    ]);
                }
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
