<?php

namespace App\Enums;

use App\Traits\Enums\GetSelectValues;

enum DiskStatusEnum: int
{
    use GetSelectValues;

    case Mint = 0;
    case NearMint = 1;
    case VeryGoodPlus = 2;
    case VeryGood = 3;
    case Good = 4;
    case Poor = 5;
    case Generic = 6;

    public function getDescription(): string
    {
        return match ($this) {
            self::Mint => 'Mint',
            self::NearMint => 'Near Mint',
            self::VeryGoodPlus => 'Very Good Plus',
            self::VeryGood => 'Very Good',
            self::Good => 'Good, Good Plus',
            self::Poor => 'Poor, Fair',
            self::Generic => 'Generic',
        };
    }

    /**
     * Short condition code shown on printed labels.
     */
    public function getLabelCode(): string
    {
        return match ($this) {
            self::Mint => 'M',
            self::NearMint => 'NM',
            self::VeryGoodPlus => 'VG+',
            self::VeryGood => 'VG',
            self::Good => 'G,G+',
            self::Poor => 'F',
            default => '',
        };
    }

    public static function fromDescription(string $description): self
    {
        foreach (self::cases() as $case) {
            if ($case->getDescription() === $description) {
                return $case;
            }
        }

        return self::Mint;
    }
}
