<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportingAuditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_dashboard_and_movement_report(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);
        $stockInResponse = $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-RPT-100001',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 2,
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id,
            'qty_received_pending_qc' => 2,
            'qty_in_stock' => 0,
        ]);

        $stockInId = (int) $stockInResponse->json('data.id');
        $stockInLineId = (int) $stockInResponse->json('data.lines.0.id');

        $this->postJson('/api/qc-transactions', [
            'qc_reference_number' => 'QC-RPT-100001',
            'stock_in_id' => $stockInId,
            'qc_date' => now()->toDateString(),
            'lines' => [
                [
                    'stock_in_line_id' => $stockInLineId,
                    'product_id' => $product->id,
                    'qc_result' => 'PASS',
                    'qty_pass' => 2,
                    'qty_fail' => 0,
                ],
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('stock_balances', [
            'product_id' => $product->id,
            'qty_received_pending_qc' => 0,
            'qty_in_stock' => 2,
        ]);

        Sanctum::actingAs($staff, ['staff-access']);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.items_in_stock', 2)
            ->assertJsonStructure(['data' => ['total_products', 'movements_today']]);

        $this->getJson('/api/reports/stock-movements?movement_type=STOCK_IN')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_staff_cannot_view_audit_logs_but_admin_can(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);
        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-RPT-100002',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 1,
                ],
            ],
        ])->assertCreated();

        Sanctum::actingAs($staff, ['staff-access']);
        $this->getJson('/api/audit-logs')->assertForbidden();

        Sanctum::actingAs($admin, ['admin-access']);
        $this->getJson('/api/audit-logs?module_name=PurchasingInbound')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    public function test_audit_log_response_redacts_sensitive_fields(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['admin-access']);

        AuditLog::query()->create([
            'user_id' => $admin->id,
            'module_name' => 'IdentityAccess',
            'entity_name' => 'User',
            'entity_id' => $admin->id,
            'action' => 'UPDATE',
            'old_values' => [
                'password' => 'plain-text-password',
                'profile' => [
                    'api_token' => 'sensitive-token',
                    'display_name' => 'Admin User',
                ],
            ],
            'new_values' => [
                'access_token' => 'new-token',
                'status' => 'ACTIVE',
            ],
        ]);

        $this->getJson('/api/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.old_values.password', '[REDACTED]')
            ->assertJsonPath('data.0.old_values.profile.api_token', '[REDACTED]')
            ->assertJsonPath('data.0.new_values.access_token', '[REDACTED]')
            ->assertJsonPath('data.0.new_values.status', 'ACTIVE');
    }

    public function test_export_endpoints_respect_access_and_return_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        $supplier = Supplier::factory()->create();
        $product = Product::factory()->create(['product_type' => 'CONSUMABLE']);

        Sanctum::actingAs($admin, ['admin-access']);
        $this->postJson('/api/stock-ins', [
            'stock_in_number' => 'SIN-RPT-100003',
            'stock_in_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'lines' => [
                [
                    'product_id' => $product->id,
                    'received_qty' => 1,
                ],
            ],
        ])->assertCreated();

        Sanctum::actingAs($staff, ['staff-access']);
        $this->get('/api/audit-logs/export')->assertForbidden();

        $movementExportForStaff = $this->get('/api/reports/stock-movements/export');
        $movementExportForStaff->assertOk();
        $movementExportForStaff->assertHeader('content-type', 'text/csv; charset=UTF-8');

        Sanctum::actingAs($admin, ['admin-access']);

        $movementExport = $this->get('/api/reports/stock-movements/export');
        $movementExport->assertOk();
        $movementExport->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('movement_type', $movementExport->streamedContent());

        $auditExport = $this->get('/api/audit-logs/export');
        $auditExport->assertOk();
        $auditExport->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('module_name', $auditExport->streamedContent());

        $this->assertNotNull(StockBalance::query()->where('product_id', $product->id)->first());
    }
}
