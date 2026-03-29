<?php

namespace App\Http\Requests\Api\QcOutbound\StockOut;

use App\Http\Requests\Api\StrictFormRequest;

class StockOutSerialOptionsRequest extends StrictFormRequest
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
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'query' => ['nullable', 'string', 'min:1', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['product_id', 'query', 'per_page'];
    }
}
