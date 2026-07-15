<?php

namespace App\Http\Requests\Api\SalesOutbound\QuickStockOut;

use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Validator;

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
            'serial_numbers' => ['nullable', 'array', 'min:1'],
            'serial_numbers.*' => ['required', 'string', 'max:80'],
            'lines' => ['nullable', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.remarks' => ['nullable', 'string'],
            'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['required', 'string', 'max:80'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $serialNumbers = array_values(array_filter(
                (array) $this->input('serial_numbers', []),
                static fn (mixed $value): bool => trim((string) $value) !== '',
            ));
            $lines = array_values(array_filter(
                (array) $this->input('lines', []),
                static fn (mixed $line): bool => is_array($line),
            ));

            if ($serialNumbers === [] && $lines === []) {
                $validator->errors()->add('lines', 'At least one quick stock out line or one serial number is required.');
            }
        });
    }

    protected function allowedFields(): array
    {
        return [
            'customer_id',
            'qso_date',
            'remarks',
            'serial_numbers',
            'lines',
        ];
    }
}
