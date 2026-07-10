<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VendorManager\Jobs;

use App\Events\FeedEventRequested;
use App\Events\TaskRequested;
use App\Plugins\Scheduling\PluginScheduledJob;
use App\Support\TenantContext;
use Vctrs\Plugins\VendorManager\Models\VendorCredential;
use Vctrs\Plugins\VendorManager\Models\VendorDocument;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;
use Vctrs\Plugins\VendorManager\Services\VendorService;

final class VendorExpiryCheckJob extends PluginScheduledJob
{
    protected function run(): void
    {
        $settings = app(VendorService::class)->resolveSettings();
        $now = now();
        $maxWindow = $now->copy()->addDays(65);

        $docs = VendorDocument::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $maxWindow])
            ->limit(500)->get();

        foreach ($docs as $doc) {
            $days = (int) ceil($now->diffInSeconds($doc->expires_at, false) / 86400);
            $thresholds = $doc->document_type === 'coi'
                ? [$settings['coiAlertDays1'], $settings['coiAlertDays2'], $settings['coiAlertDays3']]
                : [$settings['contractAlertDays']];

            foreach ($thresholds as $threshold) {
                if ($days <= $threshold && $doc->last_alert_days_sent !== (string) $threshold) {
                    $this->fireDocumentAlert($doc, $days);
                    VendorDocument::query()->whereKey($doc->id)->update([
                        'last_alert_days_sent' => (string) $threshold,
                        'expiry_alert_sent_at' => now(),
                        'updated_at' => now(),
                    ]);
                    break;
                }
            }
        }

        $creds = VendorCredential::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $maxWindow])
            ->whereNull('expiry_alert_sent_at')
            ->limit(500)->get();

        foreach ($creds as $cred) {
            $days = (int) ceil($now->diffInSeconds($cred->expires_at, false) / 86400);
            if ($days <= $settings['credentialAlertDays']) {
                $this->fireCredentialAlert($cred, $days);
                VendorCredential::query()->whereKey($cred->id)->update([
                    'expiry_alert_sent_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function fireDocumentAlert(VendorDocument $doc, int $days): void
    {
        $vendor = VendorProfile::query()->whereKey($doc->vendor_id)->first();
        $company = $vendor !== null ? $vendor->company_name : 'Unknown Vendor';
        $label = $doc->document_type === 'coi' ? 'Certificate of Insurance' : 'Document';
        $urgency = $days <= 7 ? 'URGENT: ' : '';
        $priority = $days <= 7 ? 'high' : 'normal';
        $plural = $days === 1 ? '' : 's';

        try {
            event(new TaskRequested(
                pluginNamespace: 'vb-vendor-manager',
                tenantType: $doc->tenant_type, tenantId: $doc->tenant_id,
                requestedBy: TenantContext::SYSTEM_ACTOR,
                title: "{$urgency}{$company}: {$label} expires in {$days} days",
                description: "Renew or replace the {$label} for vendor {$company} before it expires.",
                priority: $priority,
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            event(new FeedEventRequested(
                tenantType: $doc->tenant_type, tenantId: $doc->tenant_id,
                actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
                sourceType: 'vb-vendor-manager', sourceId: (string) $doc->id,
                pluginNamespace: 'vb-vendor-manager', eventType: 'vendor.document_expiry_alert',
                summary: "{$urgency}{$company} — {$label} expires in {$days} day{$plural}.",
                priority: $priority,
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function fireCredentialAlert(VendorCredential $cred, int $days): void
    {
        $vendor = VendorProfile::query()->whereKey($cred->vendor_id)->first();
        $company = $vendor !== null ? $vendor->company_name : 'Unknown Vendor';
        $type = strtoupper($cred->credential_type);

        try {
            event(new TaskRequested(
                pluginNamespace: 'vb-vendor-manager',
                tenantType: $cred->tenant_type, tenantId: $cred->tenant_id,
                requestedBy: TenantContext::SYSTEM_ACTOR,
                title: "{$company}: {$cred->credential_name} credential expires in {$days} days",
                description: "Renew the {$type} credential \"{$cred->credential_name}\" for vendor {$company}.",
                priority: 'normal',
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        try {
            event(new FeedEventRequested(
                tenantType: $cred->tenant_type, tenantId: $cred->tenant_id,
                actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
                sourceType: 'vb-vendor-manager', sourceId: (string) $cred->id,
                pluginNamespace: 'vb-vendor-manager', eventType: 'vendor.credential_expiry_alert',
                summary: "{$company} — {$cred->credential_name} ({$type}) credential expires in {$days} days.",
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
