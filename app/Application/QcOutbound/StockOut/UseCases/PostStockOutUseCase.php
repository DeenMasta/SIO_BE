<?php

namespace App\Application\QcOutbound\StockOut\UseCases;

use App\Application\Contracts\Repositories\StockOutRepository;
use App\Application\Contracts\UseCase;
use App\Application\Support\AuditLogger;
use App\Domain\InventoryCore\Enums\MovementType;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\MasterData\Enums\ProductType;
use App\Domain\QcOutbound\Enums\StockOutStatus;
use App\Domain\ReportingAudit\Enums\AuditAction;
use App\Models\Product;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\StockOut;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostStockOutUseCase implements UseCase
{
    public function __construct(
        private readonly StockOutRepository $stockOuts,
        private readonly AuditLogger $auditLogger,
    )
    {
    }

    /**
     * @return array{stock_out: StockOut, replayed: bool}
     */
    public function execute(mixed $payload = null): array
    {
        $data = (array) $payload;

        $existing = $this->stockOuts->findByIdempotencyKey((string) $data['idempotency_key']);
        if ($existing !== null) {
            $this->auditLogger->log(
                userId: (int) ($data['pic_id'] ?? 0),
                moduleName: 'QcOutbound',
                entityName: 'StockOut',
                entityId: (int) $existing->id,
                action: AuditAction::Post,
                newValues: ['replayed' => true, 'idempotency_key' => $data['idempotency_key']],
            );

            return [
                'stock_out' => $existing,
                'replayed' => true,
            ];
        }

        try {
            return DB::transaction(function () use ($data): array {
                $stockOut = $this->stockOuts->create([
                    'stock_out_number' => $data['stock_out_number'],
                    'idempotency_key' => $data['idempotency_key'],
                    'stock_out_date' => $data['stock_out_date'],
                    'customer_id' => $data['customer_id'],
                    'invoice_number' => $data['invoice_number'],
                    'pic_id' => $data['pic_id'],
                    'pick_list_reference' => $data['pick_list_reference'] ?? null,
                    'packing_verified' => (bool) ($data['packing_verified'] ?? false),
                    'status' => StockOutStatus::Posted,
                    'remarks' => $data['remarks'] ?? null,
                ]);

            foreach ($data['lines'] as $line) {
                $product = Product::query()->findOrFail((int) $line['product_id']);
                $qty = (int) $line['qty'];

                $stockOutLine = $stockOut->lines()->create([
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'remarks' => $line['remarks'] ?? null,
                ]);

                if (in_array($product->product_type, [ProductType::Device, ProductType::Accessory], true)) {
                    $stockItemIds = array_values(array_map('intval', Arr::wrap($line['stock_item_ids'] ?? [])));

                    if (count($stockItemIds) !== $qty) {
                        throw ValidationException::withMessages([
                            'lines' => ['Serialized products require stock_item_ids count to match qty.'],
                        ]);
                    }

                    $stockItems = StockItem::query()
                        ->whereIn('id', $stockItemIds)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->get();

                    if ($stockItems->count() !== count($stockItemIds)) {
                        throw ValidationException::withMessages([
                            'lines' => ['Some stock items are invalid for this stock out line.'],
                        ]);
                    }

                    foreach ($stockItems as $stockItem) {
                        if ($stockItem->current_status !== StockItemStatus::InStock || ! $stockItem->is_available) {
                            throw ValidationException::withMessages([
                                'lines' => ['Stock out only allows stock items in IN_STOCK status.'],
                            ]);
                        }

                        $lineItem = $stockOutLine->lineItems()->create([
                            'stock_item_id' => $stockItem->id,
                        ]);

                        $stockItem->update([
                            'current_status' => StockItemStatus::Delivered,
                            'is_available' => false,
                            'last_movement_at' => now(),
                        ]);

                        StockMovement::query()->create([
                            'movement_datetime' => now(),
                            'product_id' => $product->id,
                            'stock_item_id' => $stockItem->id,
                            'movement_type' => MovementType::StockOut,
                            'reference_table' => 'stock_out_line_items',
                            'reference_id' => (int) $lineItem->id,
                            'qty_in' => 0,
                            'qty_out' => 1,
                            'from_status' => StockItemStatus::InStock->value,
                            'to_status' => StockItemStatus::Delivered->value,
                            'performed_by' => (int) $data['pic_id'],
                            'remarks' => $line['remarks'] ?? null,
                        ]);

                    }

                    continue;
                }

                $movementTotals = StockMovement::query()
                    ->where('product_id', $product->id)
                    ->whereNull('stock_item_id')
                    ->selectRaw("COALESCE(SUM(CASE WHEN to_status = 'IN_STOCK' THEN qty_in ELSE 0 END), 0) as qty_in_stock_in")
                    ->selectRaw("COALESCE(SUM(CASE WHEN from_status = 'IN_STOCK' THEN qty_out ELSE 0 END), 0) as qty_in_stock_out")
                    ->lockForUpdate()
                    ->first();

                $availableQty = max(
                    (int) ($movementTotals->qty_in_stock_in ?? 0) - (int) ($movementTotals->qty_in_stock_out ?? 0),
                    0,
                );
                if ($availableQty < $qty) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf('Insufficient stock for product %s. Available: %d, requested: %d.', $product->product_code, $availableQty, $qty)],
                    ]);
                }

                StockMovement::query()->create([
                    'movement_datetime' => now(),
                    'product_id' => $product->id,
                    'stock_item_id' => null,
                    'movement_type' => MovementType::StockOut,
                    'reference_table' => 'stock_out_lines',
                    'reference_id' => (int) $stockOutLine->id,
                    'qty_in' => 0,
                    'qty_out' => $qty,
                    'from_status' => StockItemStatus::InStock->value,
                    'to_status' => StockItemStatus::Delivered->value,
                    'performed_by' => (int) $data['pic_id'],
                    'remarks' => $line['remarks'] ?? null,
                ]);

            }

                $result = [
                    'stock_out' => $stockOut->fresh('lines.lineItems'),
                    'replayed' => false,
                ];

                $this->auditLogger->log(
                    userId: (int) $data['pic_id'],
                    moduleName: 'QcOutbound',
                    entityName: 'StockOut',
                    entityId: (int) $result['stock_out']->id,
                    action: AuditAction::Post,
                    newValues: ['stock_out_number' => $result['stock_out']->stock_out_number, 'status' => $result['stock_out']->status?->value],
                );

                return $result;
            });
        } catch (QueryException $exception) {
            $replayed = $this->stockOuts->findByIdempotencyKey((string) $data['idempotency_key']);
            if ($replayed !== null) {
                return [
                    'stock_out' => $replayed,
                    'replayed' => true,
                ];
            }

            throw $exception;
        }
    }
}
