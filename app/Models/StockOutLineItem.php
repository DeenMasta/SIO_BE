<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOutLineItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_out_line_id',
        'stock_item_id',
        'settled_sale_order_line_id',
        'reversed_at',
        'quick_stock_out_line_item_id',
    ];

    protected function casts(): array
    {
        return [
            'reversed_at' => 'datetime',
        ];
    }

    public function stockOutLine(): BelongsTo
    {
        return $this->belongsTo(StockOutLine::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function quickStockOutLineItem(): BelongsTo
    {
        return $this->belongsTo(QuickStockOutLineItem::class);
    }

    public function settledSaleOrderLine(): BelongsTo
    {
        return $this->belongsTo(SaleOrderLine::class, 'settled_sale_order_line_id');
    }
}
