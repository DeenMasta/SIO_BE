<?php

namespace App\Http\Requests\Api\QcOutbound\StockOut;

use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class ExportStockOutRequest extends StrictFormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'q',
            'format',
        ];
    }
}
