<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager;

use App\Support\SystemContext;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

/**
 * PII-free outbound read seam for other plugins/core. Deliberately narrower than
 * VendorProfile::SAFE_FIELDS — never exposes contact_email / contact_phone / notes.
 * Returns plain arrays, never Eloquent models. Tenant is passed explicitly and applied
 * with withoutTenantScope so cross-tenant callers cannot leak (DB FORCE-RLS remains the
 * real guard).
 */
class VendorDirectory
{
    /** @var array<int, string> */
    private const FIELDS = ['id', 'company_name', 'category', 'status', 'has_active_contract', 'coi_expiry_date'];

    /**
     * @return array{id: string, company_name: string, category: string, status: string, has_active_contract: bool, coi_expiry_date: mixed}|null
     */
    public function lookup(string $tenantType, string $tenantId, string $id): ?array
    {
        return SystemContext::runAsTenant($tenantType, $tenantId, function () use ($tenantType, $tenantId, $id): ?array {
            $vendor = VendorProfile::withoutTenantScope()
                ->where('tenant_type', $tenantType)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->find($id, self::FIELDS);

            return $vendor?->only(self::FIELDS);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(string $tenantType, string $tenantId, ?string $category = null, int $limit = 100): array
    {
        return SystemContext::runAsTenant($tenantType, $tenantId, function () use ($tenantType, $tenantId, $category, $limit): array {
            $q = VendorProfile::withoutTenantScope()
                ->where('tenant_type', $tenantType)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('status', 'active');
            if ($category !== null && $category !== '') {
                $q->where('category', $category);
            }

            return $q->orderBy('company_name')->limit($limit)->get(self::FIELDS)
                ->map(fn (VendorProfile $v) => $v->only(self::FIELDS))
                ->all();
        });
    }
}
