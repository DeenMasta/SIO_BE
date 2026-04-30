<?php

namespace App\Models;

use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnToSupplier extends Model
{
    use HasFactory;

    protected $table = 'return_to_supplier';

    protected $fillable = [
        'rts_transaction_number',
        'supplier_id',
        'stock_in_id',
        'return_date',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'status' => ExceptionTransactionStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnToSupplierLine::class);
    }

    public function stockIn(): BelongsTo
    {
        return $this->belongsTo(StockIn::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
