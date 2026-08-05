<?php

namespace App\Models;

use App\Domain\ExceptionsReturns\Enums\CustomerExchangeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerExchange extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_return_id',
        'sale_order_id',
        'replacement_stock_out_id',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['status' => CustomerExchangeStatus::class];
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function saleOrder(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class);
    }

    public function replacementStockOut(): BelongsTo
    {
        return $this->belongsTo(StockOut::class, 'replacement_stock_out_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerExchangeLine::class);
    }
}
