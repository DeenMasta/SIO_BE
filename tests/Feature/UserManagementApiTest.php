<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_create_update_and_toggle_user_status(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->staff()->inactive()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        $this->getJson('/api/users')
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $created = $this->postJson('/api/users', [
            'name' => 'Ops Staff',
            'email' => 'ops.staff@example.com',
            'password' => 'password123',
            'role' => 'staff',
            'status' => 'active',
        ])->assertCreated();

        $createdId = (int) $created->json('data.id');

        $this->patchJson('/api/users/'.$createdId, [
            'role' => 'admin',
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->patchJson('/api/users/'.$target->id.'/activate')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->patchJson('/api/users/'.$target->id.'/deactivate')
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('users', [
            'id' => $createdId,
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => 'inactive',
        ]);
    }

    public function test_staff_is_forbidden_from_admin_user_management_endpoints(): void
    {
        $staff = User::factory()->staff()->create();
        $target = User::factory()->staff()->create();

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/users')->assertForbidden();

        $this->postJson('/api/users', [
            'name' => 'Blocked User',
            'email' => 'blocked.user@example.com',
            'password' => 'password123',
            'role' => 'staff',
            'status' => 'active',
        ])->assertForbidden();

        $this->patchJson('/api/users/'.$target->id, [
            'status' => 'inactive',
        ])->assertForbidden();

        $this->patchJson('/api/users/'.$target->id.'/deactivate')->assertForbidden();
    }
}
