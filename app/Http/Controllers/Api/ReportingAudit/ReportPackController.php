<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportingAudit\Report\ReportPackRequest;
use App\Models\CustomerReturn;
use App\Models\PurchaseOrder;
use App\Models\Repair;
use App\Models\ReturnToSupplier;
use App\Models\StockIn;
use App\Models\StockOut;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReportPackController extends Controller
{
    public function stockBalance(ReportPackRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);
        $query = DB::table('products as p')
            ->leftJoin('stock_balances as sb', 'sb.product_id', '=', 'p.id')
            ->select([
                'p.id as product_id',
                'p.product_code',
                'p.product_name',
                'p.reorder_level',
                DB::raw('0 as qty_received_pending_qc'),
                DB::raw('COALESCE(sb.qty_in_stock, 0) as qty_in_stock'),
                DB::raw('COALESCE(sb.qty_delivered, 0) as qty_delivered'),
                DB::raw('COALESCE(sb.qty_under_repair, 0) as qty_under_repair'),
                DB::raw('COALESCE(sb.qty_returned, 0) as qty_returned'),
                DB::raw('COALESCE(sb.qty_returned_to_supplier, 0) as qty_returned_to_supplier'),
            ])
            ->orderByDesc('qty_in_stock');

        if (! empty($filters['product_id'])) {
            $query->where('p.id', (int) $filters['product_id']);
        }

        $records = $query->paginate($perPage);

        return $this->paginatedResponse($records, 'Stock balance report retrieved successfully.');
    }

    public function stockCard(ReportPackRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = DB::table('stock_movements as sm')
            ->leftJoin('products as p', 'p.id', '=', 'sm.product_id')
            ->select([
                'sm.id',
                'sm.movement_datetime',
                'sm.product_id',
                'p.product_code',
                'p.product_name',
                'sm.stock_item_id',
                'sm.movement_type',
                'sm.reference_table',
                'sm.reference_id',
                'sm.qty_in',
                'sm.qty_out',
                'sm.from_status',
                'sm.to_status',
                'sm.performed_by',
                'sm.remarks',
            ])
            ->orderByDesc('sm.id');

        if (! empty($filters['product_id'])) {
            $query->where('sm.product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('sm.movement_datetime', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('sm.movement_datetime', '<=', (string) $filters['date_to']);
        }

        $records = $query->paginate($perPage);

        return $this->paginatedResponse($records, 'Stock card report retrieved successfully.');
    }

    public function lowStock(ReportPackRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);
        $query = DB::table('products as p')
            ->leftJoin('stock_balances as sb', 'sb.product_id', '=', 'p.id')
            ->select([
                'p.id as product_id',
                'p.product_code',
                'p.product_name',
                'p.reorder_level',
                DB::raw('COALESCE(sb.qty_in_stock, 0) as qty_in_stock'),
                DB::raw('(p.reorder_level - COALESCE(sb.qty_in_stock, 0)) as shortage_qty'),
            ])
            ->where('p.reorder_level', '>', 0)
            ->whereRaw('COALESCE(sb.qty_in_stock, 0) < p.reorder_level')
            ->orderByDesc('shortage_qty')
            ->orderBy('p.product_code');

        if (! empty($filters['product_id'])) {
            $query->where('p.id', (int) $filters['product_id']);
        }

        $records = $query->paginate($perPage);

        return $this->paginatedResponse($records, 'Low stock report retrieved successfully.');
    }

    public function poSummary(ReportPackRequest $request): JsonResponse
    {
        $records = $this->purchaseOrderBaseQuery($request->validated())->paginate((int) $request->integer('per_page', 15));

        return $this->paginatedResponse($records, 'Purchase order summary report retrieved successfully.');
    }

    public function poOpen(ReportPackRequest $request): JsonResponse
    {
        $records = $this->purchaseOrderBaseQuery($request->validated())
            ->whereIn('status', ['DRAFT', 'ISSUED'])
            ->paginate((int) $request->integer('per_page', 15));

        return $this->paginatedResponse($records, 'Open purchase order report retrieved successfully.');
    }

    public function poAging(ReportPackRequest $request): JsonResponse
    {
        $records = $this->purchaseOrderBaseQuery($request->validated())
            ->whereIn('status', ['DRAFT', 'ISSUED'])
            ->orderBy('po_date')
            ->paginate((int) $request->integer('per_page', 15));

        return $this->paginatedResponse($records, 'Purchase order aging report retrieved successfully.');
    }

    public function stockInBySupplierDo(ReportPackRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = DB::table('stock_in as si')
            ->leftJoin('suppliers as s', 's.id', '=', 'si.supplier_id')
            ->leftJoin('stock_in_lines as sil', 'sil.stock_in_id', '=', 'si.id')
            ->select([
                'si.id',
                'si.stock_in_number',
                'si.stock_in_date',
                'si.supplier_id',
                's.supplier_name',
                DB::raw('COALESCE(SUM(sil.received_qty), 0) as total_received_qty'),
            ])
            ->groupBy('si.id', 'si.stock_in_number', 'si.stock_in_date', 'si.supplier_id', 's.supplier_name')
            ->orderByDesc('si.id');

        if (! empty($filters['supplier_id'])) {
            $query->where('si.supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('si.stock_in_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('si.stock_in_date', '<=', (string) $filters['date_to']);
        }

        $records = $query->paginate($perPage);

        return $this->paginatedResponse($records, 'Stock in by supplier report retrieved successfully.');
    }


    public function stockOutByInvoiceCustomer(ReportPackRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = DB::table('stock_outs as so')
            ->leftJoin('customers as c', 'c.id', '=', 'so.customer_id')
            ->leftJoin('stock_out_lines as sol', 'sol.stock_out_id', '=', 'so.id')
            ->leftJoin('sale_orders as sale', 'sale.id', '=', 'so.sale_order_id')
            ->select([
                'so.id',
                'so.stock_out_number',
                'so.stock_out_date',
                'sale.invoice_number',
                'so.customer_id',
                'c.customer_name',
                DB::raw('COALESCE(SUM(sol.qty), 0) as total_qty'),
            ])
            ->groupBy('so.id', 'so.stock_out_number', 'so.stock_out_date', 'sale.invoice_number', 'so.customer_id', 'c.customer_name')
            ->orderByDesc('so.id');

        if (! empty($filters['customer_id'])) {
            $query->where('so.customer_id', (int) $filters['customer_id']);
        }
        if (! empty($filters['invoice_number'])) {
            $query->where('sale.invoice_number', 'like', '%'.(string) $filters['invoice_number'].'%');
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('so.stock_out_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('so.stock_out_date', '<=', (string) $filters['date_to']);
        }

        $records = $query->paginate($perPage);

        return $this->paginatedResponse($records, 'Stock out by invoice/customer report retrieved successfully.');
    }

    public function repairSummary(ReportPackRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = $this->applyDateRange(
            Repair::query()->orderByDesc('id'),
            'repair_date',
            $filters
        );

        $this->applyAgeBucketFilter($query, 'repair_date', $filters);

        $records = $query->paginate((int) $request->integer('per_page', 15));

        $items = collect($records->items())
            ->map(fn (Repair $repair): array => [
                ...$repair->toArray(),
                'age_days' => CarbonImmutable::parse((string) $repair->repair_date)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()),
                'age_bucket' => $this->resolveAgeBucket((string) $repair->repair_date),
            ])
            ->all();

        return $this->paginatedArrayResponse($records, $items, 'Repair summary report retrieved successfully.');
    }

    public function rtsSummary(ReportPackRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = $this->applyDateRange(
            ReturnToSupplier::query()->orderByDesc('id'),
            'return_date',
            $filters
        );

        $this->applyAgeBucketFilter($query, 'return_date', $filters);

        $records = $query->paginate((int) $request->integer('per_page', 15));

        $items = collect($records->items())
            ->map(fn (ReturnToSupplier $rts): array => [
                ...$rts->toArray(),
                'age_days' => CarbonImmutable::parse((string) $rts->return_date)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()),
                'age_bucket' => $this->resolveAgeBucket((string) $rts->return_date),
            ])
            ->all();

        return $this->paginatedArrayResponse($records, $items, 'Return to supplier summary report retrieved successfully.');
    }

    public function customerReturnSummary(ReportPackRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $query = $this->applyDateRange(
            CustomerReturn::query()->orderByDesc('id'),
            'return_date',
            $filters
        );

        $this->applyAgeBucketFilter($query, 'return_date', $filters);

        $records = $query->paginate((int) $request->integer('per_page', 15));

        $items = collect($records->items())
            ->map(fn (CustomerReturn $customerReturn): array => [
                ...$customerReturn->toArray(),
                'age_days' => CarbonImmutable::parse((string) $customerReturn->return_date)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay()),
                'age_bucket' => $this->resolveAgeBucket((string) $customerReturn->return_date),
            ])
            ->all();

        return $this->paginatedArrayResponse($records, $items, 'Customer return summary report retrieved successfully.');
    }

    public function serialTrace(\Illuminate\Http\Request $request): JsonResponse
    {
        $serialNumber = trim((string) $request->string('serial_number', ''));

        if (empty($serialNumber)) {
            return ApiResponse::error(
                'Serial number is required.',
                status: 422,
            );
        }

        $service = new \App\Application\ReportingAudit\Reports\Services\SerialTracingService();
        $trace = $service->getSerialTrace($serialNumber);

        if (! $trace) {
            return ApiResponse::error(
                'Serial number not found in inventory.',
                status: 404,
            );
        }

        return ApiResponse::success(
            $trace,
            'Serial traceability retrieved successfully.',
        );
    }

    private function purchaseOrderBaseQuery(array $filters): Builder
    {
        $query = PurchaseOrder::query()->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', strtoupper((string) $filters['status']));
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['po_number'])) {
            $query->where('po_number', 'like', '%'.(string) $filters['po_number'].'%');
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('po_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('po_date', '<=', (string) $filters['date_to']);
        }

        return $query;
    }

    private function applyDateRange(Builder $query, string $column, array $filters): Builder
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate($column, '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate($column, '<=', (string) $filters['date_to']);
        }

        return $query;
    }

    private function applyAgeBucketFilter(Builder $query, string $column, array $filters): void
    {
        $today = CarbonImmutable::now()->startOfDay();

        if (($filters['age_bucket'] ?? null) === '0_7') {
            $query->whereDate($column, '>=', $today->subDays(7)->toDateString());

            return;
        }

        if (($filters['age_bucket'] ?? null) === '8_30') {
            $query->whereDate($column, '<=', $today->subDays(8)->toDateString())
                ->whereDate($column, '>=', $today->subDays(30)->toDateString());

            return;
        }

        if (($filters['age_bucket'] ?? null) === '31_plus') {
            $query->whereDate($column, '<=', $today->subDays(31)->toDateString());
        }
    }

    private function resolveAgeBucket(string $date): string
    {
        $ageDays = CarbonImmutable::parse($date)->startOfDay()->diffInDays(CarbonImmutable::now()->startOfDay());

        if ($ageDays <= 7) {
            return '0_7';
        }

        if ($ageDays <= 30) {
            return '8_30';
        }

        return '31_plus';
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function paginatedArrayResponse(object $records, array $items, string $message): JsonResponse
    {
        return ApiResponse::success(
            $items,
            $message,
            meta: [
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
        );
    }

    private function paginatedResponse(object $records, string $message): JsonResponse
    {
        return ApiResponse::success(
            $records->items(),
            $message,
            meta: [
                'pagination' => [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ],
            ],
        );
    }
}
