<?php

declare(strict_types=1);

/**
 * Relation-vocabulary guard.
 *
 * VendorRelation's relation constants ALIAS core App\Support\EntityRelation (EVIDENCE was
 * always core vocabulary; ACCOUNT_REP was promoted in Track-B S1). The literal assertions
 * below are the ones that matter: they pin the WIRE values written to
 * entity_references.relation, so the alias can never silently rewrite stored edges if
 * core's constant values are ever changed. The identity assertions document that the
 * alias is in place.
 *
 * EntityReferenceService::link() does NOT validate against the registry, so nothing at
 * runtime enforces this — the guard is the enforcement.
 */

use App\Support\EntityRelation;
use Vctrs\Plugins\VbVendorManager\Support\VendorRelation;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    vmInstallSignedAndBoot(vmBindTenant(pluginTestUser()->id));
});

it('pins the on-disk relation strings the plugin writes', function () {
    expect(VendorRelation::EVIDENCE)->toBe('evidence')
        ->and(VendorRelation::ACCOUNT_REP)->toBe('account_rep');
});

it('aliases the core EntityRelation vocabulary', function () {
    expect(VendorRelation::EVIDENCE)->toBe(EntityRelation::EVIDENCE)
        ->and(VendorRelation::ACCOUNT_REP)->toBe(EntityRelation::ACCOUNT_REP);
});

it('uses relations core recognises as valid', function () {
    expect(EntityRelation::isValid(VendorRelation::EVIDENCE))->toBeTrue()
        ->and(EntityRelation::isValid(VendorRelation::ACCOUNT_REP))->toBeTrue();
});

it('keeps the entity-type constants plugin-local (core has no registry for them)', function () {
    expect(VendorRelation::DOC_SOURCE_TYPE)->toBe('vb-vendor-manager.document')
        ->and(VendorRelation::CRED_SOURCE_TYPE)->toBe('vb-vendor-manager.credential')
        ->and(VendorRelation::PROFILE_SOURCE_TYPE)->toBe('vb-vendor-manager.profile')
        ->and(VendorRelation::VAULT_TARGET_TYPE)->toBe('vault.document')
        ->and(VendorRelation::STAFF_TARGET_TYPE)->toBe('staff.employee');
});
