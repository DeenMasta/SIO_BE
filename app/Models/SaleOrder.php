<?php

namespace App\Models;

use App\Domain\SalesOutbound\Enums\SaleOrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'so_number',
        'so_date',
        'customer_id',
        'expected_delivery_date',
        'invoice_number',
        'status',
        'created_by',
        'remarks',
        'quick_stock_out_id',
    ];

    protected function casts(): array
    {
        return [
            'so_date' => 'date',
            'expected_delivery_date' => 'date',
            'status' => SaleOrderStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleOrderLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function quickStockOut(): BelongsTo
    {
        return $this->belongsTo(QuickStockOut::class);
    }

    public function convertedFromQuickStockOut(): HasOne
    {
        return $this->hasOne(QuickStockOut::class, 'converted_sale_order_id');
    }

    public function customerExchanges(): HasMany
    {
        return $this->hasMany(CustomerExchange::class);
    }
}
