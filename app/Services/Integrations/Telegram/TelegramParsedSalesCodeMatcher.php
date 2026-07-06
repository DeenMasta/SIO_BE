<?php

namespace App\Services\Integrations\Telegram;

use App\Models\Package;
use App\Models\Product;
use App\Support\Parsing\SalesDocumentCodeCatalog;

class TelegramParsedSalesCodeMatcher
{
    public function __construct(
        private readonly SalesDocumentCodeCatalog $catalog,
    ) {
    }

    /**
     * @param  array<int, string>  $codes
     * @return array{lines:array<int, array<string, mixed>>, unmatched_codes:array<int, string>}
     */
    public function buildSaleOrderLines(array $codes): array
    {
        $allowedProductCodes = $this->catalog->getProductCodes();
        $allowedPackageCodes = $this->catalog->getPackageCodes();

        $normalizedCodes = array_values(array_unique(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $codes,
        )));

        $packageMap = Package::query()
            ->with('products')
            ->whereIn('package_code', array_values(array_intersect($normalizedCodes, $allowedPackageCodes)))
            ->get()
            ->keyBy(fn (Package $package): string => strtoupper((string) $package->package_code));

        $productMap = Product::query()
            ->whereIn('product_code', array_values(array_intersect($normalizedCodes, $allowedProductCodes)))
            ->get()
            ->keyBy(fn (Product $product): string => strtoupper((string) $product->product_code));

        $lines = [];
        $unmatchedCodes = [];

        foreach ($normalizedCodes as $code) {
            if ($packageMap->has($code)) {
                /** @var Package $package */
                $package = $packageMap->get($code);

                foreach ($package->products as $product) {
                    $lines[] = [
                        'product_id' => $product->id,
                        'ordered_qty' => $product->pivot->quantity ?? 1,
                        'unit_price' => $product->selling_price,
                        'is_free' => false,
                        'remarks' => 'From Package: '.$package->package_name,
                    ];
                }

                continue;
            }

            if ($productMap->has($code)) {
                /** @var Product $product */
                $product = $productMap->get($code);
                $lines[] = [
                    'product_id' => $product->id,
                    'ordered_qty' => 1,
                    'unit_price' => $product->selling_price,
                    'is_free' => false,
                    'remarks' => null,
                ];

                continue;
            }

            $unmatchedCodes[] = $code;
        }

        return [
            'lines' => $lines,
            'unmatched_codes' => array_values(array_unique($unmatchedCodes)),
        ];
    }
}
