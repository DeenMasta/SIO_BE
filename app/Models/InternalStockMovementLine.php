<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalStockMovementLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'internal_stock_movement_id',
        'product_id',
        'stock_item_id',
        'qty',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(InternalStockMovement::class, 'internal_stock_movement_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
