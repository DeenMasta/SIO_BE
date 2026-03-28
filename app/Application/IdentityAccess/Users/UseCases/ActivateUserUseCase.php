<?php

namespace App\Application\IdentityAccess\Users\UseCases;

use App\Application\Contracts\Repositories\UserRepository;
use App\Application\Contracts\UseCase;
use App\Domain\IdentityAccess\Enums\UserStatus;
use App\Models\User;

class ActivateUserUseCase implements UseCase
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function execute(mixed $payload = null): User
    {
        /** @var User $user */
        $user = $payload;

        return $this->users->setStatus($user, UserStatus::Active);
    }
}
