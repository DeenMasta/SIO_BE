<?php

namespace App\Domain\PurchasingInbound\Enums;

enum StockInStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Cancelled = 'CANCELLED';
}
