<?php

namespace App\Domain\ExceptionsReturns\Enums;

enum ExceptionTransactionStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
    case Cancelled = 'CANCELLED';
}
