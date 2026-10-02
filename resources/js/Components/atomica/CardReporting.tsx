import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { TrendingDown, TrendingUp, MoveRight } from 'lucide-react';

function getTrend(percentage: number) {
    if (percentage > 0) {
        return <TrendingUp size={25} className='inline-block mr-1 text-green-500' />;
    } else if (percentage < 0) {
        return <TrendingDown size={25} className='inline-block mr-1 text-red-500' />;
    } else {
        return <MoveRight size={25} className='inline-block mr-1 text-gray-500' />;
    }
}

export const CardReporting = ({
    title,
    value,
    percentage,
    symbol,
}: {
    title: string;
    value: number;
    percentage: number;
    symbol?: string;
}) => {
    const formattedValue = value % 1 !== 0 ? value.toFixed(2) : value.toString();
    const formattedPercentage = percentage % 1 !== 0 ? percentage.toFixed(2) : percentage.toString();

    return (
        <Card className='w-full'>
            <CardHeader>
                <CardTitle className='text-lg'>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <CardDescription className='text-5xl'>
                    {symbol}
                    {formattedValue}
                </CardDescription>
            </CardContent>
            <CardFooter>
                <CardDescription className='align-middle'>
                    {getTrend(percentage)} {formattedPercentage}% Anno Precedente
                </CardDescription>
            </CardFooter>
        </Card>
    );
};
