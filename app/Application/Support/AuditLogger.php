<?php

namespace App\Application\Support;

use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\AuditLog;

class AuditLogger
{
    public function log(
        ?int $userId,
        string $moduleName,
        string $entityName,
        ?int $entityId,
        AuditAction $action,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        AuditLog::query()->create([
            'user_id' => $userId,
            'module_name' => $moduleName,
            'entity_name' => $entityName,
            'entity_id' => $entityId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
