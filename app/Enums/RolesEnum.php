<?php

namespace App\Enums;

use App\Traits\Enums\GetSelectValues;

enum RolesEnum: string
{
    use GetSelectValues;

    public const ADMIN = 'admin';

    public const MANAGER = 'manager';

    public const OPERATOR = 'operator';

    case Admin = self::ADMIN;
    case Manager = self::MANAGER;
    case Operator = self::OPERATOR;

    public function getDescription(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Manager => 'Manager',
            self::Operator => 'Operator',
        };
    }
}
