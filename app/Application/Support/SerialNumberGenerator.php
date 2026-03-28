<?php

namespace App\Application\Support;

use App\Models\StockItem;
use Carbon\CarbonImmutable;

class SerialNumberGenerator
{
    public function generate(string $productCode): string
    {
        $date = CarbonImmutable::now()->format('Ymd');
        $prefix = strtoupper(trim($productCode)).'-'.$date;

        $existingSerials = StockItem::query()
            ->where('serial_number', 'like', $prefix.'-%')
            ->pluck('serial_number');

        $maxRunning = 0;

        foreach ($existingSerials as $existingSerial) {
            $value = (string) $existingSerial;
            if (! str_starts_with($value, $prefix.'-')) {
                continue;
            }

            $suffix = substr($value, strlen($prefix) + 1);
            if (strlen($suffix) !== 4 || ! ctype_digit($suffix)) {
                continue;
            }

            $maxRunning = max($maxRunning, (int) $suffix);
        }

        $nextRunning = $maxRunning + 1;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $running = str_pad((string) $nextRunning, 4, '0', STR_PAD_LEFT);
            $serial = $prefix.'-'.$running;

            if (! StockItem::query()->where('serial_number', $serial)->exists()) {
                return $serial;
            }

            $nextRunning++;
        }

        throw new \RuntimeException('Unable to generate sequential serial number.');
    }
}
