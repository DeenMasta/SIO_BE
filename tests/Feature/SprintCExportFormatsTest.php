<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SprintCExportFormatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_movement_export_csv_format(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export as CSV (default format)
        $this->getJson('/api/reports/stock-movements/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition');
    }

    public function test_stock_movement_export_xlsx_format(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export as XLSX
        $this->getJson('/api/reports/stock-movements/export?format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('content-disposition');
    }

    public function test_stock_movement_export_pdf_format(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export as PDF
        $this->getJson('/api/reports/stock-movements/export?format=pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition');
    }

    public function test_stock_movement_export_csv_explicit_format(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export with explicit CSV format
        $this->getJson('/api/reports/stock-movements/export?format=csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_audit_log_export_csv_format(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        // Export audit logs as CSV
        $this->getJson('/api/audit-logs/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_audit_log_export_xlsx_format(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        // Export audit logs as XLSX
        $this->getJson('/api/audit-logs/export?format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_audit_log_export_pdf_format(): void
    {
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin, ['admin-access']);

        // Export audit logs as PDF
        $this->getJson('/api/audit-logs/export?format=pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_export_rejects_invalid_format(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Try export with invalid format
        $this->getJson('/api/reports/stock-movements/export?format=invalid')
            ->assertStatus(422);
    }

    public function test_export_filename_has_correct_extension(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export as CSV
        $response = $this->getJson('/api/reports/stock-movements/export?format=csv')
            ->assertOk();

        $contentDisposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('.csv', $contentDisposition);

        // Export as XLSX
        $response = $this->getJson('/api/reports/stock-movements/export?format=xlsx')
            ->assertOk();

        $contentDisposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('.xlsx', $contentDisposition);

        // Export as PDF
        $response = $this->getJson('/api/reports/stock-movements/export?format=pdf')
            ->assertOk();

        $contentDisposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('.pdf', $contentDisposition);
    }

    public function test_export_format_logged_in_audit(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export as XLSX
        $this->getJson('/api/reports/stock-movements/export?format=xlsx')
            ->assertOk();

        // Verify audit log includes format
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'module_name' => 'ReportingAudit',
            'entity_name' => 'StockMovementReport',
            'action' => 'EXPORT',
        ]);
    }

    public function test_staff_can_export_with_different_formats(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->staff()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($staff, ['staff-access']);

        // Staff exports as CSV
        $this->getJson('/api/reports/stock-movements/export?format=csv')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        // Staff exports as PDF
        $this->getJson('/api/reports/stock-movements/export?format=pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Staff exports as XLSX
        $this->getJson('/api/reports/stock-movements/export?format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_with_filters_and_format(): void
    {
        $admin = User::factory()->admin()->create();
        [$stockItemId] = $this->createDeliveredDevice($admin);

        Sanctum::actingAs($admin, ['admin-access']);

        // Export with filters and format
        $this->getJson('/api/reports/stock-movements/export?per_page=10&date_from=2026-01-01&format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
