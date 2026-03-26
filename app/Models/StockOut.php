<?php

namespace App\Models;

use App\Domain\QcOutbound\Enums\StockOutStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOut extends Model
{
    use HasFactory;

    protected $table = 'stock_out';

    protected $fillable = [
        'stock_out_number',
        'idempotency_key',
        'stock_out_date',
        'customer_id',
        'invoice_number',
        'pic_id',
        'pick_list_reference',
        'packing_verified',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'stock_out_date' => 'date',
            'packing_verified' => 'boolean',
            'status' => StockOutStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockOutLine::class);
    }
}
