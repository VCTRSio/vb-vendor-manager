<?php

namespace Vctrs\Plugins\VendorManager\Http\Controllers;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;
use Vctrs\Plugins\VendorManager\Services\VendorService;

class VendorMutationController extends Controller
{
    public function __construct(private readonly VendorService $vendors) {}

    public function create(Request $request): JsonResponse
    {
        $v = $request->validate([
            'companyName' => ['required', 'string', 'max:200'],
            'contactName' => ['nullable', 'string', 'max:200'],
            'contactEmail' => ['nullable', 'email', 'max:200'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'category' => ['required', 'in:oem,aftermarket,marketing,facility,technology'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $vendor = $this->vendors->createVendor([
            'company_name' => $v['companyName'],
            'contact_name' => $v['contactName'] ?? null,
            'contact_email' => $v['contactEmail'] ?? null,
            'contact_phone' => $v['contactPhone'] ?? null,
            'category' => $v['category'],
            'notes' => $v['notes'] ?? null,
        ], app(TenantContext::class)->userId());

        return response()->json(['data' => ['vendor' => $vendor->only(VendorProfile::SAFE_FIELDS)]], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $v = $request->validate([
            'companyName' => ['sometimes', 'string', 'max:200'],
            'contactName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'contactEmail' => ['sometimes', 'nullable', 'email', 'max:200'],
            'contactPhone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'category' => ['sometimes', 'in:oem,aftermarket,marketing,facility,technology'],
            'w9OnFile' => ['sometimes', 'boolean'],
            'contractStart' => ['sometimes', 'nullable', 'date'],
            'contractEnd' => ['sometimes', 'nullable', 'date'],
            'contractValueMonthly' => ['sometimes', 'nullable', 'string'],
            'contractValueAnnual' => ['sometimes', 'nullable', 'string'],
            'hasActiveContract' => ['sometimes', 'boolean'],
            'oemCertifications' => ['sometimes', 'array'],
            'oemCertifications.*' => ['string'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $vendor = VendorProfile::query()->findOrFail($id);

        $map = [
            'companyName' => 'company_name', 'contactName' => 'contact_name',
            'contactEmail' => 'contact_email', 'contactPhone' => 'contact_phone',
            'category' => 'category', 'w9OnFile' => 'w9_on_file',
            'contractValueMonthly' => 'contract_value_monthly',
            'contractValueAnnual' => 'contract_value_annual',
            'hasActiveContract' => 'has_active_contract', 'notes' => 'notes',
        ];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $v)) {
                $vendor->{$col} = $v[$in];
            }
        }
        if (array_key_exists('oemCertifications', $v)) {
            $vendor->oem_certifications_json = $v['oemCertifications'];
        }
        if (array_key_exists('contractStart', $v)) {
            $vendor->contract_start = $v['contractStart'] ? \Illuminate\Support\Carbon::parse($v['contractStart']) : null;
        }
        if (array_key_exists('contractEnd', $v)) {
            $vendor->contract_end = $v['contractEnd'] ? \Illuminate\Support\Carbon::parse($v['contractEnd']) : null;
        }

        AuditContext::tag('vendor.update');
        $this->touchAndSave($vendor);

        return response()->json(['data' => ['vendor' => $vendor->only(VendorProfile::SAFE_FIELDS)]]);
    }

    /**
     * Always bump updated_at and persist so the save fires the AuditObserver (audit row)
     * even on a no-op field set — matching core, which always sets updatedAt=now() and
     * always audits. Eloquent's date dirty-check is second-granular, so a plain
     * updated_at=now() within the same clock second as the stored value reads as clean
     * and the save would no-op. Guaranteeing the new value is strictly greater than the
     * stored timestamp keeps the model dirty without any reflection or event trickery.
     */
    private function touchAndSave(VendorProfile $vendor): void
    {
        $now = now();
        $current = $vendor->getOriginal('updated_at');
        if ($current !== null) {
            // Eloquent's date dirty-check compares at the model's date format (second
            // granularity), so bump to at least one second past the stored value when
            // they fall in the same second — otherwise the save reads as clean and no-ops.
            $currentSecond = \Illuminate\Support\Carbon::parse($current)->startOfSecond();
            if ($now->copy()->startOfSecond()->lessThanOrEqualTo($currentSecond)) {
                $now = $currentSecond->copy()->addSecond();
            }
        }
        $vendor->updated_at = $now;
        $vendor->save();
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $v = $request->validate([
            'status' => ['required', 'in:pending,active,inactive,rejected'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $vendor = VendorProfile::query()->findOrFail($id);
        AuditContext::tag('vendor.status_change');
        $vendor->status = $v['status'];
        $this->touchAndSave($vendor);

        return response()->json(['data' => ['vendor' => $vendor->only(VendorProfile::SAFE_FIELDS)]]);
    }
}
