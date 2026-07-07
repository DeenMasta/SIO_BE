<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickStockOutLineItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quick_stock_out_line_id',
        'stock_item_id',
        'serial_number_snapshot',
        'stock_out_line_item_id',
    ];

    public function quickStockOutLine(): BelongsTo
    {
        return $this->belongsTo(QuickStockOutLine::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function stockOutLineItem(): BelongsTo
    {
        return $this->belongsTo(StockOutLineItem::class);
    }
}
