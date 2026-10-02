// DateInput - A single date picker component for form inputs
import { useState } from 'react';
import { DateValueType } from 'react-tailwindcss-datepicker/dist/types';
import Datepicker from 'react-tailwindcss-datepicker';
import { Label } from '@/Components/ui/label';
import InputError from '@/Components/InputError';

/**
 * DateInput Component
 * Used for selecting a single date in form contexts
 * Key differences from DateRangeInput:
 * - Handles single date selection vs date range
 * - Uses initialDate pattern for uncontrolled state
 * - Returns empty string when cleared vs null
 * - Includes error handling for forms
 * - Different styling for form context
 * - Configurable date format
 */
interface DateInputProps {
    label?: string;
    error?: string;
    initialDate?: string | null;
    onDateChange: (date: string) => void;
    displayFormat?: string;
}

export default function DateInput({
    label,
    error,
    initialDate = null,
    onDateChange,
    displayFormat = 'DD/MM/YYYY',
}: DateInputProps) {
    // Single date state management
    const [selectedDate, setSelectedDate] = useState<DateValueType>(() => ({
        startDate: initialDate ? new Date(initialDate) : null,
        endDate: initialDate ? new Date(initialDate) : null,
    }));

    const handleChange = (value: DateValueType) => {
        setSelectedDate(value);
        onDateChange(value?.startDate ? new Date(value.startDate).toISOString().split('T')[0] : '');
    };

    return (
        <div>
            {label && <Label className='block text-sm font-medium leading-6 text-gray-900 mb-2'>{label}</Label>}
            <div className='mt-2'>
                <Datepicker
                    asSingle
                    useRange={false}
                    displayFormat={displayFormat}
                    value={selectedDate}
                    onChange={handleChange}
                    inputClassName='flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50'
                    containerClassName='relative [&>button]:top-0'
                />
            </div>
            <div className='h-[20px]'>
                <InputError message={error} />
            </div>
        </div>
    );
}
