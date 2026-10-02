<?php

namespace App\Enums;

use App\Traits\Enums\GetSelectValues;

enum LocationTypeEnum: string
{
    use GetSelectValues;

    public const STORE = 'store';

    public const WAREHOUSE = 'warehouse';

    case Store = self::STORE;
    case Warehouse = self::WAREHOUSE;

    public function getDescription(): string
    {
        return match ($this) {
            self::Store => 'Store',
            self::Warehouse => 'Warehouse',
        };
    }
}
