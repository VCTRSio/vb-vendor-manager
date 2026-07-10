<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The full expected column set for vendor_profiles — a drift safety net so that a
 * future edit to the genesis migration that drops/renames a column is caught here.
 *
 * @var array<int, string>
 */
$expectedVendorProfileColumns = [
    'id', 'tenant_type', 'tenant_id',
    'company_name', 'contact_name', 'contact_email', 'contact_phone',
    'category', 'status', 'w9_on_file',
    'coi_expiry_date', 'contract_start', 'contract_end',
    'contract_value_monthly', 'contract_value_annual', 'has_active_contract',
    'oem_certifications_json',
    'api_key_hash', 'api_key_prefix', 'api_key_issued_at', 'api_key_revoked_at',
    'notes',
    'created_at', 'updated_at',
    'deleted_at', 'deleted_by_id', 'delete_reason',
    'edited_at', 'edited_by_id', 'edit_count',
];

it('creates the vendor schema from the plugin migrations', function () use ($expectedVendorProfileColumns) {
    foreach (['vendor_documents','vendor_credentials','vendor_onboarding','vendor_settings','vendor_profiles'] as $t) {
        DB::statement("DROP TABLE IF EXISTS $t CASCADE");
    }

    $dir = getenv('VM_SRC') ? getenv('VM_SRC').'/database/migrations' : __DIR__.'/../database/migrations';
    foreach (glob($dir.'/*.php') as $file) {
        (require $file)->up();
    }

    expect(Schema::hasTable('vendor_profiles'))->toBeTrue();
    expect(Schema::hasColumn('vendor_profiles', 'oem_certifications_json'))->toBeTrue();
    expect(Schema::hasColumn('vendor_profiles', 'contract_value_monthly'))->toBeTrue();
    expect(Schema::hasColumn('vendor_profiles', 'deleted_at'))->toBeTrue();
    expect(Schema::hasTable('vendor_documents'))->toBeTrue();
    expect(Schema::hasColumn('vendor_documents', 'vault_document_id'))->toBeTrue();
    expect(Schema::hasTable('vendor_credentials'))->toBeTrue();
    expect(Schema::hasTable('vendor_onboarding'))->toBeTrue();
    expect(Schema::hasTable('vendor_settings'))->toBeTrue();
    expect(Schema::hasColumn('vendor_settings', 'require_coi'))->toBeTrue();

    // Drift safety net: every expected vendor_profiles column must be present.
    foreach ($expectedVendorProfileColumns as $col) {
        expect(Schema::hasColumn('vendor_profiles', $col))->toBeTrue();
    }
});

it('adopts an existing vendor schema without dropping data on re-run', function () {
    // Proves the EXISTING-HOST adopt path: each genesis migration is guarded by
    // `if (Schema::hasTable('<table>')) return;`, so re-running the migrations against
    // a host that already has the tables is a no-op that PRESERVES existing rows
    // (the platform adopts the incumbent tables rather than dropping/recreating them).
    // This is deterministic regardless of the harness baseline: the first up() loop
    // guarantees the tables exist (creating them only if absent), then we prove the
    // SECOND up() loop leaves a pre-existing sentinel row untouched.
    $dir = getenv('VM_SRC') ? getenv('VM_SRC').'/database/migrations' : __DIR__.'/../database/migrations';

    // 1. Ensure all five tables exist (creates them if the baseline lacks them; a
    //    no-op if they are already present).
    foreach (glob($dir.'/*.php') as $file) {
        (require $file)->up();
    }

    // 2. Insert a sentinel row that must survive the re-run. Only NOT-NULL columns
    //    that lack a DB default are supplied; the rest fall back to their defaults.
    DB::table('vendor_profiles')->insert([
        'id' => (string) Str::uuid(),
        'tenant_type' => 'rooftop',
        'tenant_id' => (string) Str::uuid(),
        'company_name' => 'Sentinel Co',
        'category' => 'oem',
        'status' => 'active',
    ]);

    // A second sentinel in a different table so the adopt proof isn't limited to one
    // table — a stray dropIfExists+create on any child migration would wipe this too.
    DB::table('vendor_settings')->insert([
        'id' => (string) Str::uuid(),
        'tenant_type' => 'rooftop',
        'tenant_id' => (string) Str::uuid(),
    ]);

    // 3. Re-run the migrations. The hasTable genesis guard makes this a no-op.
    $rerun = function () use ($dir) {
        foreach (glob($dir.'/*.php') as $file) {
            (require $file)->up();
        }
    };
    expect($rerun)->not->toThrow(Exception::class);

    // 4. Both sentinel rows still exist (tables were ADOPTED, not dropped/recreated)...
    expect(DB::table('vendor_profiles')->where('company_name', 'Sentinel Co')->exists())->toBeTrue();
    expect(DB::table('vendor_settings')->count())->toBeGreaterThan(0);
    // ...and a representative column is still present (schema left intact).
    expect(Schema::hasColumn('vendor_profiles', 'oem_certifications_json'))->toBeTrue();
});
