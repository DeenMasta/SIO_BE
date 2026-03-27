<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCondition extends Model
{
    use HasFactory;

    public const AVAILABLE_CONDITIONS = [
        'Body Condition',
        'Sound Function',
        'Touch Sensitivity',
        'SN Match',
        'Printed Test',
        'Connectivity Check',
        'No Lag',
        'CD - Kick',
        'CD - Key',
        'CD - Sticker',
        'Network Check',
        '3 Times Test',
        'TPR - Amount',
        'Sticker - Amount',
        'Power Indicator',
    ];

    protected $fillable = [
        'product_id',
        'condition_name',
    ];

    /**
     * @return array<int, string>
     */
    public static function availableConditions(): array
    {
        return self::AVAILABLE_CONDITIONS;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
