<?php

namespace App\Domain\InventoryCore\Enums;

enum StocktakeStatus: string
{
    case Counting = 'COUNTING';
    case Submitted = 'SUBMITTED';
}
