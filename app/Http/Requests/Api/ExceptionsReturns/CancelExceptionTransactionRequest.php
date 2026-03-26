<?php

namespace App\Http\Requests\Api\ExceptionsReturns;

use App\Http\Requests\Api\StrictFormRequest;

class CancelExceptionTransactionRequest extends StrictFormRequest
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
        ];
    }

    protected function allowedFields(): array
    {
        return ['remarks'];
    }
}
