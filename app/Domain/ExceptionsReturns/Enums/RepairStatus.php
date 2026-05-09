<?php

namespace App\Domain\ExceptionsReturns\Enums;

enum RepairStatus: string
{
    case Open = 'OPEN';
    case InProgress = 'IN_PROGRESS';
    case ReadyToReturn = 'READY_TO_RETURN';
    case Completed = 'COMPLETED';
    case ReturnedToCustomer = 'RETURNED_TO_CUSTOMER';
    case Cancelled = 'CANCELLED';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
