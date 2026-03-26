<?php

namespace App\Domain\MasterData\Enums;

enum ProductType: string
{
    case Device = 'DEVICE';
    case Accessory = 'ACCESSORY';
    case Consumable = 'CONSUMABLE';
}
