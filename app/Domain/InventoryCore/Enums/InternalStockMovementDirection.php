<?php

namespace App\Domain\InventoryCore\Enums;

enum InternalStockMovementDirection: string
{
    case Out = 'OUT';
    case Return = 'RETURN';
}
