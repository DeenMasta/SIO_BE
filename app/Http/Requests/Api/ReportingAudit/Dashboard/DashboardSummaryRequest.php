<?php

namespace App\Http\Requests\Api\ReportingAudit\Dashboard;

use App\Http\Requests\Api\StrictFormRequest;

class DashboardSummaryRequest extends StrictFormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['date_from', 'date_to'];
    }
}
