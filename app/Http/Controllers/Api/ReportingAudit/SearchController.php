<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportingAudit\Search\SearchCatalogRequest;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockItem;
use App\Models\StockOut;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller
{
    public function products(SearchCatalogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $records = Product::query()
            ->select(['id', 'product_code', 'product_name', 'product_type', 'uom'])
            ->where(function (Builder $query) use ($filters): void {
                $query->where('product_code', 'like', '%'.(string) $filters['query'].'%')
                    ->orWhere('product_name', 'like', '%'.(string) $filters['query'].'%');
            })
            ->orderBy('product_code')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->paginatedResponse($records, 'Product search completed successfully.');
    }

    public function serials(SearchCatalogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $records = StockItem::query()
            ->with(['product:id,product_code,product_name,product_model'])
            ->select([
                'id',
                'product_id',
                'serial_number',
                'current_status',
                'is_available',
                'qc_status',
                'last_movement_at',
            ])
            ->when(! empty($filters['query'] ?? ''), function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['query']);

                $query->where('serial_number', 'like', '%'.$search.'%');
            })
            ->when(! empty($filters['product_id'] ?? null), function (Builder $query) use ($filters): void {
                $query->where('product_id', (int) $filters['product_id']);
            })
            ->when(! empty($filters['current_status'] ?? ''), function (Builder $query) use ($filters): void {
                $query->where('current_status', (string) $filters['current_status']);
            })
            ->when(! empty($filters['qc_status'] ?? ''), function (Builder $query) use ($filters): void {
                $query->where('qc_status', (string) $filters['qc_status']);
            })
            ->when(array_key_exists('is_available', $filters), function (Builder $query) use ($filters): void {
                $query->where('is_available', (bool) $filters['is_available']);
            })
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->paginatedResponse($records, 'Serial search completed successfully.');
    }

    public function invoices(SearchCatalogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $records = StockOut::query()
            ->leftJoin('sale_orders', 'sale_orders.id', '=', 'stock_out.sale_order_id')
            ->select([
                'stock_out.id',
                'stock_out.stock_out_number',
                'stock_out.stock_out_date',
                'stock_out.invoice_number',
                'stock_out.customer_id',
                'sale_orders.so_number',
            ])
            ->where(function (Builder $query) use ($filters): void {
                $search = '%'.(string) $filters['query'].'%';
                $query->where('stock_out.invoice_number', 'like', $search)
                    ->orWhere('sale_orders.invoice_number', 'like', $search)
                    ->orWhere('stock_out.stock_out_number', 'like', $search);
            })
            ->orderByDesc('stock_out.id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->paginatedResponse($records, 'Invoice search completed successfully.');
    }

    public function purchaseOrders(SearchCatalogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $records = PurchaseOrder::query()
            ->select(['id', 'po_number', 'po_date', 'supplier_id', 'status'])
            ->where('po_number', 'like', '%'.(string) $filters['query'].'%')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->paginatedResponse($records, 'PO search completed successfully.');
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
