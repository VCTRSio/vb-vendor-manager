<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager\Support;

use App\Support\EntityRelation;

/**
 * Plugin-local relation vocabulary for App\Support\EntityReferenceService edges.
 * The relation constants now ALIAS core App\Support\EntityRelation — EVIDENCE was
 * always core vocabulary, and ACCOUNT_REP was promoted in Track-B S1. The promoted
 * values are byte-identical to the strings already stored in
 * entity_references.relation, so aliasing is a no-op on disk — source cleanliness
 * only. link() still does not validate against the core registry; this is a
 * canonical vocabulary, not a gate.
 *
 * The *_TYPE constants stay plugin-local: they are entity-type identifiers, not
 * relations, and core has no registry for them.
 */
final class VendorRelation
{
    /** vendor document / credential → the vault document that is its evidence. */
    public const EVIDENCE = EntityRelation::EVIDENCE;

    /** vendor profile → the staff employee who is its internal account rep. */
    public const ACCOUNT_REP = EntityRelation::ACCOUNT_REP;

    public const DOC_SOURCE_TYPE = 'vb-vendor-manager.document';

    public const CRED_SOURCE_TYPE = 'vb-vendor-manager.credential';

    public const PROFILE_SOURCE_TYPE = 'vb-vendor-manager.profile';

    public const VAULT_TARGET_TYPE = 'vault.document';

    public const STAFF_TARGET_TYPE = 'staff.employee';
}
