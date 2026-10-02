<?php

namespace App\Enums;

use App\Traits\Enums\GetSelectValues;

enum SaleTypeEnum: int
{
    use GetSelectValues;

    case Shop = 0;
    case Web = 1;

    public function getDescription(): string
    {
        return match ($this) {
            self::Shop => 'Negozio',
            self::Web => 'Web',
        };
    }
}
