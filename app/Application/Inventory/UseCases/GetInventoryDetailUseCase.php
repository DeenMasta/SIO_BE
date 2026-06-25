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
     * @return array{inventory: object, serials: LengthAwarePaginator|null}
     */
    public function execute(mixed $payload = null): array
    {
        $filters = is_array($payload) ? $payload : [];
        $productId = (int) ($filters['product_id'] ?? 0);
        $serialPerPage = (int) ($filters['serial_per_page'] ?? 50);
        $serialSearch = trim((string) ($filters['serial_q'] ?? ''));
        $serialStatus = trim((string) ($filters['serial_status'] ?? ''));

        $inventory = $this->inventoryStockQuery
            ->base()
            ->where('p.id', $productId)
            ->firstOrFail();

        $serials = null;
        if ((bool) $inventory->requires_serial_number) {
            $serials = $this->serialsQuery($productId, $serialSearch, $serialStatus)
                ->paginate($serialPerPage > 0 ? $serialPerPage : 50, ['*'], 'serial_page');
        }

        return [
            'inventory' => $inventory,
            'serials' => $serials,
        ];
    }

    private function serialsQuery(int $productId, string $search, string $status): Builder
    {
        return StockItem::query()
            ->from('stock_items', 'si')
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
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where('si.serial_number', 'like', '%'.$search.'%');
            })
            ->when($status !== '', function (Builder $query) use ($status): void {
                $query->where('si.current_status', $status);
            })
            ->orderBy('si.serial_number');
    }
}
