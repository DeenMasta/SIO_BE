<?php

namespace App\Models;

use App\Domain\ExceptionsReturns\Enums\ExceptionTransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_transaction_number',
        'return_date',
        'customer_id',
        'original_invoice_number',
        'original_stock_out_id',
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
        return $this->hasMany(CustomerReturnLine::class);
    }
}
