<?php

namespace App\Application\SalesOutbound\QuickStockOut\UseCases;

use App\Application\QcOutbound\StockOut\UseCases\PostStockOutUseCase;
use App\Application\Support\DocumentNumberGenerator;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use App\Domain\SalesOutbound\Enums\QuickStockOutStatus;
use App\Models\Product;
use App\Models\QuickStockOut;
use App\Models\StockItem;
use Illuminate\Support\Arr;
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
        $legacySerialNumbers = array_values(array_filter(array_map(
            static fn (mixed $serial): string => trim((string) $serial),
            $payload['serial_numbers'] ?? [],
        )));
        $usesLegacySerialPayload = $legacySerialNumbers !== [] && empty($payload['lines']);

        $lines = array_values(array_filter(
            array_map(
                static function (mixed $line): ?array {
                    if (!is_array($line)) {
                        return null;
                    }

                    return [
                        'product_id' => (int) ($line['product_id'] ?? 0),
                        'qty' => (int) ($line['qty'] ?? 0),
                        'remarks' => isset($line['remarks']) ? trim((string) $line['remarks']) : null,
                        'serial_numbers' => array_values(array_filter(array_map(
                            static fn (mixed $serial): string => trim((string) $serial),
                            Arr::wrap($line['serial_numbers'] ?? []),
                        ))),
                    ];
                },
                $payload['lines'] ?? [],
            ),
            static fn (?array $line): bool => $line !== null,
        ));

        if ($lines === [] && $legacySerialNumbers !== []) {
            $lines = [[
                'product_id' => 0,
                'qty' => count($legacySerialNumbers),
                'remarks' => $payload['remarks'] ?? null,
                'serial_numbers' => $legacySerialNumbers,
            ]];
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => ['At least one quick stock out line is required.'],
            ]);
        }

        return DB::transaction(function () use ($payload, $lines, $userId, $usesLegacySerialPayload): QuickStockOut {
            $allSerialNumbers = collect($lines)
                ->flatMap(static fn (array $line): array => $line['serial_numbers'])
                ->values()
                ->all();

            if (count($allSerialNumbers) !== count(array_unique(array_map('strtoupper', $allSerialNumbers)))) {
                throw ValidationException::withMessages([
                    $usesLegacySerialPayload ? 'serial_numbers' : 'lines' => ['Duplicate serial numbers are not allowed in one quick stock out request.'],
                ]);
            }

            $stockItems = collect();
            if ($allSerialNumbers !== []) {
                $stockItems = StockItem::query()
                    ->with('product')
                    ->whereIn('serial_number', $allSerialNumbers)
                    ->where('current_status', StockItemStatus::InStock->value)
                    ->where('is_available', true)
                    ->where('qc_status', StockItemQcStatus::Passed->value)
                    ->withoutUnresolvedMissingItemReport()
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(static fn (StockItem $item): string => strtoupper((string) $item->serial_number));

                $missingSerials = [];
                foreach ($allSerialNumbers as $serialNumber) {
                    if (! $stockItems->has(strtoupper($serialNumber))) {
                        $missingSerials[] = $serialNumber;
                    }
                }

                if ($missingSerials !== []) {
                    throw ValidationException::withMessages([
                        $usesLegacySerialPayload ? 'serial_numbers' : 'lines' => [
                            sprintf(
                                'Some serials are invalid, not currently IN_STOCK, unavailable, or have not passed QC: %s.',
                                implode(', ', $missingSerials),
                            ),
                        ],
                    ]);
                }
            }

            $quickStockOut = QuickStockOut::query()->create([
                'qso_number' => $this->documentNumberGenerator->generateQuickStockOutNumber(),
                'qso_date' => $payload['qso_date'],
                'customer_id' => $payload['customer_id'],
                'status' => QuickStockOutStatus::Pending,
                'remarks' => $payload['remarks'] ?? null,
                'created_by' => $userId,
            ]);

            $stockOutLinesPayload = [];
            foreach ($lines as $index => $line) {
                $resolvedProductId = (int) $line['product_id'];
                $serialNumbers = $line['serial_numbers'];
                $remarks = $line['remarks'] ?: ($payload['remarks'] ?? null);

                if ($serialNumbers !== []) {
                    $lineStockItems = [];
                    foreach ($serialNumbers as $serialNumber) {
                        $stockItem = $stockItems->get(strtoupper($serialNumber));
                        if (! $stockItem instanceof StockItem) {
                            throw ValidationException::withMessages([
                                'lines' => [sprintf('Unable to resolve serial number %s.', $serialNumber)],
                            ]);
                        }

                        if ($resolvedProductId !== 0 && $resolvedProductId !== (int) $stockItem->product_id) {
                            throw ValidationException::withMessages([
                                'lines' => [sprintf('Line %d contains serials from a different product.', $index + 1)],
                            ]);
                        }

                        $resolvedProductId = (int) $stockItem->product_id;
                        $lineStockItems[] = $stockItem;
                    }

                    $product = $lineStockItems[0]->product;
                    if (! $product instanceof Product || ! $product->requiresSerialNumber()) {
                        throw ValidationException::withMessages([
                            'lines' => [sprintf('Line %d serial numbers do not belong to a serialized product.', $index + 1)],
                        ]);
                    }

                    if ($line['qty'] !== count($lineStockItems)) {
                        throw ValidationException::withMessages([
                            'lines' => [sprintf('Line %d qty must match the number of serial numbers.', $index + 1)],
                        ]);
                    }

                    $quickLine = $quickStockOut->lines()->create([
                        'product_id' => $resolvedProductId,
                        'quantity' => count($lineStockItems),
                        'remarks' => $remarks,
                    ]);

                    $quickLineItemIds = [];
                    $stockItemIds = [];

                    foreach ($lineStockItems as $stockItem) {
                        $quickLineItem = $quickLine->lineItems()->create([
                            'stock_item_id' => $stockItem->id,
                            'serial_number_snapshot' => $stockItem->serial_number,
                        ]);

                        $quickLineItemIds[] = (int) $quickLineItem->id;
                        $stockItemIds[] = (int) $stockItem->id;
                    }

                    $stockOutLinesPayload[] = [
                        'product_id' => $resolvedProductId,
                        'qty' => count($lineStockItems),
                        'stock_item_ids' => $stockItemIds,
                        'quick_stock_out_line_id' => (int) $quickLine->id,
                        'quick_stock_out_line_item_ids' => $quickLineItemIds,
                        'remarks' => $remarks,
                    ];

                    continue;
                }

                $product = Product::query()->findOrFail($resolvedProductId);
                if ($product->requiresSerialNumber()) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf('Line %d requires serial numbers before posting quick stock out.', $index + 1)],
                    ]);
                }

                if ($line['qty'] <= 0) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf('Line %d qty must be greater than 0.', $index + 1)],
                    ]);
                }

                $quickLine = $quickStockOut->lines()->create([
                    'product_id' => $resolvedProductId,
                    'quantity' => $line['qty'],
                    'remarks' => $remarks,
                ]);

                $stockOutLinesPayload[] = [
                    'product_id' => $resolvedProductId,
                    'qty' => $line['qty'],
                    'quick_stock_out_line_id' => (int) $quickLine->id,
                    'remarks' => $remarks,
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
