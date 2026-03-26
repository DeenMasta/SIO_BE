<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SprintCAuditExportActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_movement_export_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export stock movements
        $response = $this->getJson('/api/reports/stock-movements/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Verify audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'module_name' => 'ReportingAudit',
            'entity_name' => 'StockMovementReport',
            'entity_id' => 0,
            'action' => 'EXPORT',
        ]);

        // Verify audit log has filter metadata
        $auditLog = AuditLog::where('module_name', 'ReportingAudit')
            ->where('entity_name', 'StockMovementReport')
            ->where('action', 'EXPORT')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertIsArray($auditLog->new_values);
        $this->assertArrayHasKey('filename', $auditLog->new_values);
    }

    public function test_stock_movement_export_with_filters_logs_filters(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export with filters
        $this->getJson('/api/reports/stock-movements/export?per_page=10&date_from=2026-01-01')
            ->assertOk();

        // Verify audit log contains filters
        $auditLog = AuditLog::where('module_name', 'ReportingAudit')
            ->where('entity_name', 'StockMovementReport')
            ->where('action', 'EXPORT')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertIsArray($auditLog->new_values);
        $this->assertArrayHasKey('filters', $auditLog->new_values);
        $this->assertArrayHasKey('filename', $auditLog->new_values);
    }

    public function test_audit_log_export_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        // Export audit logs
        $response = $this->getJson('/api/audit-logs/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Verify audit log was created for the export
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'module_name' => 'ReportingAudit',
            'entity_name' => 'AuditLogReport',
            'entity_id' => 0,
            'action' => 'EXPORT',
        ]);
    }

    public function test_multiple_exports_create_multiple_audit_logs(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export stock movements
        $this->getJson('/api/reports/stock-movements/export')->assertOk();

        // Export audit logs
        $this->getJson('/api/audit-logs/export')->assertOk();

        // Verify both exports were logged
        $exportLogs = AuditLog::where('user_id', $admin->id)
            ->where('action', 'EXPORT')
            ->get();

        $this->assertCount(2, $exportLogs);
        $this->assertTrue(
            $exportLogs->contains(fn ($log) => $log->entity_name === 'StockMovementReport')
        );
        $this->assertTrue(
            $exportLogs->contains(fn ($log) => $log->entity_name === 'AuditLogReport')
        );
    }

    public function test_staff_can_export_and_creates_audit_log(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($staff, ['staff-access']);

        // Staff exports stock movements
        $this->getJson('/api/reports/stock-movements/export')->assertOk();

        // Verify audit log created with staff user
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $staff->id,
            'module_name' => 'ReportingAudit',
            'entity_name' => 'StockMovementReport',
            'entity_id' => 0,
            'action' => 'EXPORT',
        ]);
    }

    public function test_export_audit_logs_include_metadata(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        // Export with specific filters
        $this->getJson('/api/audit-logs/export?action=CREATE&per_page=25')
            ->assertOk();

        // Get the export audit log
        $exportLog = AuditLog::where('module_name', 'ReportingAudit')
            ->where('entity_name', 'AuditLogReport')
            ->where('action', 'EXPORT')
            ->first();

        $this->assertNotNull($exportLog);
        $this->assertIsArray($exportLog->new_values);

        // Should contain filters and filename
        $this->assertArrayHasKey('filters', $exportLog->new_values);
        $this->assertArrayHasKey('filename', $exportLog->new_values);

        // Filters should include what was requested
        $this->assertArrayHasKey('action', $exportLog->new_values['filters']);
        $this->assertArrayHasKey('per_page', $exportLog->new_values['filters']);
    }
}
