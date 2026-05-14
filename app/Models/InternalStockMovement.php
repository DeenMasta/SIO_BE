<?php

namespace App\Models;

use App\Domain\InventoryCore\Enums\InternalStockMovementDirection;
use App\Domain\InventoryCore\Enums\InternalStockMovementPurpose;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalStockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'movement_number',
        'movement_date',
        'movement_direction',
        'purpose',
        'original_movement_id',
        'status',
        'remarks',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'movement_direction' => InternalStockMovementDirection::class,
            'purpose' => InternalStockMovementPurpose::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InternalStockMovementLine::class);
    }

    public function originalMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_movement_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
