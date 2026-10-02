import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { useState } from 'react';
import DateRangeInput from '@/Components/atomica/Forms/DateRangeInput';
import axios from 'axios';
import { router } from '@inertiajs/react';

interface SalesData {
    day: number;
    month: number;
    range: number;
}

export const CardSales = ({
    title,
    description,
    salesData,
    symbol = '€ ',
}: {
    title: string;
    description?: string;
    salesData: SalesData;
    symbol?: string;
}) => {
    const [rangeTotal, setRangeTotal] = useState<number>(salesData?.range || 0);

    const formatNumber = (num: number): string => {
        return new Intl.NumberFormat('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(num);
    };

    const handleDateRangeChange = async (dates: { startDate: string; endDate: string } | null) => {
        if (!dates) {
            setRangeTotal(0);
            return;
        }

        const response = await axios.get(route('sale.summary'), {
            params: { dateRange: dates },
            headers: {
                'Content-Type': 'application/json',
            },
        });

        setRangeTotal(response.data.range);
    };

    return (
        <Card className='w-full'>
            <CardHeader>
                <CardTitle className='text-2xl'>{title}</CardTitle>
                <CardDescription className='text-lg'>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className='grid grid-cols-1 md:grid-cols-2 gap-6'>
                    <div className='p-4 rounded-lg border bg-card'>
                        <h3 className='text-lg font-semibold mb-2'>Vendite Giornaliere</h3>
                        <p className='text-3xl'>
                            {symbol}
                            {formatNumber(salesData.day)}
                        </p>
                    </div>
                    <div className='p-4 rounded-lg border bg-card'>
                        <h3 className='text-lg font-semibold mb-2'>Vendite Mensili</h3>
                        <p className='text-3xl'>
                            {symbol}
                            {formatNumber(salesData.month)}
                        </p>
                    </div>
                    <div className='p-4 rounded-lg border bg-card md:col-span-2'>
                        <h3 className='text-lg font-semibold mb-2'>Vendite Periodo</h3>
                        <DateRangeInput onFilterChange={handleDateRangeChange} />
                        <p className='text-3xl mt-2'>
                            {symbol}
                            {formatNumber(rangeTotal)}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
};
