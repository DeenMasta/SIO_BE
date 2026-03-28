<?php

namespace App\Application\IdentityAccess\Users\UseCases;

use App\Application\Contracts\Repositories\UserRepository;
use App\Application\Contracts\UseCase;
use App\Models\User;

class CreateUserUseCase implements UseCase
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function execute(mixed $payload = null): User
    {
        return $this->users->create((array) $payload);
    }
}
