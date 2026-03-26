<?php

namespace App\Domain\IdentityAccess\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
