<?php

namespace Vctrs\Plugins\VendorManager\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;

class VendorController extends Controller
{
    public function index(Request $request): Response
    {
        $base = VendorProfile::query()->whereNull('deleted_at');

        $query = (clone $base);

        if (($status = $request->query('status')) !== null && $status !== 'all') {
            $query->where('status', $status);
        }

        return Inertia::render('Vendor/Index', [
            'vendors' => $query->orderBy('company_name')
                ->get()
                ->map(fn (VendorProfile $v) => $v->only(VendorProfile::SAFE_FIELDS)),
            'stats' => [
                'active' => (clone $base)->where('status', 'active')->count(),
                'pending' => (clone $base)->where('status', 'pending')->count(),
                'expiringCoiCount' => (clone $base)
                    ->where('status', 'active')
                    ->whereNotNull('coi_expiry_date')
                    ->whereBetween('coi_expiry_date', [now(), now()->addDays(30)])
                    ->count(),
                'noContractCount' => (clone $base)
                    ->where('status', 'active')
                    ->where('has_active_contract', false)
                    ->count(),
            ],
            'filters' => ['status' => $request->query('status', 'all')],
        ]);
    }

    public function show(string $id): Response
    {
        $vendor = VendorProfile::query()
            ->whereNull('deleted_at')
            ->findOrFail($id);

        return Inertia::render('Vendor/Show', [
            'vendor' => $vendor->only(VendorProfile::SAFE_FIELDS),
            'onboarding' => $vendor->onboardingSteps()->orderBy('created_at')->get(),
            'documents' => $vendor->documents()->whereNull('deleted_at')
                ->orderByDesc('created_at')->get(),
            'credentials' => $vendor->credentials()->orderByDesc('created_at')->get(),
        ]);
    }
}
