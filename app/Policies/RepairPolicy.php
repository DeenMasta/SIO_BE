<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\Repair;
use App\Models\User;

class RepairPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role === UserRole::Admin && $user->isActive()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function view(User $user, Repair $repair): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Repair $repair): bool
    {
        return false;
    }
}
