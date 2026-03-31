<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcDocument extends Model
{
    use HasFactory;

    protected $table = 'quality_checks';

    protected $fillable = [
        'document_number',
        'date',
        'pic_id',
        'status',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(QcCheck::class);
    }
}
