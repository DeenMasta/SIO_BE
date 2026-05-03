<?php

namespace App\Http\Requests\Api\ExceptionsReturns\ReturnToSupplier;

use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class ExportReturnToSupplierRequest extends StrictFormRequest
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
            'status' => ['nullable', Rule::enum(ExceptionTransactionStatus::class)],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    protected function allowedFields(): array
    {
        return ['q', 'date_from', 'date_to', 'status', 'format'];
    }
}
