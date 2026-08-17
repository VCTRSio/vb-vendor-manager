<?php

declare(strict_types=1);

namespace Vctrs\Plugins\VbVendorManager\Support;

use App\Support\EntityRelation;
use App\Support\EntityType;

/**
 * Plugin-local vocabulary for App\Support\EntityReferenceService edges. Both halves
 * now ALIAS the core registries:
 *
 *  - relation VERBS  → App\Support\EntityRelation (EVIDENCE was always core
 *    vocabulary; ACCOUNT_REP was promoted in Track-B S1)
 *  - entity TYPES    → App\Support\EntityType (the canonical type registry)
 *
 * Every aliased value is byte-identical to the string already stored in
 * entity_references, so aliasing is a no-op on disk — source cleanliness only.
 * Neither registry is a gate: link() validates against neither.
 *
 * CORRECTION (v1.1.3): the v1.1.2 note claiming "core has no registry for them"
 * was wrong — App\Support\EntityType existed all along and simply had no adopters.
 * The *_TARGET_TYPE constants are now aliased to it.
 *
 * The *_SOURCE_TYPE constants stay literals: plugin-owned source types are
 * namespaced to this plugin and have no core registry entry (EntityType catalogs
 * the shared cross-plugin nouns, not each plugin's own row types).
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

    public const VAULT_TARGET_TYPE = EntityType::VAULT_DOCUMENT;

    public const STAFF_TARGET_TYPE = EntityType::STAFF_EMPLOYEE;
}
