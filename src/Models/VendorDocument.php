<?php

namespace Vctrs\Plugins\VendorManager\Models;

use App\Plugins\Concerns\AdminManageable;
use App\Plugins\Contracts\AdminManageableModel;
use App\Plugins\PluginModel;

/**
 * @property string $id
 * @property string $tenant_type
 * @property string $tenant_id
 * @property string $vendor_id
 * @property string $document_type
 * @property string|null $document_name
 * @property string|null $vault_document_id
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property string|null $last_alert_days_sent
 * @property \Illuminate\Support\Carbon|null $expiry_alert_sent_at
 * @property string|null $uploaded_by
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string|null $deleted_by_id
 * @property string|null $delete_reason
 * @property \Illuminate\Support\Carbon|null $edited_at
 * @property string|null $edited_by_id
 * @property int $edit_count
 */
class VendorDocument extends PluginModel implements AdminManageableModel
{
    use AdminManageable;

    protected $table = 'vendor_documents';

    protected $casts = [
        'expires_at' => 'datetime',
        'expiry_alert_sent_at' => 'datetime',
    ];
}
