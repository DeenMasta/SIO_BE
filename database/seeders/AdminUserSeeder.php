<?php

namespace Database\Seeders;

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Domain\IdentityAccess\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's admin user.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
            ]
        );
    }
}
