<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_to_user_management_is_rejected(): void
    {
        $this->getJson('/api/users')->assertUnauthorized();
    }

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

    public function test_admin_can_filter_users_by_role_status_and_search_query(): void
    {
        $admin = User::factory()->admin()->create();

        User::factory()->create([
            'name' => 'Alice Active Staff',
            'email' => 'alice.staff@example.com',
            'role' => 'staff',
            'status' => 'active',
        ]);
        User::factory()->create([
            'name' => 'Bob Inactive Staff',
            'email' => 'bob.staff@example.com',
            'role' => 'staff',
            'status' => 'inactive',
        ]);
        User::factory()->create([
            'name' => 'Charlie Active Admin',
            'email' => 'charlie.admin@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->getJson('/api/users?role=staff&status=active&q=alice')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alice Active Staff')
            ->assertJsonPath('data.0.role', 'staff')
            ->assertJsonPath('data.0.status', 'active');
    }

    public function test_admin_cannot_deactivate_their_own_current_session_account(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        $this->patchJson('/api/users/'.$admin->id.'/deactivate')
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'You cannot deactivate your current session account.');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'status' => 'active',
        ]);
    }

    public function test_user_create_rejects_unknown_fields_and_duplicate_email(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'ops.staff@example.com']);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/users', [
            'name' => 'Ops Staff',
            'email' => 'ops.staff@example.com',
            'password' => 'password123',
            'role' => 'staff',
            'status' => 'active',
            'illegal_field' => 'not-allowed',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonStructure(['errors']);
    }
}
