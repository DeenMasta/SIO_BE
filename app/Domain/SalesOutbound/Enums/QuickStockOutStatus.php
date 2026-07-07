<?php

namespace App\Domain\SalesOutbound\Enums;

enum QuickStockOutStatus: string
{
    case Pending = 'PENDING';
    case Converted = 'CONVERTED';
}
