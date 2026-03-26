<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_token(): void
    {
        $user = User::factory()->create([
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['token_type', 'access_token', 'user'],
                'meta',
            ]);
    }

    public function test_login_with_invalid_credentials_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'not-the-right-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('status', 'error');
    }

    public function test_authenticated_user_can_view_own_profile(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user, ['staff-access']);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_inactive_user_is_blocked_from_protected_endpoint(): void
    {
        $user = User::factory()->inactive()->create();

        Sanctum::actingAs($user, ['staff-access']);

        $this->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Your account is inactive.');
    }

    public function test_staff_user_cannot_access_admin_endpoint(): void
    {
        $staff = User::factory()->staff()->create();

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/admin/ping')
            ->assertForbidden();
    }

    public function test_admin_user_can_access_admin_endpoint(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        $this->getJson('/api/admin/ping')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }
}
