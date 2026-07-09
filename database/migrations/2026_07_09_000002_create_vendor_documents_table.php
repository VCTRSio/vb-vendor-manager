<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_documents')) {
            return;
        }

        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('tenant_type');
            $table->uuid('tenant_id');
            $table->uuid('vendor_id');
            $table->text('document_type');
            $table->text('document_name')->nullable();
            $table->uuid('vault_document_id')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->text('last_alert_days_sent')->nullable();
            $table->timestampTz('expiry_alert_sent_at')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
            $table->uuid('deleted_by_id')->nullable();
            $table->text('delete_reason')->nullable();

            $table->index(['tenant_type', 'tenant_id'], 'vendor_documents_tenant_idx');
            $table->index('expires_at', 'vendor_documents_expiry_idx');
            $table->index('document_type', 'vendor_documents_type_idx');
            $table->index('vendor_id', 'vendor_documents_vendor_idx');

            $table->foreign('vendor_id')->references('id')->on('vendor_profiles')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX IF NOT EXISTS idx_vendor_documents_active ON vendor_documents (tenant_type, tenant_id) WHERE (deleted_at IS NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_documents');
    }
};
