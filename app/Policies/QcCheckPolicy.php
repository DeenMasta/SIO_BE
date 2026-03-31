<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\QcCheck;
use App\Models\User;

class QcCheckPolicy
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

    public function view(User $user, QcCheck $qcCheck): bool
    {
        return $user->isActive();
    }

    public function create(User $user): bool
    {
        return $user->isStaff() && $user->isActive();
    }
}
