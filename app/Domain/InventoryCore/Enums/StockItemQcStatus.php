<?php

namespace App\Domain\InventoryCore\Enums;

enum StockItemQcStatus: string
{
    case Pending = 'PENDING';
    case Partial = 'PARTIAL';
    case Passed  = 'PASSED';
    case Failed  = 'FAILED';
}
