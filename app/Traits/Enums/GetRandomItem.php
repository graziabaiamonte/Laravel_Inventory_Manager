<?php

namespace App\Traits\Enums;

trait GetRandomItem
{
    public static function getRandomItem(): self
    {
        return self::cases()[rand(0, count(self::cases()) - 1)];
    }
}
