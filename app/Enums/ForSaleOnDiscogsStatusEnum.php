<?php

namespace App\Enums;

use App\Traits\Enums\GetSelectValues;

enum ForSaleOnDiscogsStatusEnum: int
{
    use GetSelectValues;

    case ForSale = 1;
    case NotForSale = 0;

    public function getDescription(): string
    {
        return match ($this) {
            self::ForSale => 'In vendita',
            self::NotForSale => 'Non in vendita',
        };
    }
}
