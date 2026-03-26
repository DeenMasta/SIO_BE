<?php

namespace App\Http\Requests\Api\ReportingAudit\Search;

use App\Http\Requests\Api\StrictFormRequest;

class SearchCatalogRequest extends StrictFormRequest
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
            'query' => ['required', 'string', 'min:1', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['query', 'per_page'];
    }
}
