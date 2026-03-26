<?php

namespace App\Domain\InventoryCore\Enums;

enum StockItemStatus: string
{
    case Received = 'RECEIVED';
    case InStock = 'IN_STOCK';
    case Delivered = 'DELIVERED';
    case UnderRepair = 'UNDER_REPAIR';
    case ReturnedToSupplier = 'RETURNED_TO_SUPPLIER';
    case Returned = 'RETURNED';
}
