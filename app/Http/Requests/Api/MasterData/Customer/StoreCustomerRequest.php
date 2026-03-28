<?php

namespace App\Http\Requests\Api\MasterData\Customer;

use App\Domain\MasterData\Enums\RecordStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends StrictFormRequest
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
            'customer_name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150', 'unique:customers,email'],
            'address' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::enum(RecordStatus::class)],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'customer_name',
            'contact_person',
            'phone',
            'email',
            'address',
            'status',
            'remarks',
        ];
    }
}
