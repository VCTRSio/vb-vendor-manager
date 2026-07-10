<?php

namespace Vctrs\Plugins\VbVendorManager\Http\Controllers;

use App\Plugins\Admin\PluginAdminController;
use Vctrs\Plugins\VbVendorManager\Models\VendorProfile;

class VendorAdminController extends PluginAdminController
{
    protected function model(): string
    {
        return VendorProfile::class;
    }

    protected function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'max:50'],
            'category' => ['sometimes', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    protected function permission(): string
    {
        return 'vendor.admin.manage.rooftop';
    }

    protected function procedurePrefix(): string
    {
        return 'vendor';
    }
}
