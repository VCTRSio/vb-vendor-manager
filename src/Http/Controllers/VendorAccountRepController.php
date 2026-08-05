<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\EntityReferenceService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Vctrs\Plugins\StaffHub\StaffDirectory;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;
use Vctrs\Plugins\VbVendorManager\Support\VendorRelation;

class VendorAccountRepController extends Controller
{
    private function staffDirectoryAvailable(): bool
    {
        return class_exists(StaffDirectory::class) && app()->bound(StaffDirectory::class);
    }

    public function assignableStaff(): JsonResponse
    {
        if (! $this->staffDirectoryAvailable()) {
            return response()->json(['data' => ['employees' => []]]);
        }
        $ctx = app(TenantContext::class);
        $rows = app(StaffDirectory::class)->listAssignable($ctx->activeTenantType(), $ctx->activeTenantId());
        $employees = array_map(fn (array $e) => ['id' => $e['id'], 'display_name' => $e['display_name'] ?? ''], $rows);

        return response()->json(['data' => ['employees' => $employees]]);
    }

    public function assign(Request $request, string $vendorId): JsonResponse
    {
        $v = $request->validate(['employeeId' => ['nullable', 'uuid']]);
        $vendor = VendorProfile::query()->whereNull('deleted_at')->findOrFail($vendorId);
        $ctx = app(TenantContext::class);
        $previous = $vendor->account_rep_employee_id;
        $new = $v['employeeId'] ?? null;

        DB::transaction(function () use ($vendor, $new, $ctx, $previous) {
            VendorProfile::query()->whereKey($vendor->id)->update(['account_rep_employee_id' => $new, 'updated_at' => now()]);

            $refs = app(EntityReferenceService::class);
            $tt = $ctx->activeTenantType();
            $tid = $ctx->activeTenantId();
            if ($previous !== null && $previous !== '' && $previous !== $new) {
                $refs->unlink($tt, $tid, VendorRelation::PROFILE_SOURCE_TYPE, (string) $vendor->id, VendorRelation::STAFF_TARGET_TYPE, $previous, VendorRelation::ACCOUNT_REP);
            }
            if ($new !== null && $new !== '') {
                $refs->link($tt, $tid, VendorRelation::PROFILE_SOURCE_TYPE, (string) $vendor->id, VendorRelation::STAFF_TARGET_TYPE, $new, VendorRelation::ACCOUNT_REP, $ctx->userId() !== '' ? $ctx->userId() : null);
            }
        });

        return response()->json(['data' => ['accountRepEmployeeId' => $new]]);
    }
}
