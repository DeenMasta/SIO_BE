<?php

namespace App\Domain\QcOutbound\Enums;

enum QcResult: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
}
