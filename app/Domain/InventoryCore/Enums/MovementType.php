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
    case RepairReturnToCustomer = 'REPAIR_RETURN_TO_CUSTOMER';
    case RepairCancelled = 'REPAIR_CANCELLED';
    case CustomerReturn = 'CUSTOMER_RETURN';
    case CustomerReturnCancelled = 'CUSTOMER_RETURN_CANCELLED';
    case ReturnToSupplier = 'RETURN_TO_SUPPLIER';
    case ReturnToSupplierCancelled = 'RETURN_TO_SUPPLIER_CANCELLED';
    case Adjustment = 'ADJUSTMENT';
}
