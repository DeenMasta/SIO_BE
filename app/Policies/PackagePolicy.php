<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\Package;
use App\Models\User;

class PackagePolicy
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

    public function view(User $user, Package $package): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Package $package): bool
    {
        return false;
    }

    public function delete(User $user, Package $package): bool
    {
        return false;
    }
}
