<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\SaleOrder;
use App\Models\User;

class SaleOrderPolicy
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

    public function view(User $user, SaleOrder $saleOrder): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function update(User $user, SaleOrder $saleOrder): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function delete(User $user, SaleOrder $saleOrder): bool
    {
        return $user->isStaff() && $user->isActive();
    }
}
