<?php

namespace App\Domain\InventoryCore\Enums;

enum MovementType: string
{
    case StockIn = 'STOCK_IN';
    case QcPass = 'QC_PASS';
    case QcFail = 'QC_FAIL';
    case StockOut = 'STOCK_OUT';
    case RepairIn = 'REPAIR_IN';
    case RepairOut = 'REPAIR_OUT';
    case CustomerReturn = 'CUSTOMER_RETURN';
    case ReturnToSupplier = 'RETURN_TO_SUPPLIER';
    case Adjustment = 'ADJUSTMENT';
}
