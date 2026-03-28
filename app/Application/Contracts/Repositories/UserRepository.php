<?php

namespace App\Application\Contracts\Repositories;

use App\Domain\IdentityAccess\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface UserRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator;

    public function create(array $data): User;

    public function update(User $user, array $data): User;

    public function setStatus(User $user, UserStatus $status): User;
}
