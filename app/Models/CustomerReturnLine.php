<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerReturnLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_return_id',
        'original_stock_out_line_id',
        'product_id',
        'stock_item_id',
        'qty',
        'reason_for_return',
        'condition_on_return',
        'next_action',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
        ];
    }
}
