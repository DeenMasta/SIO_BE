<?php

namespace App\Application\Support;

use App\Domain\InventoryCore\Enums\StockItemStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockBalanceService
{
    public function incrementStatus(int $productId, StockItemStatus $status, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $this->adjustColumn($productId, $this->statusColumn($status), $qty);
    }

    public function decrementStatus(int $productId, StockItemStatus $status, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $this->adjustColumn($productId, $this->statusColumn($status), -$qty);
    }

    public function transferStatus(int $productId, StockItemStatus $from, StockItemStatus $to, int $qty): void
    {
        if ($qty <= 0 || $from === $to) {
            return;
        }

        $this->adjustColumn($productId, $this->statusColumn($from), -$qty);
        $this->adjustColumn($productId, $this->statusColumn($to), $qty);
    }

    private function statusColumn(StockItemStatus $status): string
    {
        return match ($status) {
            StockItemStatus::Received => 'qty_received_pending_qc',
            StockItemStatus::InStock => 'qty_in_stock',
            StockItemStatus::Delivered => 'qty_delivered',
            StockItemStatus::UnderRepair => 'qty_under_repair',
            StockItemStatus::Returned => 'qty_returned',
            StockItemStatus::ReturnedToSupplier => 'qty_returned_to_supplier',
        };
    }

    private function adjustColumn(int $productId, string $column, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $allowedColumns = [
            'qty_received_pending_qc',
            'qty_in_stock',
            'qty_delivered',
            'qty_under_repair',
            'qty_returned',
            'qty_returned_to_supplier',
        ];

        if (! in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported stock balance column.');
        }

        $this->ensureRow($productId);

        if ($delta > 0) {
            DB::table('stock_balances')
                ->where('product_id', $productId)
                ->increment($column, $delta, ['updated_at' => now()]);

            return;
        }

        $amount = abs($delta);

        DB::table('stock_balances')
            ->where('product_id', $productId)
            ->update([
                $column => DB::raw("CASE WHEN {$column} >= {$amount} THEN {$column} - {$amount} ELSE 0 END"),
                'updated_at' => now(),
            ]);
    }

    private function ensureRow(int $productId): void
    {
        $now = now();

        DB::table('stock_balances')->upsert([
            [
                'product_id' => $productId,
                'qty_received_pending_qc' => 0,
                'qty_in_stock' => 0,
                'qty_delivered' => 0,
                'qty_under_repair' => 0,
                'qty_returned' => 0,
                'qty_returned_to_supplier' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['product_id'], ['updated_at']);
    }
}
