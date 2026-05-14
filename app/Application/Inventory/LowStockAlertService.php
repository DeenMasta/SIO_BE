<?php

namespace App\Application\Inventory;

use App\Application\Support\UserNotificationService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class LowStockAlertService
{
    public function __construct(
        private readonly InventoryStockQuery $inventoryStockQuery,
        private readonly UserNotificationService $userNotificationService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function reportQuery(array $filters = []): QueryBuilder
    {
        $query = $this->inventoryStockQuery->base();
        $this->inventoryStockQuery->applyFilters($query, $filters);

        return DB::query()->fromSub($query, 'inventory_report');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function lowStockReportQuery(array $filters = []): QueryBuilder
    {
        return $this->reportQuery([
            ...$filters,
            'stock_status' => 'low_stock',
        ])->select([
            'product_id',
            'product_code',
            'product_name',
            'reorder_level',
            DB::raw('qty_available as qty_in_stock'),
            'qty_available',
            DB::raw('CASE WHEN reorder_level > qty_available THEN reorder_level - qty_available ELSE 0 END as shortage_qty'),
            'stock_status',
        ]);
    }

    public function lowStockCount(): int
    {
        return (int) $this->lowStockReportQuery()->count();
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @return array<int, array<string, int|string>>
     */
    public function snapshotForProducts(array $productIds): array
    {
        $ids = $this->normalizeProductIds($productIds);
        if ($ids === []) {
            return [];
        }

        return $this->reportQuery()
            ->whereIn('product_id', $ids)
            ->select([
                'product_id',
                'product_code',
                'product_name',
                'reorder_level',
                'qty_available',
                'stock_status',
            ])
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->product_id => [
                    'product_id' => (int) $row->product_id,
                    'product_code' => (string) $row->product_code,
                    'product_name' => (string) $row->product_name,
                    'reorder_level' => (int) $row->reorder_level,
                    'qty_available' => (int) $row->qty_available,
                    'stock_status' => (string) $row->stock_status,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, int|string>>  $beforeSnapshot
     * @param  array<int, int|string>  $productIds
     */
    public function notifyStatusTransitions(array $beforeSnapshot, array $productIds, ?int $exceptUserId = null): void
    {
        $afterSnapshot = $this->snapshotForProducts($productIds);

        foreach ($afterSnapshot as $productId => $after) {
            $before = $beforeSnapshot[$productId] ?? null;
            if ($before === null) {
                continue;
            }

            $beforeStatus = (string) ($before['stock_status'] ?? '');
            $afterStatus = (string) ($after['stock_status'] ?? '');
            $reorderLevel = (int) ($after['reorder_level'] ?? 0);

            if ($beforeStatus === $afterStatus || $reorderLevel <= 0) {
                continue;
            }

            $payload = [
                'product_id' => (int) $after['product_id'],
                'product_code' => (string) $after['product_code'],
                'product_name' => (string) $after['product_name'],
                'reorder_level' => $reorderLevel,
                'qty_available' => (int) $after['qty_available'],
                'stock_status' => $afterStatus,
                'previous_stock_status' => $beforeStatus,
                'shortage_qty' => max($reorderLevel - (int) $after['qty_available'], 0),
            ];

            if ($afterStatus === 'out_of_stock') {
                $this->userNotificationService->notifyAllActiveUsers(
                    eventType: 'inventory.out-of-stock.triggered',
                    title: 'Out of stock alert',
                    message: sprintf(
                        '%s (%s) is out of stock. Available: %d, reorder level: %d.',
                        $after['product_name'],
                        $after['product_code'],
                        $after['qty_available'],
                        $reorderLevel,
                    ),
                    data: $payload,
                    exceptUserId: $exceptUserId,
                    level: 'warning',
                );

                continue;
            }

            if ($afterStatus === 'low_stock') {
                $this->userNotificationService->notifyAllActiveUsers(
                    eventType: 'inventory.low-stock.triggered',
                    title: 'Low stock alert',
                    message: sprintf(
                        '%s (%s) is below reorder level. Available: %d, reorder level: %d.',
                        $after['product_name'],
                        $after['product_code'],
                        $after['qty_available'],
                        $reorderLevel,
                    ),
                    data: $payload,
                    exceptUserId: $exceptUserId,
                    level: 'warning',
                );

                continue;
            }

            if ($afterStatus === 'in_stock' && in_array($beforeStatus, ['low_stock', 'out_of_stock'], true)) {
                $this->userNotificationService->notifyAllActiveUsers(
                    eventType: 'inventory.low-stock.resolved',
                    title: 'Low stock resolved',
                    message: sprintf(
                        '%s (%s) is back above reorder level. Available: %d, reorder level: %d.',
                        $after['product_name'],
                        $after['product_code'],
                        $after['qty_available'],
                        $reorderLevel,
                    ),
                    data: $payload,
                    exceptUserId: $exceptUserId,
                    level: 'success',
                );
            }
        }
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @return array<int, int>
     */
    private function normalizeProductIds(array $productIds): array
    {
        return collect($productIds)
            ->map(static fn (int|string $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
