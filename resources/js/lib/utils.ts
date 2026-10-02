import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

// Not currently used
export function formatDate(input: string | number, locale: string = 'en-US'): string {
    const date = new Date(input);
    return date.toLocaleDateString(locale, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

// Not currently used
export function absoluteUrl(path: string) {
    return `${process.env.NEXT_PUBLIC_APP_URL}${path}`;
}

export function getUpdatedSelection(
    items: Array<{ id: string }>,
    checked: boolean,
    selectedItems: Array<string | number>,
) {
    if (!checked) {
        return [];
    }
    const newSelection: Array<any> = [];
    items.map(item => {
        if (!selectedItems.includes(item.id)) {
            newSelection.push(item.id);
        }
    });
    return newSelection;
}

/**
 * Cleans a barcode by stripping whitespace, dashes, underscores and angle brackets.
 * Letters and digits are preserved; mirrors the backend Helpers::cleanBarcode behaviour.
 */
export function cleanBarcode(barcode: string | null | undefined): string {
    if (!barcode) {
        return '';
    }
    return barcode.replace(/[\s_\-<>]/g, '');
}

/**
 * True when a price is zero (or empty, which the backend stores as 0).
 * Prices travel as decimal strings ("0.00", "12,50") or numbers depending on the input used.
 */
export function isZeroPrice(price: string | number | null | undefined): boolean {
    if (price === null || price === undefined || price === '') {
        return true;
    }
    const value = typeof price === 'number' ? price : parseFloat(String(price).replace(',', '.'));

    return isNaN(value) || value === 0;
}
