<?php

namespace App\Http\Requests\Api\ExceptionsReturns\Repair;

use App\Domain\ExceptionsReturns\Enums\RepairFlow;
use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use App\Http\Requests\Api\StrictFormRequest;
use Illuminate\Validation\Rule;

class ExportRepairRequest extends StrictFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(RepairStatus::class)],
            'repair_flow' => ['nullable', 'string', Rule::in(RepairFlow::values())],
            'format' => ['nullable', 'string', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }

    protected function allowedFields(): array
    {
        return ['q', 'status', 'repair_flow', 'format'];
    }
}
