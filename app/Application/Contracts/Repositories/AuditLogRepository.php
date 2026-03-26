<?php

namespace App\Application\Contracts\Repositories;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface AuditLogRepository
{
    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator;

    public function listWithFilters(array $filters, int $limit = 5000): Collection;

    public function create(array $data): AuditLog;
}
