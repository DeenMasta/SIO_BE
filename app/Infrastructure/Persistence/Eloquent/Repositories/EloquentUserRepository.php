<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\UserRepository;
use App\Domain\IdentityAccess\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentUserRepository implements UserRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = User::query();

        if (($filters['q'] ?? null) !== null) {
            $q = (string) $filters['q'];

            $query->where(function ($builder) use ($q): void {
                $builder
                    ->where('name', 'like', '%'.$q.'%')
                    ->orWhere('email', 'like', '%'.$q.'%');
            });
        }

        if (($filters['role'] ?? null) !== null) {
            $query->where('role', (string) $filters['role']);
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->latest('id')->paginate($perPage);
    }

    public function create(array $data): User
    {
        return User::query()->create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->fill($data);
        $user->save();

        return $user->refresh();
    }

    public function setStatus(User $user, UserStatus $status): User
    {
        $user->status = $status;
        $user->save();

        return $user->refresh();
    }
}
