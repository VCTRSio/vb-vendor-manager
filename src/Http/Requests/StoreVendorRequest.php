<?php

namespace Vctrs\Plugins\VendorManager\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVendorRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:200',
            'contact_name' => 'nullable|string|max:200',
            'contact_email' => 'nullable|email|max:200',
            'contact_phone' => 'nullable|string|max:50',
            'category' => 'required|in:oem,aftermarket,marketing,facility,technology',
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
