<?php

namespace App\Domain\InventoryCore\Enums;

enum InternalStockMovementPurpose: string
{
    case Showroom = 'SHOWROOM';
    case InternalUse = 'INTERNAL_USE';
}
