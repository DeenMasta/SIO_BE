<?php

namespace App\Domain\ExceptionsReturns\Enums;

enum RepairStatus: string
{
    case Open = 'OPEN';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
