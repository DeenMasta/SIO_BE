<?php

namespace App\Application\DTOs;

abstract class DataTransferObject
{
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
