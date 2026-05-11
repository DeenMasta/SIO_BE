<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_list_and_mark_notifications_as_read(): void
    {
        $user = User::factory()->create();
        $user->notify(new SystemNotification([
            'event_type' => 'test.event',
            'title' => 'Test notification',
            'message' => 'This is a notification test.',
            'level' => 'info',
            'data' => ['foo' => 'bar'],
        ]));

        Sanctum::actingAs($user, ['staff-access']);

        $notificationId = $user->notifications()->value('id');

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.unread_notifications_count', 1);

        $this->getJson('/api/notifications?unread=1')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.title', 'Test notification')
            ->assertJsonPath('data.0.event_type', 'test.event');

        $this->patchJson('/api/notifications/'.$notificationId.'/read')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0)
            ->assertJsonPath('data.read_at', fn (mixed $value) => $value !== null);

        $this->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_stock_in_notifies_other_active_users_only(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $inactive = User::factory()->inactive()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'NTF-STOCK-IN-001',
            'product_type' => 'CONSUMABLE',
        ]);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-NTF-0001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 2,
            ]],
        ])->assertCreated();

        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());
        $this->assertSame(1, $staff->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $inactive->fresh()->notifications()->count());
        $this->assertSame('stock-in.posted', $staff->fresh()->notifications()->first()?->data['event_type']);
    }
}
