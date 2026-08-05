<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerExchangeLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_exchange_id',
        'customer_return_line_id',
        'replacement_product_id',
        'qty',
        'sale_order_line_id',
        'replacement_stock_out_line_id',
        'remarks',
    ];

    protected function casts(): array
    {
        return ['qty' => 'integer'];
    }

    public function customerExchange(): BelongsTo
    {
        return $this->belongsTo(CustomerExchange::class);
    }

    public function customerReturnLine(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnLine::class);
    }

    public function replacementProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'replacement_product_id');
    }

    public function saleOrderLine(): BelongsTo
    {
        return $this->belongsTo(SaleOrderLine::class);
    }

    public function replacementStockOutLine(): BelongsTo
    {
        return $this->belongsTo(StockOutLine::class, 'replacement_stock_out_line_id');
    }
}
