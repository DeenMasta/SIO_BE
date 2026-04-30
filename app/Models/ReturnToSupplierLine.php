<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnToSupplierLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_to_supplier_id',
        'product_id',
        'stock_item_id',
        'stock_in_line_id',
        'qty',
        'reason_for_return',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function returnToSupplier(): BelongsTo
    {
        return $this->belongsTo(ReturnToSupplier::class);
    }

    public function stockInLine(): BelongsTo
    {
        return $this->belongsTo(StockInLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
