<?php

namespace App\Application\Support;

use App\Models\PurchaseOrder;
use App\Models\StockIn;
use Carbon\CarbonImmutable;

class DocumentNumberGenerator
{
    public function generatePurchaseOrderNumber(): string
    {
        return $this->generateNext(
            modelClass: PurchaseOrder::class,
            column: 'po_number',
            prefix: 'PO-'.CarbonImmutable::now()->format('Ymd'),
        );
    }

    public function generateStockInNumber(): string
    {
        return $this->generateNext(
            modelClass: StockIn::class,
            column: 'stock_in_number',
            prefix: 'SIN-'.CarbonImmutable::now()->format('Ymd'),
        );
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    private function generateNext(string $modelClass, string $column, string $prefix): string
    {
        $latest = $modelClass::query()
            ->where($column, 'like', $prefix.'-%')
            ->orderByDesc($column)
            ->value($column);

        $next = 1;
        if (is_string($latest) && preg_match('/^(.*)-(\d{4})$/', $latest, $matches) === 1) {
            $next = ((int) $matches[2]) + 1;
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $prefix.'-'.str_pad((string) ($next + $attempt), 4, '0', STR_PAD_LEFT);

            if (! $modelClass::query()->where($column, $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate unique document number.');
    }
}
