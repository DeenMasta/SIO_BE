<?php

namespace App\Models;

use App\Domain\MasterData\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_code',
        'supplier_name',
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
}
