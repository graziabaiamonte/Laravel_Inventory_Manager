// DateRangeInput - A date range picker component for filtering data
import { DateValueType } from 'react-tailwindcss-datepicker/dist/types';
import Datepicker from 'react-tailwindcss-datepicker';
import { Label } from '@/Components/ui/label';
import { useEffect, useState } from 'react';

/**
 * DateRangeInput Component
 * Used for selecting a date range (start and end dates) primarily for filtering data
 * Key differences from DateInput:
 * - Handles range selection (two dates) vs single date
 * - Uses filter prop pattern for controlled state
 * - Returns null when cleared vs empty string
 * - No error handling (used in filters)
 * - Different styling for filter context
 */
interface DateRangeInputProps {
    label?: string;
    filter?: {
        startDate?: string;
        endDate?: string;
    };
    onFilterChange: (dates: { startDate: string; endDate: string } | null) => void;
    className?: string;
}

export default function DateRangeInput({ label, filter, onFilterChange, className = '' }: DateRangeInputProps) {
    // Internal state tracks the date range value
    const [value, setValue] = useState<DateValueType>(null);

    // Sync internal state with external filter prop
    useEffect(() => {
        if (filter?.startDate && filter?.endDate) {
            // Parse dates as local dates to avoid timezone issues
            // When date string is "2024-12-01", we want December 1st in local time, not UTC
            const parseLocalDate = (dateStr: string) => {
                const [year, month, day] = dateStr.split('-').map(Number);
                return new Date(year, month - 1, day);
            };

            setValue({
                startDate: parseLocalDate(filter.startDate),
                endDate: parseLocalDate(filter.endDate),
            });
        }
    }, [filter]);

    // Handle date selection and format dates for filter
    const handleChange = (date: DateValueType) => {
        setValue(date);
        if (date?.startDate && date?.endDate) {
            // Format dates as YYYY-MM-DD in local timezone to avoid timezone shifts
            const formatLocalDate = (dateValue: Date | string) => {
                const d = new Date(dateValue);
                const year = d.getFullYear();
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            };

            onFilterChange({
                startDate: formatLocalDate(date.startDate),
                endDate: formatLocalDate(date.endDate),
            });
        } else {
            onFilterChange(null);
        }
    };

    return (
        <div className={className}>
            {label && <Label className='block text-sm font-medium leading-6 text-gray-700'>{label}</Label>}
            <div className='relative mt-2'>
                <Datepicker
                    containerClassName='ld-datepicker relative'
                    inputClassName='flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50'
                    toggleClassName='h-full absolute right-2 top-0 grid place-items-center cursor-pointer text-gray-400'
                    displayFormat='DD/MM/YYYY'
                    value={value}
                    onChange={handleChange}
                />
            </div>
        </div>
    );
}
