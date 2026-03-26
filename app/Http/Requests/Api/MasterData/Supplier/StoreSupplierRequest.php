<?php

namespace App\Http\Requests\Api\MasterData\Supplier;

use App\Domain\MasterData\Enums\RecordStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:suppliers,supplier_code'],
            'supplier_name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150', 'unique:suppliers,email'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'supplier_code',
            'supplier_name',
            'contact_person',
            'phone',
            'email',
            'address',
            'status',
            'remarks',
        ];
    }
}
