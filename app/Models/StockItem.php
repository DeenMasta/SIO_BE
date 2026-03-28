<?php

namespace App\Models;

use App\Domain\InventoryCore\Enums\SerialSource;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'stock_in_line_id',
        'serial_number',
        'factory_serial_number',
        'serial_source',
        'current_status',
        'received_condition',
        'is_available',
        'last_movement_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'serial_source' => SerialSource::class,
            'current_status' => StockItemStatus::class,
            'is_available' => 'boolean',
            'last_movement_at' => 'datetime',
        ];
    }

    public function stockInLine(): BelongsTo
    {
        return $this->belongsTo(StockInLine::class);
    }
}
