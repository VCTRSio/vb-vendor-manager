<?php

namespace Vctrs\Plugins\VendorManager\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Vctrs\Plugins\VendorManager\Http\Requests\StoreVendorRequest;
use Vctrs\Plugins\VendorManager\Services\VendorService;

class OnboardingController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Vendor/Onboarding');
    }

    public function store(StoreVendorRequest $request): RedirectResponse
    {
        $uid = app(TenantContext::class)->userId();

        $vendor = app(VendorService::class)->createVendor([
            'company_name' => $request->validated('company_name'),
            'contact_name' => $request->validated('contact_name'),
            'contact_email' => $request->validated('contact_email'),
            'contact_phone' => $request->validated('contact_phone'),
            'category' => $request->validated('category'),
            'notes' => $request->validated('notes'),
        ], $uid);

        return redirect()->route('vendor.show', $vendor->id)
            ->with('success', 'Vendor request submitted');
    }
}
