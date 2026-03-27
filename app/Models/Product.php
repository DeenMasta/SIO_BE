<?php

namespace App\Models;

use App\Domain\MasterData\Enums\ProductType;
use App\Domain\MasterData\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_code',
        'product_name',
        'product_type',
        'selling_price',
        'uom',
        'reorder_level',
        'remarks',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'status' => RecordStatus::class,
            'selling_price' => 'decimal:2',
            'reorder_level' => 'integer',
        ];
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(ProductAccessory::class)->orderBy('id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ProductCondition::class)->orderBy('id');
    }
}
