import * as React from 'react';
import { format } from 'date-fns';
import { Calendar as CalendarIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';
import { Calendar } from '@/Components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
// import {TimeRange} from "@/types";

export function DatePickerWithRange({
    className,
    date,
    locale,
    label,
    mode,
    onChange,
}: {
    className?: string;
    date: any;
    locale?: any;
    label?: string;
    mode?: 'single' | 'multiple' | 'range' | undefined;
    onChange: (e: any) => void;
}) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        id='date'
                        variant={'outline'}
                        className={cn('justify-start text-left font-normal', !date && 'text-muted-foreground')}>
                        <CalendarIcon className='mr-2 h-4 w-4' />
                        {date?.from ? (
                            date.to ? (
                                <>
                                    {(format(date.from as number, 'LLL dd, y'), { locale: locale })} -{' '}
                                    {(format(date.to as number, 'LLL dd, y'), { locale: locale })}
                                </>
                            ) : (
                                format(date.from as number, 'LLL dd, y', { locale: locale })
                            )
                        ) : (
                            <span>{label ?? 'Pick a date'}</span>
                        )}
                    </Button>
                </PopoverTrigger>
                <PopoverContent className='w-auto p-0' align='start'>
                    <Calendar
                        mode={mode ? mode : 'single'}
                        selected={date as any}
                        onSelect={onChange}
                        numberOfMonths={2}
                        locale={locale}
                        required={mode === 'range' ? true : undefined}
                    />
                </PopoverContent>
            </Popover>
        </div>
    );
}
