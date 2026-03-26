<?php

namespace App\Domain\ExceptionsReturns\Enums;

enum ExceptionReason: string
{
    case PhysicalDamage = 'PHYSICAL_DAMAGE';
    case FunctionalIssue = 'FUNCTIONAL_ISSUE';
    case WrongItemDelivered = 'WRONG_ITEM_DELIVERED';
    case IncompleteAccessories = 'INCOMPLETE_ACCESSORIES';
    case CosmeticDefect = 'COSMETIC_DEFECT';
    case WarrantyClaim = 'WARRANTY_CLAIM';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
