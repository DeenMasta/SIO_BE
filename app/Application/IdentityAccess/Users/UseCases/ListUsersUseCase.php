<?php

namespace App\Application\IdentityAccess\Users\UseCases;

use App\Application\Contracts\Repositories\UserRepository;
use App\Application\Contracts\UseCase;
use App\Domain\IdentityAccess\Enums\UserRole;
use App\Domain\IdentityAccess\Enums\UserStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListUsersUseCase implements UseCase
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function execute(mixed $payload = null): LengthAwarePaginator
    {
        $data = is_array($payload) ? $payload : [];
        $perPage = (int) ($data['per_page'] ?? 15);

        $filters = [];

        $search = trim((string) ($data['q'] ?? ''));
        if ($search !== '') {
            $filters['q'] = $search;
        }

        $role = UserRole::tryFrom((string) ($data['role'] ?? ''));
        if ($role !== null) {
            $filters['role'] = $role->value;
        }

        $status = UserStatus::tryFrom((string) ($data['status'] ?? ''));
        if ($status !== null) {
            $filters['status'] = $status->value;
        }

        return $this->users->paginate($perPage > 0 ? $perPage : 15, $filters);
    }
}
