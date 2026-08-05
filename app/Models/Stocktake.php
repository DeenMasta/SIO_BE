<?php

namespace App\Models;

use App\Domain\InventoryCore\Enums\StocktakeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stocktake extends Model
{
    use HasFactory;

    protected $fillable = ['stocktake_number', 'stocktake_date', 'status', 'created_by', 'submitted_by', 'submitted_at', 'remarks'];

    protected function casts(): array
    {
        return ['stocktake_date' => 'date', 'submitted_at' => 'datetime', 'status' => StocktakeStatus::class];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    public function missingReports(): HasMany
    {
        return $this->hasMany(MissingItemReport::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
