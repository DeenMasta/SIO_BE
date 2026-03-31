<?php

namespace App\Models;

use App\Domain\InventoryCore\Enums\StockItemQcStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcCheck extends Model
{
    use HasFactory;

    protected $table = 'qc_items';

    protected $fillable = [
        'qc_document_id',
        'stock_item_id',
        'result',
        'checked_at',
        'checked_conditions',
        'checked_accessories',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'result'     => StockItemQcStatus::class,
            'checked_at' => 'datetime',
            'checked_conditions' => 'array',
            'checked_accessories' => 'array',
        ];
    }

    /**
     * @return BelongsTo<StockItem, QcCheck>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function qcDocument(): BelongsTo
    {
        return $this->belongsTo(QcDocument::class, 'qc_document_id');
    }
}
