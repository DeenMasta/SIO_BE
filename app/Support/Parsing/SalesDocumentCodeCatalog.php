<?php

namespace App\Support\Parsing;

use App\Models\Package;
use App\Models\Product;

class SalesDocumentCodeCatalog
{
    /**
     * @return array<int, string>
     */
    public function getProductCodes(): array
    {
        return Product::query()
            ->whereNotNull('product_code')
            ->where('product_code', '!=', '')
            ->pluck('product_code')
            ->map(static fn (string $code): string => strtoupper(trim($code)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function getPackageCodes(): array
    {
        return Package::query()
            ->whereNotNull('package_code')
            ->where('package_code', '!=', '')
            ->pluck('package_code')
            ->map(static fn (string $code): string => strtoupper(trim($code)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function getAllCodes(): array
    {
        return array_values(array_unique([
            ...$this->getPackageCodes(),
            ...$this->getProductCodes(),
        ]));
    }
}
