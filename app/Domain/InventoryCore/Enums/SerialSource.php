<?php

namespace App\Domain\InventoryCore\Enums;

enum SerialSource: string
{
    case Factory = 'FACTORY';
    case Generated = 'GENERATED';
}
