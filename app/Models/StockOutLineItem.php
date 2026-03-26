<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockOutLineItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_out_line_id',
        'stock_item_id',
    ];
}
