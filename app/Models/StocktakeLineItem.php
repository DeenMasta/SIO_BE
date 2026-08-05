<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeLineItem extends Model
{
    use HasFactory;

    protected $fillable = ['stocktake_line_id', 'stock_item_id', 'serial_number', 'is_counted'];

    protected function casts(): array
    {
        return ['is_counted' => 'boolean'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(StocktakeLine::class, 'stocktake_line_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }
}
