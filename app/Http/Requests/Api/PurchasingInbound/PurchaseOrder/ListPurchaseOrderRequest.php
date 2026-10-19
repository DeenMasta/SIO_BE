<?php

namespace App\Http\Requests\Api\PurchasingInbound\PurchaseOrder;

use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class ListPurchaseOrderRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['q', 'date_from', 'date_to', 'status', 'per_page', 'page'];
    }
}
