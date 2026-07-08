<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOutLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_out_id',
        'sale_order_line_id',
        'is_extra',
        'quick_stock_out_line_id',
        'product_id',
        'qty',
        'settled_qty',
        'reversed_qty',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'is_extra' => 'boolean',
            'settled_qty' => 'integer',
            'reversed_qty' => 'integer',
        ];
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(StockOutLineItem::class);
    }

    public function stockOut(): BelongsTo
    {
        return $this->belongsTo(StockOut::class);
    }

    public function saleOrderLine(): BelongsTo
    {
        return $this->belongsTo(SaleOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function quickStockOutLine(): BelongsTo
    {
        return $this->belongsTo(QuickStockOutLine::class);
    }

    public function settledSaleOrderLines(): HasMany
    {
        return $this->hasMany(SaleOrderLine::class, 'source_stock_out_line_id');
    }
}
