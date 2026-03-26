<?php

namespace App\Application\Support;

use App\Models\StockItem;
use Carbon\CarbonImmutable;

class SerialNumberGenerator
{
    public function generate(string $productCode): string
    {
        $date = CarbonImmutable::now()->format('Ymd');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $running = str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $serial = strtoupper($productCode).'-'.$date.'-'.$running;

            if (! StockItem::query()->where('serial_number', $serial)->exists()) {
                return $serial;
            }
        }

        throw new \RuntimeException('Unable to generate unique serial number.');
    }
}
