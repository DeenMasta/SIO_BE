<?php

namespace App\Http\Requests\Api\ExceptionsReturns\CustomerReturn;

use App\Http\Requests\Api\StrictFormRequest;

class StoreCustomerExchangeRequest extends StrictFormRequest
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
            'remarks' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.customer_return_line_id' => ['required', 'integer', 'distinct', 'exists:customer_return_lines,id'],
            'lines.*.replacement_product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['remarks', 'lines'];
    }
}
