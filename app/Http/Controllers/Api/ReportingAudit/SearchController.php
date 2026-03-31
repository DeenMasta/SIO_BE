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
            ->select(['id', 'product_code', 'product_name', 'product_type', 'unit_of_measure'])
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
            ->select(['id', 'product_id', 'serial_number', 'factory_serial_number', 'current_status', 'is_available', 'qc_status'])
            ->where(function (Builder $query) use ($filters): void {
                $query->where('serial_number', 'like', '%'.(string) $filters['query'].'%')
                    ->orWhere('factory_serial_number', 'like', '%'.(string) $filters['query'].'%');
            })
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->paginatedResponse($records, 'Serial search completed successfully.');
    }

    public function invoices(SearchCatalogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $records = StockOut::query()
            ->join('sale_orders', 'sale_orders.id', '=', 'stock_outs.sale_order_id')
            ->select(['stock_outs.id', 'stock_outs.stock_out_number', 'stock_outs.stock_out_date', 'sale_orders.invoice_number', 'stock_outs.customer_id'])
            ->where('sale_orders.invoice_number', 'like', '%'.(string) $filters['query'].'%')
            ->orderByDesc('stock_outs.id')
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
