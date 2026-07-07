<?php

namespace App\Models;

use App\Domain\SalesOutbound\Enums\QuickStockOutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QuickStockOut extends Model
{
    use HasFactory;

    protected $fillable = [
        'qso_number',
        'qso_date',
        'customer_id',
        'stock_out_id',
        'converted_sale_order_id',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qso_date' => 'date',
            'status' => QuickStockOutStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuickStockOutLine::class);
    }

    public function stockOut(): BelongsTo
    {
        return $this->belongsTo(StockOut::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function saleOrder(): HasOne
    {
        return $this->hasOne(SaleOrder::class);
    }

    public function convertedSaleOrder(): BelongsTo
    {
        return $this->belongsTo(SaleOrder::class, 'converted_sale_order_id');
    }
}
