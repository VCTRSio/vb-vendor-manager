<?php

namespace Vctrs\Plugins\VendorManager\Http\Controllers;

use App\Plugins\Admin\PluginAdminController;
use Vctrs\Plugins\VendorManager\Models\VendorDocument;

class VendorDocumentAdminController extends PluginAdminController
{
    protected function model(): string
    {
        return VendorDocument::class;
    }

    protected function rules(): array
    {
        return [
            'document_name' => ['sometimes', 'nullable', 'string', 'max:200'],
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
