<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Genesis idempotency guard (adopt-existing): when the host already owns this
        // table (e.g. it previously ran the in-monorepo vendor-manager, or the plugin is
        // being reinstalled) we ADOPT the incumbent table and its data instead of
        // recreating it. This guard is for FIRST-INSTALL idempotency ONLY.
        //
        // UPGRADE POLICY: never mutate this genesis file to evolve the schema — a host
        // that already has the table would skip the change entirely. Future schema
        // changes ship as NEW, additive, dated migrations (each independently idempotent,
        // e.g. `if (Schema::hasColumn(...)) return;`), so both fresh and existing hosts
        // converge. Proven by tests/VendorMigrationsTest.php (fresh-create + adopt paths).
        if (Schema::hasTable('vendor_profiles')) {
            return;
        }

        Schema::create('vendor_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('tenant_type');
            $table->uuid('tenant_id');
            $table->text('company_name');
            $table->text('contact_name')->nullable();
            $table->text('contact_email')->nullable();
            $table->text('contact_phone')->nullable();
            $table->text('category');
            $table->text('status')->default('pending');
            $table->boolean('w9_on_file')->default(false);
            $table->timestampTz('coi_expiry_date')->nullable();
            $table->timestampTz('contract_start')->nullable();
            $table->timestampTz('contract_end')->nullable();
            $table->decimal('contract_value_monthly', 12, 2)->nullable();
            $table->decimal('contract_value_annual', 12, 2)->nullable();
            $table->boolean('has_active_contract')->default(false);
            $table->text('oem_certifications_json')->default('[]');
            $table->text('api_key_hash')->nullable();
            $table->text('api_key_prefix')->nullable();
            $table->timestampTz('api_key_issued_at')->nullable();
            $table->timestampTz('api_key_revoked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->uuid('deleted_by_id')->nullable();
            $table->text('delete_reason')->nullable();
            $table->timestampTz('edited_at')->nullable();
            $table->uuid('edited_by_id')->nullable();
            $table->integer('edit_count')->default(0);

            $table->index(['tenant_type', 'tenant_id'], 'vendor_profiles_tenant_idx');
            $table->index('category', 'vendor_profiles_category_idx');
            $table->index('status', 'vendor_profiles_status_idx');
            $table->index(['tenant_type', 'tenant_id', 'category'], 'vendor_profiles_tenant_category_idx');
            $table->index(['tenant_type', 'tenant_id', 'status'], 'vendor_profiles_tenant_status_idx');
            $table->index('api_key_hash', 'vendor_profiles_api_key_hash_idx');
        });

        // Partial indexes — Blueprint cannot express WHERE clauses.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_vendor_profiles_active ON vendor_profiles (tenant_type, tenant_id) WHERE (deleted_at IS NULL)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS vendor_profiles_api_key_hash_unique ON vendor_profiles (api_key_hash) WHERE (api_key_hash IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_profiles');
    }
};
