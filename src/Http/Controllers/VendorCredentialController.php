<?php

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Vctrs\Plugins\VbVendorManager\Models\VendorCredential;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

class VendorCredentialController extends Controller
{
    public function add(Request $request, string $vendorId): JsonResponse
    {
        $v = $request->validate([
            'credentialType' => ['required', 'in:bg77,afg,other'],
            'credentialName' => ['required', 'string', 'min:1', 'max:200'],
            'credentialNumber' => ['nullable', 'string', 'max:100'],
            'expiresAt' => ['nullable', 'date'],
            'vaultDocumentId' => ['nullable', 'uuid'],
        ]);

        $vendor = VendorProfile::query()->findOrFail($vendorId);

        AuditContext::tag('credential.add');
        $cred = VendorCredential::create([
            'vendor_id' => $vendor->id,
            'credential_type' => $v['credentialType'],
            'credential_name' => $v['credentialName'],
            'credential_number' => $v['credentialNumber'] ?? null,
            'expires_at' => ! empty($v['expiresAt']) ? Carbon::parse($v['expiresAt']) : null,
            'vault_document_id' => $v['vaultDocumentId'] ?? null,
        ]);

        return response()->json(['data' => ['credential' => $cred]], 201);
    }

    public function list(string $vendorId): JsonResponse
    {
        $creds = VendorCredential::query()->where('vendor_id', $vendorId)->orderByDesc('created_at')->get();

        return response()->json(['data' => ['credentials' => $creds]]);
    }

    public function remove(string $id): JsonResponse
    {
        $cred = VendorCredential::query()->findOrFail($id);
        AuditContext::tag('credential.remove');
        $cred->delete();

        return response()->json(['data' => ['removed' => true]]);
    }
}
