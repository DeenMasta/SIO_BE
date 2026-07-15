<?php

namespace App\Domain\PurchasingInbound\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'DRAFT';
    case Issued = 'ISSUED';
    case Partial = 'PARTIAL';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
