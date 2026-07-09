<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the vendor schema from the plugin migrations', function () {
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
});
