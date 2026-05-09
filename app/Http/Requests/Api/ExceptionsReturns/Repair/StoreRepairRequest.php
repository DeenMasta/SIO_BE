<?php

namespace App\Http\Requests\Api\ExceptionsReturns\Repair;

use App\Domain\ExceptionsReturns\Enums\RepairFlow;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreRepairRequest extends StrictFormRequest
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
            'repair_transaction_number' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:repairs,repair_transaction_number'],
            'repair_date' => ['required', 'date'],
            'stock_item_id' => ['required', 'integer', 'exists:stock_items,id'],
            'repair_flow' => ['required', 'string', Rule::in(RepairFlow::values())],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'issue_description' => ['required', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'repair_transaction_number',
            'repair_date',
            'stock_item_id',
            'repair_flow',
            'customer_id',
            'issue_description',
            'remarks',
        ];
    }
}
