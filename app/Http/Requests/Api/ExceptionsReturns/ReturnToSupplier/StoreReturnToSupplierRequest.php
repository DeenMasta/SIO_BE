<?php

namespace App\Http\Requests\Api\ExceptionsReturns\ReturnToSupplier;

use App\Domain\ExceptionsReturns\Enums\ExceptionReason;
use App\Http\Requests\Api\StrictFormRequest;
use App\Models\StockInLine;
use App\Models\StockItem;
use Illuminate\Validation\Rule;

class StoreReturnToSupplierRequest extends StrictFormRequest
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
            'rts_transaction_number' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:return_to_supplier,rts_transaction_number'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'stock_in_id' => ['required', 'integer', 'exists:stock_in,id'],
            'return_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_in_line_id' => ['required', 'integer', 'exists:stock_in_lines,id'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.stock_item_id' => ['nullable', 'integer', 'exists:stock_items,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.reason_for_return' => ['required', 'string', Rule::in(ExceptionReason::values())],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'rts_transaction_number',
            'supplier_id',
            'stock_in_id',
            'return_date',
            'remarks',
            'lines',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();
        $lines = array_values((array) ($payload['lines'] ?? []));
        $resolvedStockInId = (int) ($payload['stock_in_id'] ?? 0);

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $stockInLineId = (int) ($line['stock_in_line_id'] ?? 0);
            if ($stockInLineId === 0 && ! empty($line['stock_item_id'])) {
                $stockInLineId = (int) (StockItem::query()
                    ->whereKey((int) $line['stock_item_id'])
                    ->value('stock_in_line_id') ?? 0);
            }

            if ($stockInLineId > 0) {
                $lines[$index]['stock_in_line_id'] = $stockInLineId;

                if ($resolvedStockInId === 0) {
                    $resolvedStockInId = (int) (StockInLine::query()
                        ->whereKey($stockInLineId)
                        ->value('stock_in_id') ?? 0);
                }
            }
        }

        $this->merge([
            'stock_in_id' => $resolvedStockInId > 0 ? $resolvedStockInId : ($payload['stock_in_id'] ?? null),
            'lines' => $lines,
        ]);
    }
}
