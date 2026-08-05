<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StocktakeLine extends Model
{
    use HasFactory;

    protected $fillable = ['stocktake_id', 'product_id', 'expected_qty', 'counted_qty', 'variance_qty', 'remarks'];

    protected function casts(): array
    {
        return ['expected_qty' => 'integer', 'counted_qty' => 'integer', 'variance_qty' => 'integer'];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StocktakeLineItem::class);
    }
}
