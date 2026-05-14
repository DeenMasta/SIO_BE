<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\InternalStockMovement;
use App\Models\User;

class InternalStockMovementPolicy
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

    public function view(User $user, InternalStockMovement $movement): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function update(User $user, InternalStockMovement $movement): bool
    {
        return $user->isStaff() && $user->isActive();
    }
}
