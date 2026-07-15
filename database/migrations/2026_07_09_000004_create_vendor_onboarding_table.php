<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const T = 'vendor_onboarding';

    public function up(): void
    {
        // RLS is applied OUTSIDE the adopt-existing guard (see $this->applyRls()): an
        // adopted table must still receive the fail-closed policy, else post-#6 app_user
        // reads/writes every tenant's rows. See vendor_profiles genesis for the full note.
        if (! Schema::hasTable(self::T)) {
            $this->createTable();
        }

        $this->applyRls();
    }

    private function createTable(): void
    {
        Schema::create('vendor_onboarding', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('tenant_type');
            $table->uuid('tenant_id');
            $table->uuid('vendor_id');
            $table->text('step');
            $table->text('notes')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_type', 'tenant_id'], 'vendor_onboarding_tenant_idx');
            $table->index('vendor_id', 'vendor_onboarding_vendor_idx');

            $table->foreign('vendor_id')->references('id')->on('vendor_profiles')->cascadeOnDelete();
        });
    }

    /**
     * Fail-closed tenant RLS, reproduced VERBATIM from core's enforce_real_rls; runs
     * whether the table was just created or adopted (idempotent). See vendor_profiles
     * genesis for the full rationale.
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
