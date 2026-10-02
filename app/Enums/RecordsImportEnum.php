<?php

namespace App\Enums;

use App\Traits\Enums\GetSelectValues;

enum RecordsImportEnum: int
{
    use GetSelectValues;

    public const DRAFT = 1;

    public const PUBLISH = 0;

    case Draft = self::DRAFT;
    case Publish = self::PUBLISH;

    public function getDescription(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Publish => 'Pubblicato',
        };
    }
}
