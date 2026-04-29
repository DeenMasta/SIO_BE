<?php

namespace App\Http\Requests\Api\PurchasingInbound\StockIn;

use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PostQcDocumentRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_number' => ['nullable', 'string', 'max:50', 'alpha_dash', 'unique:quality_checks,document_number'],
            'stock_in_id'     => ['required', 'integer', 'exists:stock_in,id', 'unique:quality_checks,stock_in_id'],
            'date'            => ['required', 'date'],
            'remarks'         => ['nullable', 'string', 'max:2000'],
            'lines'           => ['required', 'array', 'min:1'],
            'lines.*.stock_item_id'          => ['required', 'integer', 'exists:stock_items,id'],
            'lines.*.result'                 => ['required', 'string', Rule::in([
                StockItemQcStatus::Passed->value,
                StockItemQcStatus::Partial->value,
                StockItemQcStatus::Failed->value,
            ])],
            'lines.*.checked_conditions'     => ['nullable', 'array'],
            'lines.*.checked_conditions.*'   => ['string', 'max:100'],
            'lines.*.checked_accessories'    => ['nullable', 'array'],
            'lines.*.checked_accessories.*'  => ['string', 'max:100'],
            'lines.*.remarks'                => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'document_number',
            'stock_in_id',
            'date',
            'remarks',
            'lines',
        ];
    }
}
