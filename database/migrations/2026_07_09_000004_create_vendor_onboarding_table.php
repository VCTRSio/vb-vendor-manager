<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_onboarding')) {
            return;
        }

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

    public function down(): void
    {
        Schema::dropIfExists('vendor_onboarding');
    }
};
