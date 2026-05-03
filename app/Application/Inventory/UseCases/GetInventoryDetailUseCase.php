<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Inventory\InventoryStockQuery;
use App\Models\StockItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class GetInventoryDetailUseCase implements UseCase
{
    public function __construct(private readonly InventoryStockQuery $inventoryStockQuery)
    {
    }

    /**
     * @return array{inventory: object, available_serials: LengthAwarePaginator|null}
     */
    public function execute(mixed $payload = null): array
    {
        $filters = is_array($payload) ? $payload : [];
        $productId = (int) ($filters['product_id'] ?? 0);
        $serialPerPage = (int) ($filters['serial_per_page'] ?? 50);

        $inventory = $this->inventoryStockQuery
            ->base()
            ->where('p.id', $productId)
            ->firstOrFail();

        $serials = null;
        if ((bool) $inventory->requires_serial_number) {
            $serials = $this->availableSerialsQuery($productId, trim((string) ($filters['serial_q'] ?? '')))
                ->paginate($serialPerPage > 0 ? $serialPerPage : 50, ['*'], 'serial_page');
        }

        return [
            'inventory' => $inventory,
            'available_serials' => $serials,
        ];
    }

    private function availableSerialsQuery(int $productId, string $search): Builder
    {
        return StockItem::query()
            ->from('stock_items as si')
            ->leftJoin('stock_in_lines as sil', 'sil.id', '=', 'si.stock_in_line_id')
            ->leftJoin('stock_in as sin', 'sin.id', '=', 'sil.stock_in_id')
            ->select([
                'si.id',
                'si.product_id',
                'si.stock_in_line_id',
                'si.serial_number',
                'si.current_status',
                'si.qc_status',
                'si.received_condition',
                'si.last_movement_at',
                'si.remarks',
                DB::raw('sin.stock_in_number'),
                DB::raw('sin.stock_in_date'),
            ])
            ->where('si.product_id', $productId)
            ->where('si.current_status', 'IN_STOCK')
            ->where('si.is_available', true)
            ->where('si.qc_status', 'PASSED')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where('si.serial_number', 'like', '%'.$search.'%');
            })
            ->orderBy('si.serial_number');
    }
}
