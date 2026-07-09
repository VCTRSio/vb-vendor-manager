<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_credentials')) {
            return;
        }

        Schema::create('vendor_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('tenant_type');
            $table->uuid('tenant_id');
            $table->uuid('vendor_id');
            $table->text('credential_type');
            $table->text('credential_name');
            $table->text('credential_number')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('expiry_alert_sent_at')->nullable();
            $table->uuid('vault_document_id')->nullable();
            $table->timestampsTz();

            $table->index('expires_at', 'vendor_credentials_expiry_idx');
            $table->index(['tenant_type', 'tenant_id'], 'vendor_credentials_tenant_idx');
            $table->index('vendor_id', 'vendor_credentials_vendor_idx');

            $table->foreign('vendor_id')->references('id')->on('vendor_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_credentials');
    }
};
