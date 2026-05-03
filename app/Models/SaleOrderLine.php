<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class SaleOrderLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_order_id',
        'product_id',
        'ordered_qty',
        'fulfilled_qty',
        'is_free',
        'unit_price',
        'subtotal',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'integer',
            'fulfilled_qty' => 'integer',
            'is_free' => 'boolean',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function saleOrder(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function dispatchedItems(): HasManyThrough
    {
        return $this->hasManyThrough(StockOutLineItem::class, StockOutLine::class);
    }
}
