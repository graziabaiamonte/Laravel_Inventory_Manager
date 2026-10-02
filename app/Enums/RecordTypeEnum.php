<?php

namespace App\Enums;

use App\Traits\Enums\GetSelectValues;

enum RecordTypeEnum: string
{
    use GetSelectValues;

    public const NEW = 'new';

    public const SECONDHAND = 'secondhand';

    case New = self::NEW;
    case SecondHand = self::SECONDHAND;

    public function getDescription(): string
    {
        return match ($this) {
            self::New => 'Nuovo',
            self::SecondHand => 'Usato',
        };
    }
}
