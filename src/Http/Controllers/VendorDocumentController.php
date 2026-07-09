<?php

namespace Vctrs\Plugins\VendorManager\Http\Controllers;

use App\Audit\AuditContext;
use App\Events\FeedEventRequested;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Vctrs\Plugins\VendorManager\Models\VendorDocument;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;
use Vctrs\Plugins\VendorManager\Services\VendorService;

class VendorDocumentController extends Controller
{
    public function __construct(private readonly VendorService $vendors) {}

    public function add(Request $request, string $vendorId): JsonResponse
    {
        $v = $request->validate([
            'documentType' => ['required', 'in:coi,w9,service_agreement,nda,insurance_cert,other'],
            'documentName' => ['nullable', 'string', 'max:200'],
            'vaultDocumentId' => ['nullable', 'uuid'],
            'expiresAt' => ['nullable', 'date'],
        ]);

        $vendor = VendorProfile::query()->whereNull('deleted_at')->findOrFail($vendorId);
        $uid = app(TenantContext::class)->userId();

        // Tag BEFORE the create so the doc-create audit row gets procedure document.add.
        // The COI-sync / W9 profile updates below are mass ->update() calls (no model
        // events), so they cannot consume the tag.
        AuditContext::tag('document.add');
        $doc = VendorDocument::create([
            'vendor_id' => $vendor->id,
            'document_type' => $v['documentType'],
            'document_name' => $v['documentName'] ?? null,
            'vault_document_id' => $v['vaultDocumentId'] ?? null,
            'expires_at' => ! empty($v['expiresAt']) ? Carbon::parse($v['expiresAt']) : null,
            'uploaded_by' => $uid,
        ]);

        if ($v['documentType'] === 'coi' && ! empty($v['expiresAt'])) {
            $this->vendors->syncCoiExpiry($vendor->id);
        }
        if ($v['documentType'] === 'w9') {
            VendorProfile::query()->whereKey($vendor->id)->update(['w9_on_file' => true, 'updated_at' => now()]);
        }

        try {
            event(new FeedEventRequested(
                tenantType: $doc->tenant_type,
                tenantId: $doc->tenant_id,
                actorType: 'user',
                actorId: $uid,
                sourceType: 'vendor-manager',
                sourceId: (string) $doc->id,
                pluginNamespace: 'vendor-manager',
                eventType: 'vendor.document.added',
                summary: "Document added to {$vendor->company_name}: {$doc->document_type}",
            ));
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['data' => ['document' => $doc]], 201);
    }

    public function list(Request $request, string $vendorId): JsonResponse
    {
        $v = $request->validate([
            'documentType' => ['sometimes', 'in:coi,w9,service_agreement,nda,insurance_cert,other'],
        ]);

        $q = VendorDocument::query()->where('vendor_id', $vendorId)->whereNull('deleted_at');
        if (isset($v['documentType'])) {
            $q->where('document_type', $v['documentType']);
        }

        return response()->json(['data' => ['documents' => $q->orderByDesc('created_at')->get()]]);
    }

    public function remove(string $id): JsonResponse
    {
        $doc = VendorDocument::query()->findOrFail($id);
        $wasCoi = $doc->document_type === 'coi';
        $vendorId = $doc->vendor_id;

        // Tag BEFORE the delete so the delete audit row gets procedure document.remove.
        AuditContext::tag('document.remove');
        $doc->delete();

        if ($wasCoi) {
            $this->vendors->syncCoiExpiry($vendorId);
        }

        return response()->json(['data' => ['removed' => true]]);
    }
}
