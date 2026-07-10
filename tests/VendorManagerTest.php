<?php

/**
 * Post-install discovery proof for the standalone harness.
 *
 * The original core VendorManagerTest exercised an in-tree PluginManager
 * (`new PluginManager(base_path('plugins'))->discover()`) plus a server-rendered
 * Inertia surface (`/dashboard/vendor` Index, `/dashboard/vendor/onboarding`).
 * Neither applies here: vb-vendor-manager is an EXTERNAL uploaded plugin (not in
 * base_path('plugins')) and it now ships a `uiMode: module` ESM bundle — the
 * server-rendered Inertia pages were retired. The vendor CRUD/read/onboarding
 * behavior those Inertia routes covered is now proven against the JSON API in
 * VendorRead/VendorMutation/VendorOnboarding tests.
 *
 * What remains uniquely worth asserting — and what this test now proves — is that
 * once the SIGNED plugin is installed + booted + enabled, the host PluginManager
 * surfaces its nav entry and dashboard widgets (the manifest→runtime contract).
 */

use App\Plugins\PluginLifecycle;
use App\Plugins\PluginManager;

require_once __DIR__.'/vm_bootstrap.php';

it('installs vb-vendor-manager and contributes its nav item + widgets to the host', function () {
    $user = pluginTestUser('rooftop_owner');
    vmInstallSignedAndBoot(vmBindTenant($user->id));

    // navItems()/widgets() only surface ENABLED plugins; the manifest ships
    // enabledByDefault=false, so enable it for this tenant first.
    app(PluginLifecycle::class)->enable('vb-vendor-manager');

    $mgr = app(PluginManager::class);

    $nav = $mgr->navItems();
    expect(array_column($nav, 'key'))->toContain('vendor');

    $vendorNav = collect($nav)->firstWhere('key', 'vendor');
    expect($vendorNav['href'])->toBe('/dashboard/plugins/vb-vendor-manager/view');

    expect(array_keys($mgr->widgets()))->toContain('vendor.activeVendors');
});
