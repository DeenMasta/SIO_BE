<?php

namespace App\Models;

use App\Domain\QcOutbound\Enums\QcResult;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QcTransactionLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'qc_transaction_id',
        'stock_in_line_id',
        'product_id',
        'stock_item_id',
        'qc_result',
        'qty_pass',
        'qty_fail',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'qc_result' => QcResult::class,
            'qty_pass' => 'integer',
            'qty_fail' => 'integer',
        ];
    }
}
