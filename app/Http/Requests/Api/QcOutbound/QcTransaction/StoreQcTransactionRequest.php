<?php

namespace App\Http\Requests\Api\QcOutbound\QcTransaction;

use App\Domain\QcOutbound\Enums\QcResult;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class StoreQcTransactionRequest extends StrictFormRequest
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
            'qc_reference_number' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:qc_transactions,qc_reference_number'],
            'stock_in_id' => ['required', 'integer', 'exists:stock_in,id'],
            'qc_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_in_line_id' => ['required', 'integer', 'exists:stock_in_lines,id'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qc_result' => ['required', Rule::enum(QcResult::class)],
            'lines.*.qty_pass' => ['nullable', 'integer', 'min:0'],
            'lines.*.qty_fail' => ['nullable', 'integer', 'min:0'],
            'lines.*.stock_item_ids' => ['nullable', 'array'],
            'lines.*.stock_item_ids.*' => ['integer', 'distinct', 'exists:stock_items,id'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'qc_reference_number',
            'stock_in_id',
            'qc_date',
            'remarks',
            'lines',
        ];
    }
}
