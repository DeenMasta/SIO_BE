<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($this->canManageProducts($user)) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $this->canViewProducts($user);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->canViewProducts($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Product $product): bool
    {
        return false;
    }

    public function delete(User $user, Product $product): bool
    {
        return false;
    }

    private function canManageProducts(User $user): bool
    {
        return $user->role === UserRole::Admin && $user->isActive();
    }

    private function canViewProducts(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Staff], true) && $user->isActive();
    }
}
