<?php

namespace App\Policies;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Models\MissingItemReport;
use App\Models\User;

class MissingItemReportPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->role === UserRole::Admin && $user->isActive() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function view(User $user, MissingItemReport $report): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function investigate(User $user, MissingItemReport $report): bool
    {
        return $user->isStaff() && $user->isActive();
    }

    public function resolve(User $user, MissingItemReport $report): bool
    {
        return $user->role === UserRole::Admin && $user->isActive();
    }
}
