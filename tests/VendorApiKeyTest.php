<?php

use App\Support\TenantContext;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;

require_once __DIR__.'/vm_bootstrap.php';

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
});

it('issues a portal api key for an active vendor and returns it once', function () {
    $v = VendorProfile::create(['company_name' => 'K', 'category' => 'oem', 'status' => 'active']);

    $res = $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$v->id}/key")
        ->assertOk();

    $raw = $res->json('data.apiKey');
    expect($raw)->toStartWith('vnd_')
        ->and($res->json('data.keyPrefix'))->toBe(substr($raw, 0, 12));

    $fresh = $v->fresh();
    expect($fresh->api_key_hash)->toBe(hash('sha256', $raw))
        ->and($fresh->api_key_issued_at)->not->toBeNull();
});

it('refuses to issue a key for a non-active vendor', function () {
    $v = VendorProfile::create(['company_name' => 'K', 'category' => 'oem', 'status' => 'pending']);
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->postJson("/dashboard/vendor/api/{$v->id}/key")
        ->assertStatus(422);
});

it('revokes a key', function () {
    $v = VendorProfile::create(['company_name' => 'K', 'category' => 'oem', 'status' => 'active', 'api_key_hash' => str_repeat('a', 64)]);
    $this->actingAs(pluginTestUser('rooftop_owner'))
        ->deleteJson("/dashboard/vendor/api/{$v->id}/key")
        ->assertOk()->assertJsonPath('data.revoked', true);
    expect($v->fresh()->api_key_hash)->toBeNull();
});

it('lists api access without leaking the hash', function () {
    VendorProfile::create(['company_name' => 'K', 'category' => 'oem', 'status' => 'active', 'api_key_prefix' => 'vnd_abc12345', 'api_key_hash' => str_repeat('b', 64)]);
    $res = $this->actingAs(pluginTestUser('rooftop_owner'))
        ->getJson('/dashboard/vendor/api/keys')->assertOk();
    expect($res->json('data.items.0'))->not->toHaveKey('api_key_hash');
    expect($res->json('data.items.0.apiKeyPrefix'))->toBe('vnd_abc12345');
});

it('denies key issue without api.manage permission', function () {
    $v = VendorProfile::create(['company_name' => 'K', 'category' => 'oem', 'status' => 'active']);
    $this->actingAs(pluginTestUser('rooftop_owner', ['-vendor.api.manage.rooftop']))
        ->postJson("/dashboard/vendor/api/{$v->id}/key")->assertForbidden();
});
