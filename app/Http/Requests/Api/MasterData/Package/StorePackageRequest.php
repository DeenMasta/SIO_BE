<?php

namespace App\Http\Requests\Api\MasterData\Package;

use Illuminate\Foundation\Http\FormRequest;

class StorePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'package_code' => ['required', 'string', 'max:50', 'unique:packages,package_code'],
            'package_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:ACTIVE,INACTIVE'],
            'products' => ['present', 'array'],
            'products.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
