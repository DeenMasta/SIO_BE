<?php

namespace App\Domain\QcOutbound\Enums;

enum QcTransactionStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Cancelled = 'CANCELLED';
}
