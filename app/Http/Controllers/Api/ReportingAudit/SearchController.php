<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReportingAudit\Search\SearchCatalogRequest;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockIn;
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
            ->select(['id', 'product_id', 'serial_number', 'factory_serial_number', 'current_status', 'is_available'])
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
            ->select(['id', 'stock_out_number', 'stock_out_date', 'invoice_number', 'customer_id'])
            ->where('invoice_number', 'like', '%'.(string) $filters['query'].'%')
            ->orderByDesc('id')
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

    public function deliveryOrders(SearchCatalogRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $records = StockIn::query()
            ->select(['id', 'stock_in_number', 'stock_in_date', 'delivery_order_number', 'supplier_id'])
            ->where('delivery_order_number', 'like', '%'.(string) $filters['query'].'%')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return $this->paginatedResponse($records, 'Delivery order search completed successfully.');
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
