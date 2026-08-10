<?php

namespace App\Console\Commands;

use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Domain\PurchasingInbound\Enums\StockInStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockInLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculatePurchaseOrderReceipts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'purchase-orders:recalculate-receipts
                            {--purchase-order-id=* : Limit the repair to one or more purchase-order IDs}
                            {--dry-run : Show the changes without saving them}';

    /**
     * @var string
     */
    protected $description = 'Recalculate PO received quantities and statuses from posted stock-in lines.';

    public function handle(): int
    {
        $purchaseOrderIds = collect($this->option('purchase-order-id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $dryRun = (bool) $this->option('dry-run');

        $query = PurchaseOrder::query()->orderBy('id');
        if ($purchaseOrderIds->isNotEmpty()) {
            $query->whereIn('id', $purchaseOrderIds);
        }

        $updatedLines = 0;
        $updatedOrders = 0;

        foreach ($query->cursor() as $purchaseOrder) {
            [$lineChanges, $statusChanged] = DB::transaction(function () use ($purchaseOrder, $dryRun): array {
                /** @var PurchaseOrder $lockedPurchaseOrder */
                $lockedPurchaseOrder = PurchaseOrder::query()
                    ->with('lines')
                    ->lockForUpdate()
                    ->findOrFail($purchaseOrder->id);

                $receivedQtyByLineId = $this->receivedQtyByLineId($lockedPurchaseOrder);
                $lineChanges = 0;
                foreach ($lockedPurchaseOrder->lines as $line) {
                    $actualReceivedQty = $receivedQtyByLineId[(int) $line->id] ?? 0;

                    if ((int) $line->received_qty !== $actualReceivedQty) {
                        $this->line(sprintf(
                            'PO %s line %d: received_qty %d -> %d',
                            $lockedPurchaseOrder->po_number,
                            $line->id,
                            $line->received_qty,
                            $actualReceivedQty,
                        ));
                        $lineChanges++;

                        if (! $dryRun) {
                            $line->update(['received_qty' => $actualReceivedQty]);
                        } else {
                            $line->received_qty = $actualReceivedQty;
                        }
                    }
                }

                // A cancelled or draft PO is a deliberate business decision and must
                // not be reopened by this repair command.
                if (in_array($lockedPurchaseOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Cancelled], true)) {
                    return [$lineChanges, false];
                }

                $status = $lockedPurchaseOrder->lines->isNotEmpty() && $lockedPurchaseOrder->lines->every(
                    static fn (PurchaseOrderLine $line): bool => (int) $line->received_qty >= (int) $line->ordered_qty,
                )
                    ? PurchaseOrderStatus::Completed
                    : ($lockedPurchaseOrder->lines->contains(static fn (PurchaseOrderLine $line): bool => (int) $line->received_qty > 0)
                        ? PurchaseOrderStatus::Partial
                        : PurchaseOrderStatus::Issued);

                if ($lockedPurchaseOrder->status !== $status) {
                    $this->line(sprintf(
                        'PO %s: status %s -> %s',
                        $lockedPurchaseOrder->po_number,
                        $lockedPurchaseOrder->status->value,
                        $status->value,
                    ));

                    if (! $dryRun) {
                        $lockedPurchaseOrder->update(['status' => $status]);
                    }

                    return [$lineChanges, true];
                }

                return [$lineChanges, false];
            });

            $updatedLines += $lineChanges;
            $updatedOrders += $statusChanged ? 1 : 0;
        }

        $this->info(sprintf(
            '%s %d purchase order line(s) and %d purchase order status(es).',
            $dryRun ? 'Would update' : 'Updated',
            $updatedLines,
            $updatedOrders,
        ));

        return self::SUCCESS;
    }

    /**
     * Return total received quantities for each PO line from its stock-in records.
     *
     * Current receipts reference purchase_order_line_id directly. Older receipts
     * created before that link existed only have the PO and product relationship,
     * so they are included only when that product appears once on the PO. This
     * avoids guessing which line should receive a quantity when a PO has the same
     * product on multiple lines.
     *
     * @return array<int, int>
     */
    private function receivedQtyByLineId(PurchaseOrder $purchaseOrder): array
    {
        $lines = $purchaseOrder->lines;
        if ($lines->isEmpty()) {
            return [];
        }

        $lineIds = $lines->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $receivedQtyByLineId = array_fill_keys($lineIds, 0);
        $receivedStockInStatuses = [StockInStatus::Received->value, StockInStatus::Posted->value];

        $linkedTotals = StockInLine::query()
            ->join('stock_in', 'stock_in.id', '=', 'stock_in_lines.stock_in_id')
            ->where('stock_in.purchase_order_id', $purchaseOrder->id)
            ->whereIn('stock_in.status', $receivedStockInStatuses)
            ->whereIn('stock_in_lines.purchase_order_line_id', $lineIds)
            ->groupBy('stock_in_lines.purchase_order_line_id')
            ->selectRaw('stock_in_lines.purchase_order_line_id, SUM(stock_in_lines.received_qty) as received_qty')
            ->pluck('received_qty', 'purchase_order_line_id');

        foreach ($linkedTotals as $lineId => $receivedQty) {
            $receivedQtyByLineId[(int) $lineId] = (int) $receivedQty;
        }

        $lineCountByProductId = $lines->countBy('product_id');
        $legacyTotalsByProductId = StockInLine::query()
            ->join('stock_in', 'stock_in.id', '=', 'stock_in_lines.stock_in_id')
            ->where('stock_in.purchase_order_id', $purchaseOrder->id)
            ->whereIn('stock_in.status', $receivedStockInStatuses)
            ->whereNull('stock_in_lines.purchase_order_line_id')
            ->whereIn('stock_in_lines.product_id', $lines->pluck('product_id')->unique()->all())
            ->groupBy('stock_in_lines.product_id')
            ->selectRaw('stock_in_lines.product_id, SUM(stock_in_lines.received_qty) as received_qty')
            ->pluck('received_qty', 'product_id');

        foreach ($lines as $line) {
            if ((int) $lineCountByProductId->get($line->product_id) !== 1) {
                continue;
            }

            $receivedQtyByLineId[(int) $line->id] += (int) ($legacyTotalsByProductId->get($line->product_id) ?? 0);
        }

        return $receivedQtyByLineId;
    }
}
