<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\Stocktake;
use App\Models\User;

class StocktakePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->role === UserRole::Admin && $user->isActive() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function view(User $user, Stocktake $stocktake): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function submit(User $user, Stocktake $stocktake): bool
    {
        return $user->isStaff() && $user->isActive();
    }
}
