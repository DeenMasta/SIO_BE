<?php

namespace App\Models;

use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Repair extends Model
{
    use HasFactory;

    protected $fillable = [
        'repair_transaction_number',
        'repair_date',
        'stock_item_id',
        'customer_id',
        'issue_description',
        'repair_status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'repair_date' => 'date',
            'repair_status' => RepairStatus::class,
        ];
    }
}
