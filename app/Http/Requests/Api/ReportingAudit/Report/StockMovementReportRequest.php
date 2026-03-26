<?php

namespace App\Http\Requests\Api\ReportingAudit\Report;

use App\Domain\InventoryCore\Enums\MovementType;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StockMovementReportRequest extends StrictFormRequest
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
            'movement_type' => ['nullable', Rule::enum(MovementType::class)],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'stock_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'movement_type',
            'product_id',
            'stock_item_id',
            'date_from',
            'date_to',
            'per_page',
        ];
    }
}
