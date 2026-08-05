<?php

namespace App\Http\Requests\Api\Inventory\MissingItemReport;

use App\Http\Requests\Api\StrictFormRequest;

class UpdateInvestigationRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['investigation_notes' => ['required', 'string', 'max:5000']];
    }

    protected function allowedFields(): array
    {
        return ['investigation_notes'];
    }
}
