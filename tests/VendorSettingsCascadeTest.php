<?php

use App\Plugins\PluginSettings;
use App\Support\TenantContext;

require_once __DIR__.'/vm_bootstrap.php';

// Install + boot the signed plugin so PluginManager knows its manifest (the
// settings-cascade base). ENABLE it too: the generic settings-update route
// (PluginSettingsController::update) 404s unless the plugin is enabled for the
// tenant, and this manifest ships enabledByDefault=false.
beforeEach(function () {
    vmInstallSignedAndBoot(vmBindTenant(pluginTestUser()->id));
    app(\App\Plugins\PluginLifecycle::class)->enable('vb-vendor-manager');
});

it('resolves vb-vendor-manager settings defaults from the manifest', function () {
    app()->instance(TenantContext::class, new TenantContext('u', 'rooftop', PLUGIN_TEST_TENANT, ''));

    $s = app(PluginSettings::class)->resolve('vb-vendor-manager');

    expect($s['coiAlertDays1'])->toBe(60)
        ->and($s['coiAlertDays2'])->toBe(30)
        ->and($s['coiAlertDays3'])->toBe(7)
        ->and($s['contractAlertDays'])->toBe(30)
        ->and($s['credentialAlertDays'])->toBe(30)
        ->and($s['requireCoi'])->toBeTrue()
        ->and($s['requireW9'])->toBeTrue();
});

it('a rooftop_owner saves vb-vendor-manager settings through the generic router into the cascade', function () {
    $u = pluginTestUser('rooftop_owner');

    $this->actingAs($u)->put('/dashboard/plugins/vb-vendor-manager/settings', [
        'settings' => ['coiAlertDays1' => 45, 'requireCoi' => false],
    ])->assertRedirect();

    app()->forgetInstance(PluginSettings::class);
    $s = app(PluginSettings::class)->resolve('vb-vendor-manager');

    expect($s['coiAlertDays1'])->toBe(45)
        ->and($s['requireCoi'])->toBeFalse();
});

it('resolve reflects a setOverride for vb-vendor-manager settings', function () {
    app()->instance(TenantContext::class, new TenantContext('u', 'rooftop', PLUGIN_TEST_TENANT, ''));

    app(PluginSettings::class)->setOverride('vb-vendor-manager', 'rooftop', PLUGIN_TEST_TENANT, [
        'coiAlertDays3' => 3,
        'requireW9' => false,
    ]);

    app()->forgetInstance(PluginSettings::class);
    app()->instance(TenantContext::class, new TenantContext('u', 'rooftop', PLUGIN_TEST_TENANT, ''));

    $s = app(PluginSettings::class)->resolve('vb-vendor-manager');

    expect($s['coiAlertDays3'])->toBe(3)
        ->and($s['requireW9'])->toBeFalse();
});
