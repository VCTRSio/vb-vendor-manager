<?php

namespace Vctrs\Plugins\VendorManager\Models;

use App\Plugins\PluginModel;

/**
 * @property string $id
 * @property string $tenant_type
 * @property string $tenant_id
 * @property string $vendor_id
 * @property string $credential_type
 * @property string $credential_name
 * @property string|null $credential_number
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $expiry_alert_sent_at
 * @property string|null $vault_document_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class VendorCredential extends PluginModel
{
    protected $table = 'vendor_credentials';

    protected $casts = [
        'expires_at' => 'datetime',
        'expiry_alert_sent_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::observe(\App\Audit\AuditObserver::class);
    }
}
