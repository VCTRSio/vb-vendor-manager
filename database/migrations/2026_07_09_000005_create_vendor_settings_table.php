<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const T = 'vendor_settings';

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
        Schema::create('vendor_settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('tenant_type');
            $table->uuid('tenant_id');
            $table->text('coi_alert_days_1')->default('60');
            $table->text('coi_alert_days_2')->default('30');
            $table->text('coi_alert_days_3')->default('7');
            $table->text('contract_alert_days')->default('30');
            $table->text('credential_alert_days')->default('30');
            $table->boolean('require_coi')->default(true);
            $table->boolean('require_w9')->default(true);
            $table->timestampsTz();

            $table->index(['tenant_type', 'tenant_id'], 'vendor_settings_tenant_idx');
        });

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS vendor_settings_tenant_unique_idx ON vendor_settings (tenant_type, tenant_id)');
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
