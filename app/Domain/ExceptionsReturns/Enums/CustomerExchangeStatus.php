<?php

namespace App\Domain\ExceptionsReturns\Enums;

enum CustomerExchangeStatus: string
{
    case Pending = 'PENDING';
    case Dispatched = 'DISPATCHED';
    case Cancelled = 'CANCELLED';
}
