<?php

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Audit\AuditContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

class VendorApiKeyController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['sometimes', 'in:pending,active,inactive,rejected'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);
        $limit = (int) ($v['limit'] ?? 100);
        $offset = (int) ($v['offset'] ?? 0);

        $q = VendorProfile::query()->whereNull('deleted_at');
        if (isset($v['status'])) {
            $q->where('status', $v['status']);
        }
        $total = (clone $q)->count();

        $rows = $q->orderByDesc('company_name')->limit($limit)->offset($offset)
            ->get(['id', 'company_name', 'status', 'api_key_prefix', 'api_key_issued_at', 'api_key_revoked_at']);

        $items = $rows->map(fn (VendorProfile $r) => [
            'id' => $r->id,
            'companyName' => $r->company_name,
            'status' => $r->status,
            'apiKeyPrefix' => $r->api_key_prefix,
            'apiKeyIssuedAt' => $r->api_key_issued_at,
            'apiKeyRevokedAt' => $r->api_key_revoked_at,
        ]);

        return response()->json(['data' => ['items' => $items, 'total' => $total]]);
    }

    public function issue(string $vendorId): JsonResponse
    {
        $vendor = VendorProfile::query()->findOrFail($vendorId);
        abort_if($vendor->status !== 'active', 422, 'Vendor must be active to receive a portal API key.');

        $rawKey = 'vnd_'.bin2hex(random_bytes(24));
        $keyHash = hash('sha256', $rawKey);
        $keyPrefix = substr($rawKey, 0, 12);

        VendorProfile::query()->whereKey($vendor->id)->update([
            'api_key_hash' => $keyHash,
            'api_key_prefix' => $keyPrefix,
            'api_key_issued_at' => now(),
            'api_key_revoked_at' => null,
            'updated_at' => now(),
        ]);

        AuditContext::tag('api_key.issue');

        return response()->json(['data' => ['apiKey' => $rawKey, 'keyPrefix' => $keyPrefix]]);
    }

    public function revoke(string $vendorId): JsonResponse
    {
        $vendor = VendorProfile::query()->findOrFail($vendorId);

        VendorProfile::query()->whereKey($vendor->id)->update([
            'api_key_hash' => null,
            'api_key_revoked_at' => now(),
            'updated_at' => now(),
        ]);

        AuditContext::tag('api_key.revoke');

        return response()->json(['data' => ['revoked' => true]]);
    }
}
