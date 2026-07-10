<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VendorManager\Services;

use App\Events\FeedEventRequested;
use App\Plugins\PluginSettings;
use Illuminate\Support\Facades\DB;
use Vctrs\Plugins\VendorManager\Models\VendorDocument;
use Vctrs\Plugins\VendorManager\Models\VendorOnboarding;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;

class VendorService
{
    /**
     * Resolve tenant vendor settings through the generic PluginSettings cascade,
     * falling back to the manifest defaults. Ints for day-thresholds, bools for flags.
     *
     * @return array{coiAlertDays1:int,coiAlertDays2:int,coiAlertDays3:int,contractAlertDays:int,credentialAlertDays:int,requireCoi:bool,requireW9:bool}
     */
    public function resolveSettings(): array
    {
        $s = app(PluginSettings::class)->resolve('vb-vendor-manager');

        return [
            'coiAlertDays1' => (int) ($s['coiAlertDays1'] ?? 60),
            'coiAlertDays2' => (int) ($s['coiAlertDays2'] ?? 30),
            'coiAlertDays3' => (int) ($s['coiAlertDays3'] ?? 7),
            'contractAlertDays' => (int) ($s['contractAlertDays'] ?? 30),
            'credentialAlertDays' => (int) ($s['credentialAlertDays'] ?? 30),
            'requireCoi' => (bool) ($s['requireCoi'] ?? true),
            'requireW9' => (bool) ($s['requireW9'] ?? true),
        ];
    }

    /**
     * Port of core service.getComplianceStatus.
     *
     * @param  array{requireW9:bool,requireCoi:bool}  $settings
     */
    public function complianceStatus(VendorProfile $vendor, array $settings): string
    {
        if ($vendor->status !== 'active') {
            return 'non_compliant';
        }

        $now = now();
        $thirtyDays = $now->copy()->addDays(30);
        $coi = $vendor->coi_expiry_date;

        if ($settings['requireW9'] && ! $vendor->w9_on_file) {
            return 'non_compliant';
        }
        if ($settings['requireCoi'] && $coi === null) {
            return 'non_compliant';
        }
        if ($coi !== null && $coi->lt($now)) {
            return 'non_compliant';
        }
        if ($coi !== null && $coi->lt($thirtyDays)) {
            return 'warning';
        }

        return 'compliant';
    }

    /**
     * Port of core service.syncCoiExpiry — set the profile's coi_expiry_date to the
     * latest non-expired COI doc (or the most recent if all expired), else null.
     */
    public function syncCoiExpiry(string $vendorId): void
    {
        $docs = VendorDocument::query()
            ->where('vendor_id', $vendorId)
            ->where('document_type', 'coi')
            ->orderBy('expires_at')
            ->limit(10)
            ->get();

        $now = now();
        $active = $docs->first(fn (VendorDocument $d) => $d->expires_at !== null && $d->expires_at->gt($now));
        $latest = $active ?? $docs->last();

        VendorProfile::query()
            ->whereKey($vendorId)
            ->update([
                'coi_expiry_date' => $latest?->expires_at,
                'updated_at' => now(),
            ]);
    }

    /**
     * Shared creation path for both the JSON createVendor endpoint and the Inertia
     * onboarding wizard. Matches core router.createVendor semantics.
     *
     * @param  array{company_name:string,contact_name:?string,contact_email:?string,contact_phone:?string,category:string,notes:?string}  $attrs
     */
    public function createVendor(array $attrs, string $userId): VendorProfile
    {
        $vendor = DB::transaction(function () use ($attrs, $userId) {
            \App\Audit\AuditContext::tag('vendor.create');
            $vendor = VendorProfile::create([
                'company_name' => $attrs['company_name'],
                'contact_name' => $attrs['contact_name'] ?? null,
                'contact_email' => $attrs['contact_email'] ?? null,
                'contact_phone' => $attrs['contact_phone'] ?? null,
                'category' => $attrs['category'],
                'status' => 'pending',
                'notes' => $attrs['notes'] ?? null,
            ]);

            VendorOnboarding::create([
                'vendor_id' => $vendor->id,
                'step' => 'document_submission',
                'notes' => 'Vendor profile created — awaiting document submission.',
                'reviewed_by' => $userId,
            ]);

            return $vendor;
        });

        try {
            event(new FeedEventRequested(
                tenantType: $vendor->tenant_type,
                tenantId: $vendor->tenant_id,
                actorType: 'user',
                actorId: $userId,
                sourceType: 'vb-vendor-manager',
                sourceId: (string) $vendor->id,
                pluginNamespace: 'vb-vendor-manager',
                eventType: 'vendor.created',
                summary: "Vendor created: {$vendor->company_name}",
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return $vendor;
    }
}
