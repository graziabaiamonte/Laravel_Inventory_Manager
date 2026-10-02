<?php

namespace App\Traits\Enums;

use JetBrains\PhpStorm\ArrayShape;

trait GetSelectValues
{
    public static function getJsonValues(): array
    {
        $values = self::Cases();
        $formattedValues = [];

        foreach ($values as $value) {
            $formattedValue = [
                'label' => $value->name,
                'value' => $value->value,
            ];

            if (method_exists($value, 'getDescription')) {
                $formattedValue['description'] = $value->getDescription();
            }

            $formattedValues[] = $formattedValue;
        }

        return $formattedValues;
    }

    #[ArrayShape(['label' => 'string', 'value' => 'string', 'description' => 'string'])]
    public function getJsonValue(): array
    {
        $formattedValue = [
            'label' => $this->name,
            'value' => $this->value,
        ];

        if (method_exists($this, 'getDescription')) {
            $formattedValue['description'] = $this->getDescription();
        }

        return $formattedValue;
    }

    public static function getValues(): array
    {
        $values = self::Cases();
        $enumValues = [];

        foreach ($values as $value) {
            $enumValues[] = $value->value;
        }

        return $enumValues;
    }
}
