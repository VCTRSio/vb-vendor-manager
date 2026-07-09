<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_settings')) {
            return;
        }

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

    public function down(): void
    {
        Schema::dropIfExists('vendor_settings');
    }
};
