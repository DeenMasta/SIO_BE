<?php

namespace App\Http\Requests\Api\SalesOutbound\QuickStockOut;

use App\Http\Requests\Api\StrictFormRequest;

class ConvertQuickStockOutRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'so_number' => ['nullable', 'string', 'max:50', 'alpha_dash'],
            'so_date' => ['nullable', 'date'],
            'expected_delivery_date' => ['nullable', 'date'],
            'invoice_number' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'so_number',
            'so_date',
            'expected_delivery_date',
            'invoice_number',
            'remarks',
        ];
    }
}
