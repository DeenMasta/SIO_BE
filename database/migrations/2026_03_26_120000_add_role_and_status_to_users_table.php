<?php

use App\Domain\IdentityAccess\Enums\UserRole;
use App\Domain\IdentityAccess\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', array_map(static fn (UserRole $role) => $role->value, UserRole::cases()))
                ->default(UserRole::Staff->value)
                ->after('password')
                ->index();

            $table->enum('status', array_map(static fn (UserStatus $status) => $status->value, UserStatus::cases()))
                ->default(UserStatus::Active->value)
                ->after('role')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropColumn(['role', 'status']);
        });
    }
};
