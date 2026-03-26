<?php

namespace App\Models;

use App\Domain\InventoryCore\Enums\MovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_datetime',
        'product_id',
        'stock_item_id',
        'movement_type',
        'reference_table',
        'reference_id',
        'qty_in',
        'qty_out',
        'from_status',
        'to_status',
        'performed_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'movement_datetime' => 'datetime',
            'movement_type' => MovementType::class,
            'qty_in' => 'integer',
            'qty_out' => 'integer',
        ];
    }
}
