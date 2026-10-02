import { cleanBarcode } from '@/lib/utils';

/**
 * Hook to create a barcode input handler that automatically cleans the value
 * @param setData - Function to set form data (e.g., form.setData from useForm)
 * @param fieldName - The name of the barcode field (default: 'barcode')
 * @returns Event handler for barcode input onChange
 */
export function useCleanBarcodeInput(setData: (key: string, value: string) => void, fieldName: string = 'barcode') {
    return (e: React.ChangeEvent<HTMLInputElement>) => {
        setData(fieldName, cleanBarcode(e.target.value));
    };
}
