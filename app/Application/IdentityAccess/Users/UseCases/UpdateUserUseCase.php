<?php

namespace App\Application\IdentityAccess\Users\UseCases;

use App\Application\Contracts\Repositories\UserRepository;
use App\Application\Contracts\UseCase;
use App\Models\User;

class UpdateUserUseCase implements UseCase
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function execute(mixed $payload = null): User
    {
        $data = (array) $payload;

        /** @var User $user */
        $user = $data['user'];

        unset($data['user']);

        return $this->users->update($user, $data);
    }
}
