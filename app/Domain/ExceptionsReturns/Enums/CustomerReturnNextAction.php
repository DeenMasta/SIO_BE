<?php

namespace App\Domain\ExceptionsReturns\Enums;

enum CustomerReturnNextAction: string
{
    case Restock = 'RESTOCK';
    case Repair = 'REPAIR';
    case Replace = 'REPLACE';
    case Scrap = 'SCRAP';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
