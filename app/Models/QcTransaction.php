<?php

namespace App\Models;

use App\Domain\QcOutbound\Enums\QcTransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'qc_reference_number',
        'stock_in_id',
        'qc_date',
        'qc_by',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'qc_date' => 'date',
            'status' => QcTransactionStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QcTransactionLine::class);
    }
}
