<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\StockOut;
use App\Models\User;

class StockOutPolicy
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

    public function view(User $user, StockOut $stockOut): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return false;
    }
}
