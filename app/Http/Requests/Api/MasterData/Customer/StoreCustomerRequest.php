<?php

namespace App\Http\Requests\Api\MasterData\Customer;

use App\Domain\MasterData\Enums\RecordStatus;
use App\Http\Requests\Api\StrictFormRequest;
use App\Models\Customer;
use Illuminate\Validation\Rule;
use Closure;

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
            'customer_name' => [
                'required',
                'string',
                'max:150',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $normalizedName = mb_strtolower(trim((string) $value));

                    if ($normalizedName === '') {
                        return;
                    }

                    $exists = Customer::query()
                        ->whereRaw('LOWER(TRIM(customer_name)) = ?', [$normalizedName])
                        ->exists();

                    if ($exists) {
                        $fail('The customer name has already been taken.');
                    }
                },
            ],
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
