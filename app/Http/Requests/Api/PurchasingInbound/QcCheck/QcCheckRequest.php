<?php

namespace App\Http\Requests\Api\PurchasingInbound\QcCheck;

use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QcCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate handled at route middleware level (can:access-staff)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stock_item_ids'   => ['required', 'array', 'min:1'],
            'stock_item_ids.*' => ['required', 'integer', 'exists:stock_items,id'],
            'result'           => ['required', 'string', Rule::in([
                StockItemQcStatus::Passed->value,
                StockItemQcStatus::Failed->value,
            ])],
            'checked_at'       => ['required', 'date'],
            'remarks'          => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stock_item_ids.required' => 'At least one stock item ID is required.',
            'stock_item_ids.*.exists' => 'One or more stock item IDs do not exist.',
            'result.in'               => 'Result must be PASSED or FAILED.',
            'checked_at.required'     => 'The QC check date and time is required.',
        ];
    }
}
