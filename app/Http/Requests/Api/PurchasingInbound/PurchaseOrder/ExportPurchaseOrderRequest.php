<?php

namespace App\Http\Requests\Api\PurchasingInbound\PurchaseOrder;

use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class ExportPurchaseOrderRequest extends StrictFormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'q',
            'date_from',
            'date_to',
            'status',
            'format',
        ];
    }
}
