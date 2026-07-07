<?php

namespace App\Application\SalesOutbound\QuickStockOut\UseCases;

use App\Application\Support\DocumentNumberGenerator;
use App\Domain\SalesOutbound\Enums\QuickStockOutStatus;
use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use App\Models\QuickStockOut;
use App\Models\SaleOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertQuickStockOutUseCase
{
    public function __construct(
        private readonly DocumentNumberGenerator $documentNumberGenerator,
    ) {
    }

    public function execute(QuickStockOut $quickStockOut, array $payload, int $userId): SaleOrder
    {
        if ($quickStockOut->status !== QuickStockOutStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => ['Only pending quick stock outs can be converted.'],
            ]);
        }

        if ($quickStockOut->stock_out_id === null) {
            throw ValidationException::withMessages([
                'stock_out_id' => ['Quick stock out has no posted stock out record to convert.'],
            ]);
        }

        return DB::transaction(function () use ($quickStockOut, $payload, $userId): SaleOrder {
            $quickStockOut->loadMissing([
                'lines.product',
                'stockOut.lines.lineItems',
            ]);

            $stockOut = $quickStockOut->stockOut;
            if ($stockOut === null) {
                throw ValidationException::withMessages([
                    'stock_out_id' => ['Quick stock out stock out record could not be found.'],
                ]);
            }

            if ($stockOut->sale_order_id !== null) {
                throw ValidationException::withMessages([
                    'sale_order_id' => ['This quick stock out is already linked to a sales order.'],
                ]);
            }

            $saleOrder = SaleOrder::query()->create([
                'so_number' => trim((string) ($payload['so_number'] ?? '')) !== ''
                    ? trim((string) $payload['so_number'])
                    : $this->documentNumberGenerator->generateSaleOrderNumber(),
                'so_date' => $payload['so_date'] ?? $quickStockOut->qso_date,
                'customer_id' => $quickStockOut->customer_id,
                'expected_delivery_date' => $payload['expected_delivery_date'] ?? null,
                'invoice_number' => $payload['invoice_number'] ?? null,
                'status' => SaleOrderStatus::Fulfilled,
                'remarks' => $payload['remarks'] ?? ('Converted from quick stock out '.$quickStockOut->qso_number),
                'created_by' => $userId,
                'quick_stock_out_id' => $quickStockOut->id,
            ]);

            $lineMap = [];
            foreach ($quickStockOut->lines as $quickLine) {
                $unitPrice = (float) ($quickLine->product?->selling_price ?? 0);

                $saleOrderLine = $saleOrder->lines()->create([
                    'product_id' => $quickLine->product_id,
                    'ordered_qty' => $quickLine->quantity,
                    'fulfilled_qty' => $quickLine->quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $unitPrice * (int) $quickLine->quantity,
                    'is_free' => false,
                    'remarks' => $quickLine->remarks ?? null,
                ]);

                $lineMap[(int) $quickLine->id] = (int) $saleOrderLine->id;
            }

            $stockOut->update([
                'sale_order_id' => (int) $saleOrder->id,
            ]);

            foreach ($stockOut->lines as $stockOutLine) {
                $quickLineId = (int) ($stockOutLine->quick_stock_out_line_id ?? 0);
                $saleOrderLineId = $lineMap[$quickLineId] ?? null;
                if ($saleOrderLineId !== null) {
                    $stockOutLine->update([
                        'sale_order_line_id' => $saleOrderLineId,
                    ]);
                }
            }

            $quickStockOut->update([
                'status' => QuickStockOutStatus::Converted,
                'converted_sale_order_id' => (int) $saleOrder->id,
            ]);

            return $saleOrder->fresh([
                'customer',
                'lines.product',
                'lines.dispatchedItems.stockItem',
            ]);
        });
    }
}
