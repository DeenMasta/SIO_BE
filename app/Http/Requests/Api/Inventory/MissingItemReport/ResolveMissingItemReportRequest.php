<?php

namespace App\Http\Requests\Api\Inventory\MissingItemReport;

use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class ResolveMissingItemReportRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['resolution_type' => ['required', Rule::in(['FOUND', 'STOCK_OUT', 'WRITE_OFF'])], 'stock_out_id' => ['nullable', 'integer', 'exists:stock_out,id'], 'resolution_notes' => ['required', 'string', 'max:5000']];
    }

    protected function allowedFields(): array
    {
        return ['resolution_type', 'stock_out_id', 'resolution_notes'];
    }
}
