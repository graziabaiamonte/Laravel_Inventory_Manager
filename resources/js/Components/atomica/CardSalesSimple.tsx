import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

interface SalesData {
    day: number;
    month: number;
    range: number;
}

export const CardSalesSimple = ({
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
    const formatNumber = (num: number): string => {
        return new Intl.NumberFormat('it-IT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(num);
    };

    return (
        <Card className='w-full border-0 shadow-none'>
            <CardContent className='p-0'>
                <div className='grid grid-cols-1 md:grid-cols-3 gap-3'>
                    <div className='py-2 px-3 rounded-lg border bg-card'>
                        <h3 className='text-lg font-semibold mb-1 text-sm text-gray-700'>Vendite Giornaliere</h3>
                        <p className='text-2xl leading-none'>
                            {symbol}
                            {formatNumber(salesData.day)}
                        </p>
                    </div>
                    <div className='py-2 px-3 rounded-lg border bg-card'>
                        <h3 className='text-lg font-semibold mb-1 text-sm text-gray-700'>Vendite Mensili</h3>
                        <p className='text-2xl leading-none'>
                            {symbol}
                            {formatNumber(salesData.month)}
                        </p>
                    </div>
                    <div className='py-2 px-3 rounded-lg border bg-card'>
                        <h3 className='text-lg font-semibold mb-1 text-sm text-gray-700'>Vendite Selezionate</h3>
                        <p className='text-2xl leading-none'>
                            {symbol}
                            {formatNumber(salesData.range)}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
};
