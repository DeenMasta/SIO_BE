<?php

namespace App\Models;

use App\Domain\MasterData\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_name',
        'contact_person',
        'phone',
        'email',
        'address',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    public function saleOrders(): HasMany
    {
        return $this->hasMany(SaleOrder::class);
    }
}
