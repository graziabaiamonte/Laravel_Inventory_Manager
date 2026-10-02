<?php

namespace App\Traits;

trait CurrencyHelper
{
    /**
     * Clean a currency value by removing currency symbols and normalizing decimal separators
     *
     * @param  mixed  $value  The value to clean
     * @return string Cleaned numeric string ready for casting to float
     */
    protected function cleanCurrencyValue($value): string
    {
        if ($value === null) {
            return '0';
        }

        // Convert to string if not already
        $stringValue = (string) $value;

        // Remove currency symbols (€, $, £, etc.) and any surrounding whitespace
        $cleaned = preg_replace('/[^\d,.-]/', '', $stringValue);

        // Replace comma with dot for decimal separator
        $cleaned = str_replace(',', '.', $cleaned);

        // Handle potential multiple dots (take the last one as decimal separator)
        $parts = explode('.', $cleaned);
        if (count($parts) > 2) {
            $decimal = array_pop($parts);
            $integer = implode('', $parts);
            $cleaned = $integer.'.'.$decimal;
        }

        return $cleaned;
    }
}
