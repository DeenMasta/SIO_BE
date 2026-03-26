<?php

namespace App\Http\Requests\Api\MasterData\Supplier;

use App\Domain\MasterData\Enums\RecordStatus;
use App\Http\Requests\Api\StrictFormRequest;
use App\Models\Supplier;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends StrictFormRequest
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
        /** @var Supplier $supplier */
        $supplier = $this->route('supplier');

        return [
            'supplier_code' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('suppliers', 'supplier_code')->ignore($supplier->id)],
            'supplier_name' => ['sometimes', 'required', 'string', 'max:150'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150', Rule::unique('suppliers', 'email')->ignore($supplier->id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(RecordStatus::class)],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
