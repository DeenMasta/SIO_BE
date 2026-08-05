<?php

namespace App\Domain\InventoryCore\Enums;

enum MissingItemReportStatus: string
{
    case Open = 'OPEN';
    case Investigating = 'INVESTIGATING';
    case Resolved = 'RESOLVED';
}
