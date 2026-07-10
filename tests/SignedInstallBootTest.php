<?php

declare(strict_types=1);

/**
 * THE proof: the shipping Vendor Manager plugin, packaged and SIGNED with the
 * real VCTRS first-party key, installs into the app, boots its server code,
 * serves a JSON route over HTTP, and creates its schema.
 *
 * This is the exact regression the vb-native spike caught (uploaded server-code
 * plugins that never boot in a web request → routes 404). The plugin tree is
 * mounted read-only at env VM_SRC; the keypair comes from env VM_PRIV / VM_PUB —
 * never hardcoded, never committed.
 */

use App\Models\Plugin;
use App\Plugins\PluginManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/vm_bootstrap.php';

afterEach(function () {
    File::deleteDirectory(storage_path('app/plugins/vb-vendor-manager'));
});

it('installs the signed vb-vendor-manager, boots it, serves a route and creates its schema', function () {
    // Guard: the real key material must be present (harness passes it via env).
    expect(getenv('VM_PRIV'))->not->toBeFalse();
    expect(is_dir(vmSrc()))->toBeTrue();

    $user = pluginTestUser('rooftop_owner', ['+vb-vendor-manager.read.rooftop', '+vb-vendor-manager.write.rooftop']);
    $ctx = vmBindTenant($user->id);

    // Signed install → refresh → explicit migrate (core-gap workaround) → boot.
    vmInstallSignedAndBoot($ctx);

    // The installer persisted the first-party trust tier from the signature.
    expect(Plugin::where('slug', 'vb-vendor-manager')->value('trust'))->toBe('signed_first_party');

    // The plugin's server code actually executed (register() ran).
    expect(app(PluginManager::class)->serverCodeRan('vb-vendor-manager'))->toBeTrue();

    // A JSON read route resolves (200), proving src/routes.php loaded — NOT the
    // 404 the vb-native spike caught.
    $this->actingAs($user)
        ->getJson('/dashboard/vendor/api/stats')
        ->assertOk()
        ->assertJsonPath('data.total', fn ($v) => is_int($v));

    // Migrations ran: the vendor schema exists.
    expect(Schema::hasTable('vendor_profiles'))->toBeTrue();
});
