<?php

namespace App\Domain\MasterData\Enums;

enum RecordStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
