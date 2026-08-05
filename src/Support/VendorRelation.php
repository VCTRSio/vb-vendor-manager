<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager\Support;

/**
 * Plugin-local relation vocabulary for App\Support\EntityReferenceService edges.
 * Values intentionally match core App\Support\EntityRelation's shared vocabulary
 * ('evidence'); link() does not validate against the core enum, so we redeclare here
 * to keep this Expand pass self-contained and zero-core.
 */
final class VendorRelation
{
    /** vendor document / credential → the vault document that is its evidence. */
    public const EVIDENCE = 'evidence';

    /** vendor profile → the staff employee who is its internal account rep. */
    public const ACCOUNT_REP = 'account_rep';

    public const DOC_SOURCE_TYPE = 'vb-vendor-manager.document';

    public const CRED_SOURCE_TYPE = 'vb-vendor-manager.credential';

    public const PROFILE_SOURCE_TYPE = 'vb-vendor-manager.profile';

    public const VAULT_TARGET_TYPE = 'vault.document';

    public const STAFF_TARGET_TYPE = 'staff.employee';
}
