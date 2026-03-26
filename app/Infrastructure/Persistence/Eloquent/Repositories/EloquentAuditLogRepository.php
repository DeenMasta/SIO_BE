<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\AuditLogRepository;
use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EloquentAuditLogRepository implements AuditLogRepository
{
    public function paginateWithFilters(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->buildQuery($filters)->paginate($perPage);
    }

    public function listWithFilters(array $filters, int $limit = 5000): Collection
    {
        return $this->buildQuery($filters)
            ->limit($limit > 0 ? $limit : 5000)
            ->get();
    }

    private function buildQuery(array $filters): Builder
    {
        $query = AuditLog::query()->latest('id');

        if (! empty($filters['module_name'])) {
            $query->where('module_name', (string) $filters['module_name']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        return $query;
    }

    public function create(array $data): AuditLog
    {
        return AuditLog::query()->create($data);
    }
}
