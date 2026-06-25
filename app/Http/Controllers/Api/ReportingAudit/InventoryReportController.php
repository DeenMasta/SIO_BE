<?php

namespace App\Http\Controllers\Api\ReportingAudit;

use App\Application\Inventory\InventoryStockQuery;
use App\Application\Inventory\UseCases\GetInventoryDetailUseCase;
use App\Application\Inventory\UseCases\ListInventoriesUseCase;
use App\Application\Support\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Inventory\InventoryQueryRequest;
use App\Http\Resources\Api\Inventory\InventoryResource;
use App\Http\Resources\Api\Inventory\InventorySerialResource;
use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function __construct(
        private readonly ListInventoriesUseCase $listInventories,
        private readonly GetInventoryDetailUseCase $getInventoryDetail,
        private readonly InventoryStockQuery $inventoryStockQuery,
    ) {
    }

    public function index(InventoryQueryRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $records = $this->listInventories->execute($filters);

        return ApiResponse::paginated(
            $records,
            InventoryResource::collection($records->items()),
            'Inventory report retrieved successfully.',
            meta: [
                'summary' => $this->inventorySummary($filters),
            ],
        );
    }

    public function show(Product $product, InventoryQueryRequest $request): JsonResponse
    {
        $filters = [
            ...$request->validated(),
            'product_id' => (int) $product->id,
        ];

        $detail = $this->getInventoryDetail->execute($filters);
        $inventory = $detail['inventory'];
        $serials = $detail['serials'];
        $movements = $this->movementQuery((int) $product->id)->paginate(
            perPage: (int) ($filters['movement_per_page'] ?? 10),
            columns: ['*'],
            pageName: 'movement_page',
            page: (int) ($filters['movement_page'] ?? 1),
        );

        return ApiResponse::success(
            [
                'inventory' => new InventoryResource($inventory),
                'serials' => $serials !== null
                    ? InventorySerialResource::collection($serials->items())
                    : [],
                'recent_movements' => collect($movements->items())
                    ->map(fn (object $movement): array => $this->mapMovement($movement))
                    ->all(),
            ],
            'Inventory report retrieved successfully.',
            meta: array_filter([
                'serials_pagination' => $serials !== null ? [
                    'current_page' => $serials->currentPage(),
                    'per_page' => $serials->perPage(),
                    'total' => $serials->total(),
                    'last_page' => $serials->lastPage(),
                ] : null,
                'recent_movements_pagination' => [
                    'current_page' => $movements->currentPage(),
                    'per_page' => $movements->perPage(),
                    'total' => $movements->total(),
                    'last_page' => $movements->lastPage(),
                ],
                'movement_summary' => $this->movementSummary((int) $product->id),
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function inventorySummary(array $filters): array
    {
        $query = $this->inventoryStockQuery->base();
        $this->inventoryStockQuery->applyFilters($query, $filters);

        $summary = DB::query()
            ->fromSub($query, 'inventory_report')
            ->selectRaw('COUNT(*) as total_products')
            ->selectRaw("SUM(CASE WHEN stock_status = 'low_stock' THEN 1 ELSE 0 END) as low_stock_products")
            ->selectRaw("SUM(CASE WHEN stock_status = 'out_of_stock' THEN 1 ELSE 0 END) as out_of_stock_products")
            ->selectRaw('COALESCE(SUM(qty_available), 0) as total_available_units')
            ->selectRaw('COALESCE(SUM(qty_under_repair), 0) as total_under_repair_units')
            ->selectRaw('COALESCE(SUM(qty_returned), 0) as total_customer_return_units')
            ->first();

        return [
            'total_products' => (int) ($summary->total_products ?? 0),
            'low_stock_products' => (int) ($summary->low_stock_products ?? 0),
            'out_of_stock_products' => (int) ($summary->out_of_stock_products ?? 0),
            'total_available_units' => (int) ($summary->total_available_units ?? 0),
            'total_under_repair_units' => (int) ($summary->total_under_repair_units ?? 0),
            'total_customer_return_units' => (int) ($summary->total_customer_return_units ?? 0),
        ];
    }

    private function movementQuery(int $productId): Builder
    {
        return DB::table('stock_movements as sm')
            ->leftJoin('stock_items as si', 'si.id', '=', 'sm.stock_item_id')
            ->leftJoin('users as performer', 'performer.id', '=', 'sm.performed_by')
            ->leftJoin('stock_in_lines as sil', function ($join): void {
                $join->on('sil.id', '=', 'sm.reference_id')
                    ->where('sm.reference_table', '=', 'stock_in_lines');
            })
            ->leftJoin('stock_in as sin', 'sin.id', '=', 'sil.stock_in_id')
            ->leftJoin('qc_items as qci', function ($join): void {
                $join->on('qci.id', '=', 'sm.reference_id')
                    ->where('sm.reference_table', '=', 'qc_items');
            })
            ->leftJoin('quality_checks as qcd', 'qcd.id', '=', 'qci.qc_document_id')
            ->leftJoin('stock_out_line_items as soli', function ($join): void {
                $join->on('soli.id', '=', 'sm.reference_id')
                    ->where('sm.reference_table', '=', 'stock_out_line_items');
            })
            ->leftJoin('stock_out_lines as sol_item', 'sol_item.id', '=', 'soli.stock_out_line_id')
            ->leftJoin('stock_out as sout_item', 'sout_item.id', '=', 'sol_item.stock_out_id')
            ->leftJoin('stock_out_lines as sol', function ($join): void {
                $join->on('sol.id', '=', 'sm.reference_id')
                    ->where('sm.reference_table', '=', 'stock_out_lines');
            })
            ->leftJoin('stock_out as sout', 'sout.id', '=', 'sol.stock_out_id')
            ->leftJoin('customer_return_lines as crl', function ($join): void {
                $join->on('crl.id', '=', 'sm.reference_id')
                    ->where('sm.reference_table', '=', 'customer_return_lines');
            })
            ->leftJoin('customer_returns as cr', 'cr.id', '=', 'crl.customer_return_id')
            ->leftJoin('return_to_supplier_lines as rtsl', function ($join): void {
                $join->on('rtsl.id', '=', 'sm.reference_id')
                    ->where('sm.reference_table', '=', 'return_to_supplier_lines');
            })
            ->leftJoin('return_to_supplier as rts', 'rts.id', '=', 'rtsl.return_to_supplier_id')
            ->leftJoin('repairs as rep', function ($join): void {
                $join->on('rep.id', '=', 'sm.reference_id')
                    ->where('sm.reference_table', '=', 'repairs');
            })
            ->where('sm.product_id', $productId)
            ->orderByDesc('sm.movement_datetime')
            ->orderByDesc('sm.id')
            ->select([
                'sm.id',
                'sm.movement_datetime',
                'sm.movement_type',
                'sm.qty_in',
                'sm.qty_out',
                'sm.from_status',
                'sm.to_status',
                'sm.remarks',
                DB::raw('si.serial_number as serial_number'),
                DB::raw('performer.name as performed_by_name'),
                DB::raw("
                    COALESCE(
                        sin.stock_in_number,
                        qcd.document_number,
                        sout_item.stock_out_number,
                        sout.stock_out_number,
                        cr.return_transaction_number,
                        rts.rts_transaction_number,
                        rep.repair_transaction_number
                    ) as document_number
                "),
                DB::raw("
                    CASE
                        WHEN sm.reference_table = 'stock_in_lines' THEN 'Stock In'
                        WHEN sm.reference_table = 'qc_items' THEN 'QC Document'
                        WHEN sm.reference_table IN ('stock_out_line_items', 'stock_out_lines') THEN 'Stock Out'
                        WHEN sm.reference_table = 'customer_return_lines' THEN 'Customer Return'
                        WHEN sm.reference_table = 'return_to_supplier_lines' THEN 'Return To Supplier'
                        WHEN sm.reference_table = 'repairs' THEN 'Repair'
                        ELSE 'Inventory Activity'
                    END as document_type
                "),
            ]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function movementSummary(int $productId): array
    {
        $summary = DB::table('stock_movements')
            ->where('product_id', $productId)
            ->selectRaw('COUNT(*) as total_movements')
            ->selectRaw('COALESCE(SUM(qty_in), 0) as total_qty_in')
            ->selectRaw('COALESCE(SUM(qty_out), 0) as total_qty_out')
            ->selectRaw('MAX(movement_datetime) as last_movement_at')
            ->first();

        return [
            'total_movements' => (int) ($summary->total_movements ?? 0),
            'total_qty_in' => (int) ($summary->total_qty_in ?? 0),
            'total_qty_out' => (int) ($summary->total_qty_out ?? 0),
            'last_movement_at' => $summary->last_movement_at,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function mapMovement(object $movement): array
    {
        return [
            'id' => (int) $movement->id,
            'movement_datetime' => $movement->movement_datetime,
            'movement_type' => $movement->movement_type,
            'document_number' => $movement->document_number,
            'document_type' => $movement->document_type,
            'serial_number' => $movement->serial_number,
            'qty_in' => (int) ($movement->qty_in ?? 0),
            'qty_out' => (int) ($movement->qty_out ?? 0),
            'from_status' => $movement->from_status,
            'to_status' => $movement->to_status,
            'performed_by_name' => $movement->performed_by_name,
            'remarks' => $movement->remarks,
        ];
    }
}
