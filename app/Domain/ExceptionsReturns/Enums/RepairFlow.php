<?php

namespace App\Domain\ExceptionsReturns\Enums;

enum RepairFlow: string
{
    case Internal = 'INTERNAL';
    case Customer = 'CUSTOMER';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
