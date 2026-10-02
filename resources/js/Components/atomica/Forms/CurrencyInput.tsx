import { InputHTMLAttributes, useState, useEffect } from 'react';
import CurrencyInputField from 'react-currency-input-field';
import type { CurrencyInputOnChangeValues } from 'react-currency-input-field';
import InputError from '@/Components/InputError';
import { Label } from '@/Components/ui/label';
import { twMerge } from 'tailwind-merge';

interface CurrencyInputProps extends InputHTMLAttributes<HTMLInputElement> {
    error?: string;
    label?: string;
    currency?: string;
    required?: boolean;
}

export default function CurrencyInput({
    error,
    label,
    onChange,
    value,
    name,
    id,
    required = false,
    disabled,
}: CurrencyInputProps) {
    // Display value uses Italian format with comma as decimal separator
    // Backend always sends/receives dot format (e.g., "150.00")
    const [displayValue, setDisplayValue] = useState<string | undefined>(
        value ? String(value).replace('.', ',') : undefined,
    );

    // Update display value when external value changes from backend
    useEffect(() => {
        // Convert backend dot format to Italian comma format for display
        // Backend: "150.00" → Display: "150,00"
        setDisplayValue(value ? String(value).replace('.', ',') : undefined);
    }, [value]);

    const handleChange = (inputValue: string | undefined, inputName?: string, values?: CurrencyInputOnChangeValues) => {
        // Update the display value immediately for responsive UI
        setDisplayValue(inputValue);

        // Convert Italian format (comma) to backend format (dot) while preserving typing flow
        // During typing: "1" → "14" → "140" → "140." → "140.0" → "140.00"
        // We send intermediate values as-is to prevent cursor jumping
        // Only normalize to exactly 2 decimals when user completes typing both decimal digits
        let numericValue = '';
        if (values?.value) {
            // values.value contains the raw number with comma (e.g., "140,00")
            // Replace comma with dot for backend compatibility
            numericValue = values.value.replace(',', '.');

            // Only apply toFixed(2) normalization when we have exactly 2 decimal places
            // This preserves intermediate states like "140.", "140.0" to prevent cursor jumping
            const decimalIndex = numericValue.indexOf('.');
            if (decimalIndex !== -1) {
                const decimalPart = numericValue.substring(decimalIndex + 1);
                if (decimalPart.length === 2) {
                    // User has typed both decimal digits - normalize to ensure proper format
                    const floatVal = parseFloat(numericValue);
                    if (!isNaN(floatVal)) {
                        numericValue = floatVal.toFixed(2);
                    }
                }
                // Otherwise (0 or 1 decimal digit), send as-is to allow smooth typing
            }
            // No decimal point yet - send as-is (e.g., "140")
        }

        const e = {
            target: {
                id: id,
                name: inputName || name,
                value: numericValue,
            },
        };

        onChange?.(e as React.ChangeEvent<HTMLInputElement>);
    };

    return (
        <div>
            {label && (
                <Label className='block text-sm font-medium text-gray-900'>
                    {label} {required && '*'}
                </Label>
            )}
            <div className='mt-2'>
                <CurrencyInputField
                    id={id}
                    name={name}
                    value={displayValue}
                    disabled={disabled}
                    decimalsLimit={2}
                    decimalScale={2}
                    decimalSeparator=','
                    groupSeparator='.'
                    disableGroupSeparators={true}
                    // Italian locale configuration: comma as decimal separator, no group separators.
                    // values.value holds the raw string with the current separators and is converted
                    // to dot format for the backend in handleChange() above.
                    // Keep price values as strings in table components (not parseFloat): parseFloat("140.00")
                    // gives 140, which the backend Money cast reads as cents (€1.40) instead of euros (€140.00).
                    onValueChange={handleChange}
                    prefix='€ '
                    className={twMerge(
                        'min-w-24 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 text-gray-900',
                    )}
                />
            </div>
            <div className='h-[20px]'>
                <InputError message={error} />
            </div>
        </div>
    );
}
