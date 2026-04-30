<?php

namespace App\Models;

use App\Domain\ExceptionsReturns\Enums\RepairStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * @return BelongsTo<StockItem, Repair>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * @return BelongsTo<Customer, Repair>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, Repair>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RepairStatusHistory>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(RepairStatusHistory::class)
            ->orderBy('changed_at', 'asc');
    }
}
