<?php

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\StaffHub\StaffDirectory;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\Concerns\ResolvesVaultEvidence;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;
use Vctrs\Plugins\VbVendorManager\Services\VendorService;

class VendorReadController extends Controller
{
    use ResolvesVaultEvidence;

    public function __construct(private readonly VendorService $vendors) {}

    public function stats(): JsonResponse
    {
        $base = fn () => VendorProfile::query()->whereNull('deleted_at');

        return response()->json(['data' => [
            'total' => $base()->count(),
            'active' => $base()->where('status', 'active')->count(),
            'pending' => $base()->where('status', 'pending')->count(),
            'expiringCoiCount' => $base()->where('status', 'active')
                ->whereNotNull('coi_expiry_date')
                ->whereBetween('coi_expiry_date', [now(), now()->addDays(30)])
                ->count(),
            'noContractCount' => $base()->where('status', 'active')
                ->where('has_active_contract', false)->count(),
        ]]);
    }

    public function list(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['sometimes', 'in:oem,aftermarket,marketing,facility,technology'],
            'status' => ['sometimes', 'in:pending,active,inactive,rejected'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);
        $limit = (int) ($validated['limit'] ?? 50);
        $offset = (int) ($validated['offset'] ?? 0);

        $q = VendorProfile::query()->whereNull('deleted_at');
        if (isset($validated['category'])) {
            $q->where('category', $validated['category']);
        }
        if (isset($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('company_name')->limit($limit)->offset($offset)->get();

        $settings = $this->vendors->resolveSettings();
        $items = $rows->map(function (VendorProfile $v) use ($settings) {
            $out = $v->only(VendorProfile::SAFE_FIELDS);
            $out['complianceStatus'] = $this->vendors->complianceStatus($v, $settings);

            return $out;
        });

        return response()->json(['data' => ['items' => $items, 'total' => $total]]);
    }

    public function get(string $id): JsonResponse
    {
        $vendor = VendorProfile::query()->whereNull('deleted_at')->findOrFail($id);
        $settings = $this->vendors->resolveSettings();

        $out = $vendor->only(VendorProfile::SAFE_FIELDS);
        $out['complianceStatus'] = $this->vendors->complianceStatus($vendor, $settings);

        $ctx = app(TenantContext::class);
        $documents = $vendor->documents()->whereNull('deleted_at')->orderByDesc('created_at')->get()
            ->map(function ($d) use ($ctx) {
                $row = $d->toArray();
                $row['evidence'] = $this->resolveEvidence($d->vault_document_id, $ctx);

                return $row;
            });
        $credentials = $vendor->credentials()->orderByDesc('created_at')->get()
            ->map(function ($c) use ($ctx) {
                $row = $c->toArray();
                $row['evidence'] = $this->resolveEvidence($c->vault_document_id, $ctx);

                return $row;
            });

        return response()->json(['data' => [
            'vendor' => $out,
            'documents' => $documents,
            'onboardingHistory' => $vendor->onboardingSteps()->orderBy('created_at')->get(),
            'credentials' => $credentials,
            'accountRep' => $this->resolveAccountRep($vendor->account_rep_employee_id),
        ]]);
    }

    /**
     * Live (name-cache-free) account-rep resolution for the detail view. The staff-hub
     * seam is optional — when it is not bound (staff-hub absent), degrade to null rather
     * than throw. Detail-only by design: the list() view keeps the raw
     * account_rep_employee_id pointer (via SAFE_FIELDS) and does NOT resolve names per
     * row, so there is no N+1.
     */
    private function resolveAccountRep(?string $employeeId): ?array
    {
        if ($employeeId === null || $employeeId === '' || ! (class_exists(StaffDirectory::class) && app()->bound(StaffDirectory::class))) {
            return null;
        }
        $ctx = app(TenantContext::class);

        return app(StaffDirectory::class)->lookup($ctx->activeTenantType(), $ctx->activeTenantId(), $employeeId);
    }
}
