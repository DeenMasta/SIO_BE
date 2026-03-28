<?php

namespace App\Domain\PurchasingInbound\Enums;

enum StockInStatus: string
{
    case Received = 'RECEIVED';
    case Posted = 'POSTED';
    case Draft = 'DRAFT';
    case Cancelled = 'CANCELLED';
}
