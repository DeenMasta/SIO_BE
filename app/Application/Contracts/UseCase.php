<?php

namespace App\Application\Contracts;

interface UseCase
{
    public function execute(mixed $payload = null): mixed;
}
