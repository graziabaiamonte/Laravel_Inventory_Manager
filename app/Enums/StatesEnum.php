<?php

namespace App\Enums;

use App\Traits\Enums\GetSelectValues;

enum StatesEnum: int
{
    use GetSelectValues;

    case Active = 1;
    case Inactive = 0;

    public function getDescription(): string
    {
        return match ($this) {
            self::Active => 'Attivo',
            self::Inactive => 'Non attivo',
        };
    }
}
