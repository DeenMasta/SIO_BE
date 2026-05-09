<?php

namespace App\Http\Requests\Api\ExceptionsReturns\Repair;

use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class UpdateRepairStatusRequest extends StrictFormRequest
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
            'repair_status' => ['required', Rule::enum(RepairStatus::class)],
            'returned_to_customer_date' => ['nullable', 'date'],
            'return_tracking_number' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'repair_status',
            'returned_to_customer_date',
            'return_tracking_number',
            'remarks',
        ];
    }
}
