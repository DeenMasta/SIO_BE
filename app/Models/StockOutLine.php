<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOutLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_out_id',
        'product_id',
        'qty',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(StockOutLineItem::class);
    }
}
