<?php

namespace Vctrs\Plugins\VendorManager\Models;

use App\Plugins\Concerns\AdminManageable;
use App\Plugins\Contracts\AdminManageableModel;
use App\Plugins\PluginModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_type
 * @property string $tenant_id
 * @property string $company_name
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string $category
 * @property string $status
 * @property bool $w9_on_file
 * @property Carbon|null $coi_expiry_date
 * @property Carbon|null $contract_start
 * @property Carbon|null $contract_end
 * @property float|null $contract_value_monthly
 * @property float|null $contract_value_annual
 * @property bool $has_active_contract
 * @property array $oem_certifications_json
 * @property string|null $api_key_hash
 * @property string|null $api_key_prefix
 * @property Carbon|null $api_key_issued_at
 * @property Carbon|null $api_key_revoked_at
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $deleted_by_id
 * @property string|null $delete_reason
 * @property Carbon|null $edited_at
 * @property string|null $edited_by_id
 * @property int $edit_count
 */
class VendorProfile extends PluginModel implements AdminManageableModel
{
    use AdminManageable;

    protected $table = 'vendor_profiles';

    /**
     * Display-safe column projection — excludes api_key_hash and api_key_revoked_at
     * (secret material — never returned in API responses).
     *
     * @var array<int, string>
     */
    public const SAFE_FIELDS = [
        'id', 'tenant_type', 'tenant_id',
        'company_name', 'contact_name', 'contact_email', 'contact_phone',
        'category', 'status',
        'w9_on_file',
        'coi_expiry_date',
        'contract_start', 'contract_end',
        'contract_value_monthly', 'contract_value_annual',
        'has_active_contract',
        'oem_certifications_json',
        'api_key_prefix', 'api_key_issued_at',
        'notes',
        'created_at', 'updated_at',
        'deleted_at', 'deleted_by_id', 'delete_reason',
        'edited_at', 'edited_by_id', 'edit_count',
    ];

    protected $casts = [
        'w9_on_file' => 'boolean',
        'has_active_contract' => 'boolean',
        'oem_certifications_json' => 'array',
        'coi_expiry_date' => 'datetime',
        'contract_start' => 'datetime',
        'contract_end' => 'datetime',
        'api_key_issued_at' => 'datetime',
        'api_key_revoked_at' => 'datetime',
    ];

    // Defense-in-depth backstop for the API-key secret: even if a future endpoint
    // forgets the SAFE_FIELDS projection, api_key_hash can never appear in a
    // serialized (toArray/toJson) response. Direct property access still works for
    // privileged auth paths.
    protected $hidden = ['api_key_hash', 'api_key_revoked_at'];

    public function onboardingSteps(): HasMany
    {
        return $this->hasMany(VendorOnboarding::class, 'vendor_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class, 'vendor_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(VendorCredential::class, 'vendor_id');
    }
}
