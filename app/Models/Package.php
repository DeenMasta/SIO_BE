<?php

namespace App\Models;

use App\Domain\MasterData\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_code',
        'package_name',
        'description',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
                    ->withPivot('quantity')
                    ->withTimestamps();
    }
}
