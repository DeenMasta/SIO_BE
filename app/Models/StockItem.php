<?php

namespace App\Models;

use App\Domain\InventoryCore\Enums\SerialSource;
use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use App\Domain\InventoryCore\Enums\StockItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'qc_status',
        'is_available',
        'last_movement_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'serial_source'   => SerialSource::class,
            'current_status'  => StockItemStatus::class,
            'qc_status'       => StockItemQcStatus::class,
            'is_available'    => 'boolean',
            'last_movement_at' => 'datetime',
        ];
    }

    public function stockInLine(): BelongsTo
    {
        return $this->belongsTo(StockInLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return HasMany<QcCheck>
     */
    public function qcChecks(): HasMany
    {
        return $this->hasMany(QcCheck::class)->orderBy('checked_at', 'asc');
    }
}
