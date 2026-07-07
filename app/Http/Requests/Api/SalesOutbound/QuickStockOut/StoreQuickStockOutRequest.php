<?php

namespace App\Http\Requests\Api\SalesOutbound\QuickStockOut;

use App\Http\Requests\Api\StrictFormRequest;

class StoreQuickStockOutRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'qso_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string'],
            'serial_numbers' => ['required', 'array', 'min:1'],
            'serial_numbers.*' => ['required', 'string', 'max:80'],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'customer_id',
            'qso_date',
            'remarks',
            'serial_numbers',
        ];
    }
}
