<?php

namespace App\Http\Requests\Api\SalesOutbound\SaleOrder;

use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class ExportSaleOrderRequest extends StrictFormRequest
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
            'status' => ['nullable', Rule::enum(SaleOrderStatus::class)],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'q',
            'status',
            'format',
        ];
    }
}
