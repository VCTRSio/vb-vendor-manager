<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive, dated, idempotent: adds the internal account-rep pointer to vendor_profiles.
 * Nullable uuid, no FK (the rep is a staff-hub employee, a different plugin's table —
 * the authoritative link is the entity_references 'account_rep' edge; this column is the
 * fast read pointer). No RLS change — RLS is table-level and already enforced.
 */
return new class extends Migration
{
    private const T = 'vendor_profiles';

    public function up(): void
    {
        if (! Schema::hasTable(self::T) || Schema::hasColumn(self::T, 'account_rep_employee_id')) {
            return;
        }
        Schema::table(self::T, function (Blueprint $table) {
            $table->uuid('account_rep_employee_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable(self::T) && Schema::hasColumn(self::T, 'account_rep_employee_id')) {
            Schema::table(self::T, function (Blueprint $table) {
                $table->dropColumn('account_rep_employee_id');
            });
        }
    }
};
