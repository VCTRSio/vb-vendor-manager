<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const T = 'vendor_profiles';

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
        //
        // RLS is applied OUTSIDE this guard (see $this->applyRls() at the end of up()):
        // a clean external install is never swept by core's enforce_real_rls migration,
        // and an ADOPTED table (early-return path) must still receive the fail-closed
        // policy — otherwise, post-#6, app_user reads/writes every tenant's rows.
        if (! Schema::hasTable(self::T)) {
            $this->createTable();
        }

        $this->applyRls();
    }

    private function createTable(): void
    {
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

    /**
     * Fail-closed tenant RLS, reproduced VERBATIM from core's enforce_real_rls. Runs
     * whether the table was just created or adopted (idempotent: ENABLE is a no-op if
     * already enabled, DROP POLICY IF EXISTS + CREATE POLICY re-asserts the predicate,
     * FORCE is idempotent). The predicate casts (tenant_type)::text so it is agnostic
     * to the enum→string divergence. Migrations run as the DB owner (superuser), which
     * can create policies + FORCE; the self-grant covers a host whose app_user role
     * predates ALTER DEFAULT PRIVILEGES.
     */
    private function applyRls(): void
    {
        $t = self::T;
        $predicate = <<<'SQL'
            current_setting('app.bypass_rls', true) = '1'
            OR ( (tenant_type)::text = current_setting('app.tenant_type', true)
                 AND (tenant_id)::text = NULLIF(current_setting('app.tenant_id', true), '') )
        SQL;
        DB::unprepared("ALTER TABLE public.{$t} ENABLE ROW LEVEL SECURITY;");
        DB::unprepared("DROP POLICY IF EXISTS {$t}_tenant_isolation ON public.{$t};");
        DB::unprepared("CREATE POLICY {$t}_tenant_isolation ON public.{$t} USING ({$predicate});");
        DB::unprepared("ALTER TABLE public.{$t} FORCE ROW LEVEL SECURITY;");
        DB::unprepared(<<<SQL
            DO \$\$ BEGIN
              IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'app_user') THEN
                EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON public.{$t} TO app_user';
                EXECUTE 'GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO app_user';
              END IF;
            END \$\$;
        SQL);
    }

    public function down(): void
    {
        $t = self::T;
        DB::unprepared("ALTER TABLE public.{$t} NO FORCE ROW LEVEL SECURITY;");
        DB::unprepared("DROP POLICY IF EXISTS {$t}_tenant_isolation ON public.{$t};");
        Schema::dropIfExists(self::T);
    }
};
