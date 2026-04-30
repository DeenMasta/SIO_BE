<?php

namespace App\Models;

use App\Domain\PurchasingInbound\Enums\StockInStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockIn extends Model
{
    use HasFactory;

    protected $table = 'stock_in';

    protected $fillable = [
        'stock_in_number',
        'stock_in_date',
        'purchase_order_id',
        'supplier_id',
        'stock_in_pic_id',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'stock_in_date' => 'date',
            'status' => StockInStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockInLine::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
