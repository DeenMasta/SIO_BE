<?php

namespace App\Domain\SalesOutbound\Enums;

enum SaleOrderStatus: string
{
    case Draft = 'DRAFT';
    case Confirmed = 'CONFIRMED';
    case Fulfilled = 'FULFILLED';
    case Cancelled = 'CANCELLED';
}
