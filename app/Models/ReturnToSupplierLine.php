<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnToSupplierLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_to_supplier_id',
        'product_id',
        'stock_item_id',
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
}
