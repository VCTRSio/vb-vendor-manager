<?php

declare(strict_types=1);

/**
 * Cross-tenant isolation proof on ALL FIVE vendor_* tables, enforced by Postgres
 * FORCE ROW LEVEL SECURITY as the non-superuser app_user (pgsql_app) — NOT by the
 * Eloquent tenant scope.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * The v1.0.1 artifact shipped these tables with NO RLS. A clean external install
 * is never swept by core's enforce_real_rls migration, so post-#6 (runtime runs as
 * app_user under FORCE RLS, with ALTER DEFAULT PRIVILEGES auto-granting new tables)
 * every vendor_* table was fully readable/writable across ALL tenants — a silent
 * cross-tenant leak. Each genesis migration now reproduces the fail-closed policy +
 * FORCE RLS, applied OUTSIDE the `Schema::hasTable()` adopt-existing guard so an
 * existing host gets the policy too. This test is the regression gate.
 *
 * WHY THE DDL RUNS ON A COMMITTED CLONE CONNECTION
 * ------------------------------------------------
 * The suite runs under DatabaseTransactions, which wraps only the DEFAULT (pgsql,
 * superuser) connection in an open transaction. If the plugin DDL/seed ran there,
 * (a) the rows would be invisible to the SEPARATE app_user session (uncommitted),
 * making the read meaningless, and (b) applyRls()'s ALTER TABLE would hold an ACCESS
 * EXCLUSIVE lock for the whole test and a cross-connection insert would hang.
 *
 * So the DDL + seed run on `pgsql_ddl` — a clone of the pgsql (superuser) config
 * that DatabaseTransactions never wraps, hence autocommits. The rows are committed
 * and visible to app_user, and no DDL lock is held. The superuser bypasses RLS, so
 * it seeds both tenants directly. Cleanup drops the committed tables in finally.
 *
 * A committed bypass-sanity count of 2 per table first proves both rows genuinely
 * exist and WOULD be returned if RLS were not enforcing — so the isolation
 * assertion cannot pass trivially. Reading as BOTH tenants proves isolation in both
 * directions (A cannot see B; B cannot see A).
 */

use App\Support\Rls\TenantGuc;
use App\Support\SystemContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/vm_bootstrap.php';

afterEach(fn () => TenantGuc::clear());

it('enforces cross-tenant isolation on all 5 vendor tables under app_user FORCE RLS', function () {
    $tables = ['vendor_profiles', 'vendor_documents', 'vendor_credentials', 'vendor_onboarding', 'vendor_settings'];

    // A committed, unwrapped owner (superuser) connection for the plugin DDL + seed.
    config(['database.connections.pgsql_ddl' => config('database.connections.pgsql')]);
    DB::purge('pgsql_ddl');

    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    $vendorA = (string) Str::uuid();
    $vendorB = (string) Str::uuid();

    // One row per tenant in every table. Only NOT-NULL columns lacking a DB default
    // are supplied; the FK child rows reference their own tenant's parent profile.
    $rows = fn (string $tid, string $vendorId): array => [
        'vendor_profiles' => [
            'id' => $vendorId, 'tenant_type' => 'rooftop', 'tenant_id' => $tid,
            'company_name' => 'Co '.substr($tid, 0, 8), 'category' => 'oem',
        ],
        'vendor_documents' => [
            'id' => (string) Str::uuid(), 'tenant_type' => 'rooftop', 'tenant_id' => $tid,
            'vendor_id' => $vendorId, 'document_type' => 'coi',
        ],
        'vendor_credentials' => [
            'id' => (string) Str::uuid(), 'tenant_type' => 'rooftop', 'tenant_id' => $tid,
            'vendor_id' => $vendorId, 'credential_type' => 'license', 'credential_name' => 'State License',
        ],
        'vendor_onboarding' => [
            'id' => (string) Str::uuid(), 'tenant_type' => 'rooftop', 'tenant_id' => $tid,
            'vendor_id' => $vendorId, 'step' => 'submitted',
        ],
        'vendor_settings' => [
            'id' => (string) Str::uuid(), 'tenant_type' => 'rooftop', 'tenant_id' => $tid,
        ],
    ];

    $priorDefault = config('database.default');

    try {
        // Create all five tables + fail-closed policy + FORCE RLS + app_user grants
        // on the committed clone.
        config(['database.default' => 'pgsql_ddl']);
        DB::purge('pgsql_ddl');
        foreach ($tables as $t) {
            DB::connection('pgsql_ddl')->statement("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        vmRunMigrations();

        $ddl = DB::connection('pgsql_ddl');
        $seedA = $rows($a, $vendorA);
        $seedB = $rows($b, $vendorB);

        // Parents first (FK), then children — committed so app_user can see them.
        foreach ($tables as $t) {
            $ddl->table($t)->insert([$seedA[$t], $seedB[$t]]);
        }

        // Sanity / anti-false-pass: both rows are really there and visible absent RLS.
        foreach ($tables as $t) {
            $committed = $ddl->table($t)->whereIn('tenant_id', [$a, $b])->pluck('tenant_id')->all();
            expect($committed)->toHaveCount(2, "sanity: {$t} should hold both tenant rows on the bypass connection");
        }

        // Read on the app_user connection. The ONLY surviving filter is Postgres
        // FORCE RLS keyed off the app.tenant_id GUC that runAsTenant sets.
        config(['database.default' => 'pgsql_app']);
        DB::purge('pgsql_app');

        foreach ([['self' => $a, 'other' => $b], ['self' => $b, 'other' => $a]] as $dir) {
            $seen = SystemContext::runAsTenant('rooftop', $dir['self'], function () use ($tables, $a, $b) {
                $out = [];
                foreach ($tables as $t) {
                    $out[$t] = DB::connection('pgsql_app')->table($t)
                        ->whereIn('tenant_id', [$a, $b])
                        ->pluck('tenant_id')->all();
                }

                return $out;
            });

            foreach ($tables as $t) {
                expect($seen[$t])->toHaveCount(1, "{$t}: exactly one row visible under app_user RLS as tenant {$dir['self']}");
                expect($seen[$t])->each->toBe($dir['self']); // …and it is the acting tenant — the other is invisible
            }
        }
    } finally {
        TenantGuc::clear();
        config(['database.default' => 'pgsql_ddl']);
        DB::purge('pgsql_ddl');
        foreach (array_reverse($tables) as $t) {
            DB::connection('pgsql_ddl')->statement("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        config(['database.default' => $priorDefault]);
        DB::purge('pgsql_app');
        DB::purge('pgsql_ddl');
    }
});

it('applies fail-closed RLS on the ADOPT-EXISTING path (v1.0.1-style table lacking RLS)', function () {
    // Reproduces the exact v1.0.1 hole: a host already owns the vendor_* tables WITHOUT
    // any RLS. The genesis `Schema::hasTable()` guard makes createTable() a no-op, so the
    // fix is worthless unless applyRls() runs OUTSIDE that guard. This asserts the adopt
    // re-run installs the policy + FORCE on an incumbent table that started with neither.
    $tables = ['vendor_profiles', 'vendor_documents', 'vendor_credentials', 'vendor_onboarding', 'vendor_settings'];

    config(['database.connections.pgsql_ddl' => config('database.connections.pgsql')]);
    DB::purge('pgsql_ddl');
    $priorDefault = config('database.default');

    try {
        config(['database.default' => 'pgsql_ddl']);
        DB::purge('pgsql_ddl');
        $ddl = DB::connection('pgsql_ddl');
        foreach ($tables as $t) {
            $ddl->statement("DROP TABLE IF EXISTS {$t} CASCADE");
        }

        // 1. Fresh install, then STRIP RLS to forge a v1.0.1-style incumbent: tables
        //    exist, policy dropped, FORCE + ENABLE cleared. This is what any host that
        //    installed v1.0.1 has on disk today.
        vmRunMigrations();
        foreach ($tables as $t) {
            $ddl->statement("ALTER TABLE public.{$t} NO FORCE ROW LEVEL SECURITY");
            $ddl->statement("DROP POLICY IF EXISTS {$t}_tenant_isolation ON public.{$t}");
            $ddl->statement("ALTER TABLE public.{$t} DISABLE ROW LEVEL SECURITY");
        }
        // Confirm the forged incumbent genuinely has NO policy (guards against a false pass).
        foreach ($tables as $t) {
            expect($ddl->table('pg_policies')->where('tablename', $t)->count())
                ->toBe(0, "precondition: {$t} should start RLS-less");
        }

        // 2. Adopt re-run: hasTable() is true so createTable() is skipped, but applyRls()
        //    still runs. This is the line the fix turns on.
        vmRunMigrations();

        // 3. Every table now carries the tenant-isolation policy AND FORCE row security.
        foreach ($tables as $t) {
            expect($ddl->table('pg_policies')->where('tablename', $t)->where('policyname', "{$t}_tenant_isolation")->count())
                ->toBe(1, "{$t}: adopt path must install the tenant-isolation policy");
            $forced = $ddl->selectOne('SELECT relforcerowsecurity FROM pg_class WHERE relname = ?', [$t]);
            expect((bool) $forced->relforcerowsecurity)->toBeTrue("{$t}: adopt path must FORCE row level security");
        }
    } finally {
        config(['database.default' => 'pgsql_ddl']);
        DB::purge('pgsql_ddl');
        foreach (array_reverse($tables) as $t) {
            DB::connection('pgsql_ddl')->statement("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        config(['database.default' => $priorDefault]);
        DB::purge('pgsql_ddl');
    }
});
