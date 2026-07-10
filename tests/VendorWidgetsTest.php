<?php

use App\Plugins\PluginManifest;
use App\Support\TenantContext;
use Vctrs\Plugins\VendorManager\Models\VendorProfile;
use Vctrs\Plugins\VendorManager\VendorManagerServiceProvider;

require_once __DIR__.'/vm_bootstrap.php';

function vendorManagerProvider(): VendorManagerServiceProvider
{
    // The plugin is mounted read-only at VM_SRC; its manifest + src live there,
    // NOT alongside the synced tests. Build the provider from the mounted source.
    $dir = vmSrc();
    $manifest = PluginManifest::fromArray(json_decode(file_get_contents($dir.'/manifest.json'), true));

    return new VendorManagerServiceProvider($manifest, $dir);
}

beforeEach(function () {
    $this->uid = pluginTestUser('rooftop_owner')->id;
    vmInstallSignedAndBoot(vmBindTenant($this->uid));
});

it('expiringDocuments + byCategory + recentlyOnboarded widgets compute from tenant data', function () {
    VendorProfile::create(['company_name' => 'A', 'category' => 'oem', 'status' => 'active']);
    VendorProfile::create(['company_name' => 'B', 'category' => 'facility', 'status' => 'active']);
    VendorProfile::create(['company_name' => 'C', 'category' => 'oem', 'status' => 'active']);

    $widgets = vendorManagerProvider()->widgets();

    // byCategory (chart-donut): oem=2, facility=1, ordered by count desc.
    // Labels use CATEGORY_LABELS (oem -> 'OEM', not 'Oem').
    $byCategory = $widgets['vendor.byCategory'][1]();
    expect($byCategory['type'])->toBe('chart-donut');
    $slices = $byCategory['payload']['slices']->all();
    expect($slices)->toHaveCount(2);
    expect($slices[0])->toBe(['label' => 'OEM', 'value' => 2]);
    expect($slices[1])->toBe(['label' => 'Facility', 'value' => 1]);

    // recentlyOnboarded (list): all onboarded vendors, capped at 5, each with
    // label/sublabel/value/href. (created_at is identical within the tx so order is not asserted.)
    $recent = $widgets['vendor.recentlyOnboarded'][1]();
    expect($recent['type'])->toBe('list');
    $rows = $recent['payload']['rows']->all();
    expect($rows)->toHaveCount(3);
    expect(collect($rows)->pluck('label')->sort()->values()->all())->toBe(['A', 'B', 'C']);
    expect($rows[0])->toHaveKeys(['label', 'sublabel', 'value', 'href']);
    expect($rows[0]['href'])->toStartWith('/dashboard/vendor/');

    // sublabel uses CATEGORY_LABELS; value is a relativeTime string.
    $byLabel = collect($rows)->keyBy('label');
    expect($byLabel['A']['sublabel'])->toBe('OEM');
    expect($byLabel['B']['sublabel'])->toBe('Facility');
    expect($byLabel['A']['value'])->toBeString();
    expect($byLabel['A']['value'])->toEndWith('ago');
});

it('every vendor widget payload carries a non-empty top-level label', function () {
    // The dashboard MetricCard reads payload.label unconditionally; a missing
    // label crashes the whole dashboard. Every widget payload must supply one.
    VendorProfile::create(['company_name' => 'A', 'category' => 'oem', 'status' => 'active']);

    $widgets = vendorManagerProvider()->widgets();

    foreach ($widgets as $key => [$perm, $resolver]) {
        $payload = $resolver()['payload'];
        expect(array_key_exists('label', $payload))->toBeTrue("widget {$key} is missing a top-level label");
        expect($payload['label'])->toBeString()->not->toBe('');
    }
});

it('relativeTime helper produces core-compatible buckets', function () {
    $rt = (new \ReflectionMethod(VendorManagerServiceProvider::class, 'relativeTime'));
    $rt->setAccessible(true);
    $call = fn ($date) => $rt->invoke(null, $date);

    expect($call(null))->toBe('');
    expect($call(now()->subSeconds(10)))->toBe('10s ago');
    expect($call(now()->subMinutes(5)))->toBe('5m ago');
    expect($call(now()->subHours(3)))->toBe('3h ago');
    expect($call(now()->subDays(2)))->toBe('2d ago');
    expect($call(now()->subDays(14)))->toBe('2w ago');
    expect($call(now()->subDays(60)))->toBe('2mo ago');
    expect($call(now()->subDays(400)))->toBe('1y ago');
});

it('expiringDocuments widget counts documents inside the 60-day window', function () {
    $vendor = VendorProfile::create(['company_name' => 'A', 'category' => 'oem', 'status' => 'active']);
    \Vctrs\Plugins\VendorManager\Models\VendorDocument::create(['vendor_id' => $vendor->id, 'document_type' => 'coi', 'expires_at' => now()->addDays(30)]);
    \Vctrs\Plugins\VendorManager\Models\VendorDocument::create(['vendor_id' => $vendor->id, 'document_type' => 'coi', 'expires_at' => now()->addDays(90)]);

    $widget = vendorManagerProvider()->widgets()['vendor.expiringDocuments'][1]();

    expect($widget['type'])->toBe('metric');
    expect($widget['payload']['value'])->toBe(1);
});
