<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Contracts\Repositories\SaleOrderRepository;
use App\Models\SaleOrder;
use App\Models\StockOutLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class EloquentSaleOrderRepository implements SaleOrderRepository
{
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = SaleOrder::query()->with(['customer', 'lines.product']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery->where('so_number', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', function ($customerQuery) use ($search): void {
                        $customerQuery->where('customer_name', 'like', '%'.$search.'%');
                    });
            });
        }

        return $query
            ->orderByDesc('so_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findOrFail(int $id): SaleOrder
    {
        return SaleOrder::query()->with('lines.product')->findOrFail($id);
    }

    public function createWithLines(array $data): SaleOrder
    {
        $lines = $data['lines'];
        unset($data['lines']);

        $saleOrder = SaleOrder::query()->create($data);

        foreach ($lines as $line) {
            $saleOrder->lines()->create($this->normalizeLine($line));
        }

        return $saleOrder->fresh('lines.product');
    }

    public function update(SaleOrder $so, array $data): SaleOrder
    {
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        $so->update($data);

        if ($lines !== null) {
            $this->syncLines($so, $lines);
        }

        return $so->fresh('lines.product');
    }

    public function appendLines(SaleOrder $so, array $lines): SaleOrder
    {
        foreach ($lines as $line) {
            $so->lines()->create($this->normalizeLine($line));
        }

        return $so->fresh('lines.product');
    }

    public function delete(SaleOrder $so): void
    {
        $so->delete();
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizeLine(array $line): array
    {
        $isFree = filter_var($line['is_free'] ?? false, FILTER_VALIDATE_BOOL);
        $unitPrice = $isFree ? 0.0 : (float) $line['unit_price'];

        $line['is_free'] = $isFree;
        $line['unit_price'] = $unitPrice;
        $line['subtotal'] = (float) $line['ordered_qty'] * $unitPrice;
        $line['fulfilled_qty'] = 0;

        return $line;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(SaleOrder $so, array $lines): void
    {
        $existingLines = $so->lines()->orderBy('id')->get();

        if ($existingLines->isEmpty()) {
            foreach ($lines as $line) {
                $so->lines()->create($this->normalizeLine($line));
            }

            return;
        }

        $incoming = $lines;
        $hasLineIds = collect($incoming)->contains(
            fn (array $line): bool => array_key_exists('id', $line) && $line['id'] !== null
        );

        // Backward compatibility: when no IDs are provided but line counts match,
        // map incoming lines by position so existing line identities are preserved.
        if (! $hasLineIds && count($incoming) === $existingLines->count()) {
            foreach ($incoming as $index => $line) {
                $incoming[$index]['id'] = (int) $existingLines[$index]->id;
            }
        }

        $existingById = $existingLines->keyBy('id');
        $existingIds = $existingById->keys()->map(fn ($id): int => (int) $id)->all();

        $referencedIds = StockOutLine::whereIn('sale_order_line_id', $existingIds, 'and', false)
            ->pluck('sale_order_line_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $referencedLookup = array_fill_keys($referencedIds, true);

        $keptIds = [];

        foreach ($incoming as $line) {
            $lineId = isset($line['id']) ? (int) $line['id'] : null;

            if ($lineId !== null) {
                $existingLine = $existingById->get($lineId);

                if (! $existingLine) {
                    throw ValidationException::withMessages([
                        'lines' => ['One or more line IDs do not belong to this sales order.'],
                    ]);
                }

                $normalized = $this->normalizeLine($line);
                $fulfilledQty = (int) $existingLine->fulfilled_qty;

                if (($referencedLookup[$lineId] ?? false) && (int) $normalized['product_id'] !== (int) $existingLine->product_id) {
                    throw ValidationException::withMessages([
                        'lines' => ['Cannot change product on a line that already has stock-out transactions.'],
                    ]);
                }

                if ((int) $normalized['ordered_qty'] < $fulfilledQty) {
                    throw ValidationException::withMessages([
                        'lines' => [sprintf('Ordered qty cannot be less than fulfilled qty (%d).', $fulfilledQty)],
                    ]);
                }

                $normalized['fulfilled_qty'] = $fulfilledQty;
                $existingLine->update($normalized);

                $keptIds[] = $lineId;

                continue;
            }

            $so->lines()->create($this->normalizeLine($line));
        }

        $idsToDelete = array_values(array_diff($existingIds, $keptIds));

        if ($idsToDelete === []) {
            return;
        }

        $blockedDeleteIds = array_values(array_intersect($idsToDelete, $referencedIds));
        if ($blockedDeleteIds !== []) {
            throw ValidationException::withMessages([
                'lines' => ['Cannot remove sale order lines that already have stock-out transactions. Update existing lines instead.'],
            ]);
        }

        $so->lines()->whereIn('id', $idsToDelete)->delete();
    }
}
