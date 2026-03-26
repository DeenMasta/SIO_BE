<?php

namespace App\Http\Requests\Api\MasterData\Customer;

use App\Domain\MasterData\Enums\RecordStatus;
use App\Http\Requests\Api\StrictFormRequest;
use App\Models\Customer;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends StrictFormRequest
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
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'customer_code' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('customers', 'customer_code')->ignore($customer->id)],
            'customer_name' => ['sometimes', 'required', 'string', 'max:150'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150', Rule::unique('customers', 'email')->ignore($customer->id)],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(RecordStatus::class)],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'customer_code',
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
