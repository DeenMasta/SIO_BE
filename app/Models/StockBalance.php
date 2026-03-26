<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'qty_received_pending_qc',
        'qty_in_stock',
        'qty_delivered',
        'qty_under_repair',
        'qty_returned',
        'qty_returned_to_supplier',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
