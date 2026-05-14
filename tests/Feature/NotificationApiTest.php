<?php

namespace Tests\Feature;

use App\Application\Support\StockBalanceUpdater;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
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

        $user->notify(new SystemNotification([
            'event_type' => 'test.event.clear',
            'title' => 'Clear me',
            'message' => 'This notification should be deleted.',
            'level' => 'info',
            'data' => [],
        ]));

        $this->assertSame(2, $user->fresh()->notifications()->count());

        $this->deleteJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 0);

        $this->assertSame(0, $user->fresh()->notifications()->count());
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
            'reorder_level' => 0,
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

    public function test_low_stock_notifications_trigger_and_resolve_on_stock_changes(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create([
            'product_code' => 'NTF-LOW-001',
            'product_name' => 'Low Stock Product',
            'product_type' => 'CONSUMABLE',
            'requires_serial_number' => false,
            'reorder_level' => 5,
            'supplier_id' => $supplier->id,
        ]);

        StockMovement::query()->create([
            'movement_datetime' => now(),
            'product_id' => $product->id,
            'stock_item_id' => null,
            'movement_type' => 'STOCK_IN',
            'reference_table' => 'test_seed',
            'reference_id' => 1,
            'qty_in' => 6,
            'qty_out' => 0,
            'to_status' => 'IN_STOCK',
            'performed_by' => $admin->id,
        ]);

        app(StockBalanceUpdater::class)->recomputeForProducts([$product->id]);

        Sanctum::actingAs($admin, ['admin-access']);

        $this->postJson('/api/stock-outs', [
            'stock_out_number' => 'SOUT-NTF-LOW-0001',
            'idempotency_key' => 'idem-ntf-low-0001',
            'stock_out_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-NTF-LOW-0001',
            'lines' => [[
                'product_id' => $product->id,
                'qty' => 2,
            ]],
        ])->assertCreated();

        $eventTypesAfterStockOut = $staff->fresh()->unreadNotifications()
            ->get()
            ->pluck('data.event_type')
            ->all();

        $this->assertContains('stock-out.posted', $eventTypesAfterStockOut);
        $this->assertContains('inventory.low-stock.triggered', $eventTypesAfterStockOut);

        $staff->notifications()->delete();

        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-NTF-LOW-0001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [[
                'product_id' => $product->id,
                'received_qty' => 2,
            ]],
        ])->assertCreated();

        $notificationsAfterRestock = $staff->fresh()->unreadNotifications()
            ->get();

        $eventTypesAfterRestock = $notificationsAfterRestock
            ->pluck('data.event_type')
            ->all();

        $this->assertContains('stock-in.posted', $eventTypesAfterRestock);
        $this->assertContains('inventory.low-stock.resolved', $eventTypesAfterRestock);
    }
}
