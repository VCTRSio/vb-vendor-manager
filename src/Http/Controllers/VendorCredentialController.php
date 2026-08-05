<?php

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Vctrs\Plugins\VbVendorManager\Http\Controllers\Concerns\ResolvesVaultEvidence;
use Vctrs\Plugins\VbVendorManager\Models\VendorCredential;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;
use Vctrs\Plugins\VbVendorManager\Support\VendorRelation;

class VendorCredentialController extends Controller
{
    use ResolvesVaultEvidence;

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
        $ctx = app(TenantContext::class);

        $cred = DB::transaction(function () use ($vendor, $v, $ctx) {
            AuditContext::tag('credential.add');
            $cred = VendorCredential::create([
                'vendor_id' => $vendor->id,
                'credential_type' => $v['credentialType'],
                'credential_name' => $v['credentialName'],
                'credential_number' => $v['credentialNumber'] ?? null,
                'expires_at' => ! empty($v['expiresAt']) ? Carbon::parse($v['expiresAt']) : null,
                'vault_document_id' => $v['vaultDocumentId'] ?? null,
            ]);

            $this->reconcileEvidenceEdge($ctx, VendorRelation::CRED_SOURCE_TYPE, (string) $cred->id, null, $v['vaultDocumentId'] ?? null);

            return $cred;
        });

        return response()->json(['data' => ['credential' => $cred]], 201);
    }

    public function setEvidence(Request $request, string $id): JsonResponse
    {
        $v = $request->validate(['vaultDocumentId' => ['nullable', 'uuid']]);
        $cred = VendorCredential::query()->findOrFail($id);
        $ctx = app(TenantContext::class);
        $previous = $cred->vault_document_id;
        $new = $v['vaultDocumentId'] ?? null;

        DB::transaction(function () use ($cred, $new, $ctx, $previous) {
            $cred->update(['vault_document_id' => $new]);
            $this->reconcileEvidenceEdge($ctx, VendorRelation::CRED_SOURCE_TYPE, (string) $cred->id, $previous, $new);
        });

        return response()->json(['data' => ['credential' => $cred->fresh(), 'evidence' => $this->resolveEvidence($new, $ctx)]]);
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
