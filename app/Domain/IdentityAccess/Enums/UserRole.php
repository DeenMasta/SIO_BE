<?php

namespace App\Domain\IdentityAccess\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';
}
