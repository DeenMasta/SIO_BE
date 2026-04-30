<?php

namespace App\Models;

use App\Domain\MasterData\Enums\ProductType;
use App\Domain\MasterData\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_code',
        'product_name',
        'product_type',
        'requires_serial_number',
        'supplier_id',
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
            'requires_serial_number' => 'boolean',
            'status' => RecordStatus::class,
            'selling_price' => 'decimal:2',
            'reorder_level' => 'integer',
        ];
    }

    public function requiresSerialNumber(): bool
    {
        return (bool) $this->requires_serial_number;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(ProductAccessory::class)->orderBy('id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ProductCondition::class)->orderBy('id');
    }

    public function packages(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Package::class)
                    ->withPivot('quantity')
                    ->withTimestamps();
    }
}
