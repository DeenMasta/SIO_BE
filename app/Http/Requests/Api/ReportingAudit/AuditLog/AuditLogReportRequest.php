<?php

namespace App\Http\Requests\Api\ReportingAudit\AuditLog;

use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class AuditLogReportRequest extends StrictFormRequest
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
            'q' => ['nullable', 'string', 'max:120'],
            'module_name' => ['nullable', 'string', 'max:60'],
            'action' => ['nullable', Rule::enum(AuditAction::class)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'format' => ['nullable', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    protected function allowedFields(): array
    {
        return [
            'q',
            'module_name',
            'action',
            'user_id',
            'date_from',
            'date_to',
            'page',
            'per_page',
            'format',
        ];
    }
}
