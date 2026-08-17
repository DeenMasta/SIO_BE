<?php

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\UseCase;
use App\Application\Inventory\InventoryStockQuery;
use App\Application\Support\AuditLogger;
use App\Application\Support\DocumentNumberGenerator;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\InventoryCore\Enums\StocktakeStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\StockItem;
use App\Models\Stocktake;
use Illuminate\Support\Facades\DB;

final class CreateStocktakeUseCase implements UseCase
{
    public function __construct(private readonly InventoryStockQuery $inventoryStockQuery, private readonly DocumentNumberGenerator $numbers, private readonly AuditLogger $auditLogger) {}

    public function execute(mixed $payload = null): Stocktake
    {
        $data = (array) $payload;

        return DB::transaction(function () use ($data): Stocktake {
            $inventory = $this->inventoryStockQuery->base()
                ->whereRaw('('.$this->inventoryStockQuery->availableQtyExpression().') > 0')
                ->orderBy('p.product_code')
                ->get();

            $stocktake = Stocktake::query()->create([
                'stocktake_number' => ($data['stocktake_number'] ?? null) ?: $this->numbers->generateStocktakeNumber(),
                'stocktake_date' => $data['stocktake_date'],
                'status' => StocktakeStatus::Counting,
                'created_by' => $data['created_by'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($inventory as $row) {
                $line = $stocktake->lines()->create([
                    'product_id' => (int) $row->product_id,
                    'expected_qty' => (int) $row->qty_available,
                ]);

                if (! $row->requires_serial_number) {
                    continue;
                }

                StockItem::query()
                    ->where('product_id', $row->product_id)
                    ->where('current_status', StockItemStatus::InStock->value)
                    ->where('is_available', true)
                    ->where('qc_status', StockItemQcStatus::Passed->value)
                    ->withoutUnresolvedMissingItemReport()
                    ->orderBy('id')
                    ->get(['id', 'serial_number'])
                    ->each(fn (StockItem $item) => $line->items()->create([
                        'stock_item_id' => $item->id,
                        'serial_number' => $item->serial_number,
                    ]));
            }

            $this->auditLogger->log((int) $data['created_by'], 'StockManagement', 'Stocktake', (int) $stocktake->id, AuditAction::Create, newValues: [
                'stocktake_number' => $stocktake->stocktake_number,
                'stocktake_date' => $stocktake->stocktake_date->toDateString(),
                'line_count' => $stocktake->lines()->count(),
            ]);

            return $stocktake->load(['lines.product', 'lines.items', 'createdByUser']);
        });
    }
}
