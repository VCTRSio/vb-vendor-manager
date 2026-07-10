<?php

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

class VendorReportController extends Controller
{
    public function contract(Request $request): JsonResponse
    {
        $v = $request->validate([
            'category' => ['sometimes', 'in:oem,aftermarket,marketing,facility,technology'],
        ]);

        $q = VendorProfile::query()->whereNull('deleted_at')->where('status', 'active');
        if (isset($v['category'])) {
            $q->where('category', $v['category']);
        }

        $vendors = $q->orderBy('category')->orderBy('company_name')
            ->get(['id', 'company_name', 'category', 'contract_value_monthly', 'contract_value_annual', 'has_active_contract', 'contract_end']);

        $totalMonthly = $vendors->sum(fn (VendorProfile $vendor) => (float) ($vendor->contract_value_monthly ?? 0));
        $totalAnnual = $vendors->sum(fn (VendorProfile $vendor) => (float) ($vendor->contract_value_annual ?? 0));
        $withoutContract = $vendors->filter(fn (VendorProfile $vendor) => ! $vendor->has_active_contract)->count();

        return response()->json(['data' => [
            'vendors' => $vendors,
            'totalMonthly' => number_format($totalMonthly, 2, '.', ''),
            'totalAnnual' => number_format($totalAnnual, 2, '.', ''),
            'withoutContractCount' => $withoutContract,
        ]]);
    }
}
