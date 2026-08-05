<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers\Concerns;

use App\Support\EntityReferenceService;
use App\Support\TenantContext;
use Vctrs\Plugins\Vault\VaultDirectory;
use Vctrs\Plugins\VbVendorManager\Support\VendorRelation;

trait ResolvesVaultEvidence
{
    protected function vaultDirectoryAvailable(): bool
    {
        return class_exists(VaultDirectory::class) && app()->bound(VaultDirectory::class);
    }

    /**
     * @return array{id: string, title: string, document_class: string, current_version: int}|null
     */
    protected function resolveEvidence(?string $vaultDocumentId, TenantContext $ctx): ?array
    {
        if ($vaultDocumentId === null || $vaultDocumentId === '' || ! $this->vaultDirectoryAvailable()) {
            return null;
        }

        return app(VaultDirectory::class)->lookup($ctx->activeTenantType(), $ctx->activeTenantId(), $vaultDocumentId);
    }

    /**
     * Idempotently reconcile a source → vault.document 'evidence' edge: unlink the old
     * target when it changed, link the new target when it is a non-empty string.
     */
    protected function reconcileEvidenceEdge(TenantContext $ctx, string $sourceType, string $sourceId, ?string $previousVaultId, ?string $newVaultId): void
    {
        $refs = app(EntityReferenceService::class);
        $tt = $ctx->activeTenantType();
        $tid = $ctx->activeTenantId();
        $createdBy = $ctx->userId() !== '' ? $ctx->userId() : null;

        if ($previousVaultId !== null && $previousVaultId !== '' && $previousVaultId !== $newVaultId) {
            $refs->unlink($tt, $tid, $sourceType, $sourceId, VendorRelation::VAULT_TARGET_TYPE, $previousVaultId, VendorRelation::EVIDENCE);
        }
        if ($newVaultId !== null && $newVaultId !== '') {
            $refs->link($tt, $tid, $sourceType, $sourceId, VendorRelation::VAULT_TARGET_TYPE, $newVaultId, VendorRelation::EVIDENCE, $createdBy);
        }
    }
}
