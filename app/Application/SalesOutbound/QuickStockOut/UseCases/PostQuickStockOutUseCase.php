<?php

namespace App\Application\SalesOutbound\QuickStockOut\UseCases;

use App\Application\QcOutbound\StockOut\UseCases\PostStockOutUseCase;
use App\Application\Support\DocumentNumberGenerator;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\SalesOutbound\Enums\QuickStockOutStatus;
use App\Models\QuickStockOut;
use App\Models\StockItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PostQuickStockOutUseCase
{
    public function __construct(
        private readonly PostStockOutUseCase $postStockOut,
        private readonly DocumentNumberGenerator $documentNumberGenerator,
    ) {
    }

    public function execute(array $payload, int $userId): QuickStockOut
    {
        $serialNumbers = array_values(array_filter(array_map(
            static fn (mixed $serial): string => trim((string) $serial),
            $payload['serial_numbers'] ?? [],
        )));

        if ($serialNumbers === []) {
            throw ValidationException::withMessages([
                'serial_numbers' => ['At least one serial number is required.'],
            ]);
        }

        if (count($serialNumbers) !== count(array_unique(array_map('strtoupper', $serialNumbers)))) {
            throw ValidationException::withMessages([
                'serial_numbers' => ['Duplicate serial numbers are not allowed in one quick stock out request.'],
            ]);
        }

        return DB::transaction(function () use ($payload, $serialNumbers, $userId): QuickStockOut {
            $stockItems = StockItem::query()
                ->with('product')
                ->whereIn('serial_number', $serialNumbers)
                ->where('current_status', StockItemStatus::InStock->value)
                ->where('is_available', true)
                ->where('qc_status', StockItemQcStatus::Passed->value)
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (StockItem $item): string => strtoupper((string) $item->serial_number));

            $missingSerials = [];
            foreach ($serialNumbers as $serialNumber) {
                if (! $stockItems->has(strtoupper($serialNumber))) {
                    $missingSerials[] = $serialNumber;
                }
            }

            if ($missingSerials !== []) {
                throw ValidationException::withMessages([
                    'serial_numbers' => [
                        sprintf(
                            'Some serials are invalid, not currently IN_STOCK, unavailable, or have not passed QC: %s.',
                            implode(', ', $missingSerials),
                        ),
                    ],
                ]);
            }

            $quickStockOut = QuickStockOut::query()->create([
                'qso_number' => $this->documentNumberGenerator->generateQuickStockOutNumber(),
                'qso_date' => $payload['qso_date'],
                'customer_id' => $payload['customer_id'],
                'status' => QuickStockOutStatus::Pending,
                'remarks' => $payload['remarks'] ?? null,
                'created_by' => $userId,
            ]);

            $groupedItems = [];
            foreach ($serialNumbers as $serialNumber) {
                $stockItem = $stockItems->get(strtoupper($serialNumber));
                $productId = (int) $stockItem->product_id;
                $groupedItems[$productId] ??= [
                    'product_id' => $productId,
                    'items' => [],
                ];
                $groupedItems[$productId]['items'][] = $stockItem;
            }

            $stockOutLinesPayload = [];
            foreach (array_values($groupedItems) as $group) {
                $quickLine = $quickStockOut->lines()->create([
                    'product_id' => $group['product_id'],
                    'quantity' => count($group['items']),
                ]);

                $quickLineItemIds = [];
                $stockItemIds = [];

                foreach ($group['items'] as $stockItem) {
                    $quickLineItem = $quickLine->lineItems()->create([
                        'stock_item_id' => $stockItem->id,
                        'serial_number_snapshot' => $stockItem->serial_number,
                    ]);

                    $quickLineItemIds[] = (int) $quickLineItem->id;
                    $stockItemIds[] = (int) $stockItem->id;
                }

                $stockOutLinesPayload[] = [
                    'product_id' => $group['product_id'],
                    'qty' => count($group['items']),
                    'stock_item_ids' => $stockItemIds,
                    'quick_stock_out_line_id' => (int) $quickLine->id,
                    'quick_stock_out_line_item_ids' => $quickLineItemIds,
                    'remarks' => $payload['remarks'] ?? null,
                ];
            }

            $stockOutResult = $this->postStockOut->execute([
                'stock_out_number' => $this->documentNumberGenerator->generateStockOutNumber(),
                'idempotency_key' => 'qso-'.Str::uuid()->toString(),
                'stock_out_date' => $payload['qso_date'],
                'customer_id' => $payload['customer_id'],
                'pic_id' => $userId,
                'remarks' => $payload['remarks'] ?? null,
                'quick_stock_out_id' => (int) $quickStockOut->id,
                'lines' => $stockOutLinesPayload,
            ]);

            $quickStockOut->update([
                'stock_out_id' => (int) $stockOutResult['stock_out']->id,
            ]);

            return $quickStockOut->fresh([
                'customer',
                'createdBy',
                'stockOut.saleOrder',
                'lines.product',
                'lines.lineItems.stockItem',
            ]);
        });
    }
}
