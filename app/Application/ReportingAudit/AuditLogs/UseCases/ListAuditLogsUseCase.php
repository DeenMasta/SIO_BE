<?php

namespace App\Application\ReportingAudit\AuditLogs\UseCases;

use App\Application\Contracts\Repositories\AuditLogRepository;
use App\Application\Contracts\UseCase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ListAuditLogsUseCase implements UseCase
{
    public function __construct(private readonly AuditLogRepository $auditLogs)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $filters = (array) $payload;
        $perPage = (int) ($filters['per_page'] ?? 15);

        return $this->auditLogs->paginateWithFilters($filters, $perPage > 0 ? $perPage : 15);
    }

    public function exportRows(array $filters, int $limit = 5000): Collection
    {
        return $this->auditLogs->listWithFilters($filters, $limit);
    }
}
