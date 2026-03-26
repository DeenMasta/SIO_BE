<?php

namespace App\Domain\QcOutbound\Enums;

enum StockOutStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Cancelled = 'CANCELLED';
}
