<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AlphanumericBarcode implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * The barcode should only contain alphanumeric characters (letters and digits).
     * Characters like spaces, dashes, underscores, < and > are allowed
     * because they are automatically cleaned before validation.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $barcode = (string) $value;

        if (empty($barcode)) {
            return;
        }

        $cleanedForValidation = preg_replace('/[\s_\-<>]/', '', $barcode);

        if (! ctype_alnum($cleanedForValidation)) {
            $fail('Il campo :attribute deve contenere solo lettere e numeri');
        }
    }
}
