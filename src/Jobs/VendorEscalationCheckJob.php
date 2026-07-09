<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VendorManager\Jobs;

use App\Events\FeedEventRequested;
use App\Plugins\Scheduling\PluginScheduledJob;
use App\Support\TenantContext;
use Vctrs\Plugins\VendorManager\Models\VendorDocument;

final class VendorEscalationCheckJob extends PluginScheduledJob
{
    protected function run(): void
    {
        $now = now();
        $sevenDays = $now->copy()->addDays(7);

        $docs = VendorDocument::query()
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $sevenDays])
            ->limit(200)->get();

        foreach ($docs as $doc) {
            $label = $doc->document_type === 'coi' ? 'COI' : 'Document';
            try {
                event(new FeedEventRequested(
                    tenantType: $doc->tenant_type, tenantId: $doc->tenant_id,
                    actorType: 'system', actorId: TenantContext::SYSTEM_ACTOR,
                    sourceType: 'vendor-manager', sourceId: (string) $doc->id,
                    pluginNamespace: 'vendor-manager', eventType: 'vendor.expiry_escalation',
                    summary: "ESCALATION: {$label} for vendor expires in less than 7 days and has not been renewed.",
                    priority: 'high',
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
