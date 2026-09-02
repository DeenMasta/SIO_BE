<?php

namespace App\Application\PurchasingInbound\PurchaseOrders;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PurchaseOrderProductSupplierValidator
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function validate(int $supplierId, array $lines): void
    {
        $productIds = collect($lines)
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        /** @var Collection<int, Product> $products */
        $products = Product::query()
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get(['id', 'product_code', 'product_name', 'supplier_id']);

        $invalidProducts = $products
            ->filter(static fn (Product $product): bool => (int) $product->supplier_id !== $supplierId)
            ->map(static fn (Product $product): string => sprintf(
                '%s (%s)',
                $product->product_name,
                $product->product_code,
            ))
            ->values();

        if ($invalidProducts->isEmpty()) {
            return;
        }

        throw ValidationException::withMessages([
            'lines' => [
                'Every product on a purchase order must belong to the selected supplier. Invalid product(s): '
                .$invalidProducts->implode(', '),
            ],
        ]);
    }
}
