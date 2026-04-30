<?php

namespace App\Http\Resources\Api\MasterData;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $products = $this->whenLoaded('products');
        $totalPrice = 0;
        
        if ($products && is_iterable($products)) {
            foreach ($products as $product) {
                $quantity = $product->pivot->quantity ?? 1;
                $totalPrice += ($product->selling_price * $quantity);
            }
        }

        return [
            'id' => $this->id,
            'package_code' => $this->package_code,
            'package_name' => $this->package_name,
            'description' => $this->description,
            'status' => $this->status?->value ?? $this->status,
            'total_price' => (string) number_format($totalPrice, 2, '.', ''),
            'products' => $this->whenLoaded('products', function () use ($products) {
                return $products->map(fn ($product) => [
                    'product_id' => $product->id,
                    'product_code' => $product->product_code,
                    'product_name' => $product->product_name,
                    'selling_price' => (string) $product->selling_price,
                    'quantity' => $product->pivot->quantity ?? 1,
                ]);
            }),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
