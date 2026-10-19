<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\PurchaseOrderRepository;
use App\Domain\PurchasingInbound\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EloquentPurchaseOrderRepository implements PurchaseOrderRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = PurchaseOrder::query()
            ->with('lines.product')
            ->orderByDesc('po_date')
            ->orderByDesc('po_number');

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('po_number', 'like', '%'.$search.'%')
                    ->orWhereHas('supplier', function ($supplierQuery) use ($search): void {
                        $supplierQuery->where('supplier_name', 'like', '%'.$search.'%')
                            ->orWhere('supplier_code', 'like', '%'.$search.'%');
                    });
            });
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('po_date', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('po_date', '<=', (string) $filters['date_to']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->paginate($perPage);
    }

    public function findOrFail(int $id): PurchaseOrder
    {
        return PurchaseOrder::query()->with('lines.product')->findOrFail($id);
    }

    public function createWithLines(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data): PurchaseOrder {
            $lines = $data['lines'];
            unset($data['lines']);

            $purchaseOrder = PurchaseOrder::query()->create($data);

            foreach ($lines as $line) {
                $line['subtotal'] = (float) $line['ordered_qty'] * (float) $line['unit_price'];
                $line['received_qty'] = 0;
                $purchaseOrder->lines()->create($line);
            }

            return $purchaseOrder->fresh('lines.product');
        });
    }

    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $data): PurchaseOrder {
            $lines = $data['lines'];
            unset($data['lines']);

            $purchaseOrder->update($data);

            if ($purchaseOrder->status === PurchaseOrderStatus::Partial) {
                return $this->updatePartialLines($purchaseOrder, $lines);
            }

            $purchaseOrder->lines()->delete();

            foreach ($lines as $line) {
                unset($line['id']);
                $line['subtotal'] = (float) $line['ordered_qty'] * (float) $line['unit_price'];
                $line['received_qty'] = 0;
                $purchaseOrder->lines()->create($line);
            }

            return $purchaseOrder->fresh('lines.product');
        });
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function updatePartialLines(PurchaseOrder $purchaseOrder, array $lines): PurchaseOrder
    {
        $existingLines = $purchaseOrder->lines()->get()->keyBy('id');
        $submittedLineIds = [];

        foreach ($lines as $line) {
            $lineId = (int) ($line['id'] ?? 0);
            unset($line['id']);
            $line['subtotal'] = (float) $line['ordered_qty'] * (float) $line['unit_price'];

            if ($lineId === 0) {
                $line['received_qty'] = 0;
                $purchaseOrder->lines()->create($line);

                continue;
            }

            $existingLine = $existingLines->get($lineId);
            if ($existingLine === null) {
                continue;
            }

            $existingLine->update($line);
            $submittedLineIds[] = $lineId;
        }

        $removedLineIds = array_values(array_diff(
            $existingLines->keys()->map(static fn (mixed $id): int => (int) $id)->all(),
            $submittedLineIds,
        ));

        if ($removedLineIds !== []) {
            $purchaseOrder->lines()
                ->whereIn('id', $removedLineIds)
                ->delete();
        }

        return $purchaseOrder->fresh('lines.product');
    }

    public function delete(PurchaseOrder $purchaseOrder): void
    {
        $purchaseOrder->delete();
    }
}
