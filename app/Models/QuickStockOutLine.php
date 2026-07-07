<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuickStockOutLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'quick_stock_out_id',
        'product_id',
        'quantity',
        'remarks',
    ];

    public function quickStockOut(): BelongsTo
    {
        return $this->belongsTo(QuickStockOut::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(QuickStockOutLineItem::class);
    }
}
