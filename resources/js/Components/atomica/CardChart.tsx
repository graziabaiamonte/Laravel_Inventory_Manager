import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import VerticalBarChart from './VerticalBarChart';
import Combo from './Utils/Combo';
import { MapByYear } from '@/types';
import { useState } from 'react';

export const CardChart = ({
    title,
    description,
    cardHeight,
    cardWidth,
    items,
}: {
    title: string;
    description: string;
    cardHeight?: string;
    cardWidth?: string;
    items: MapByYear;
}) => {
    const itemKeys = Object.keys(items);
    const [selectedYear, setSelectedYear] = useState(new Date().getFullYear());

    return (
        <Card className={`w-full ${cardHeight} ${cardWidth}`}>
            <CardHeader>
                <CardTitle className='text-2xl'>{title}</CardTitle>
                <CardDescription className='text-lg'>{description}</CardDescription>
                <Combo items={itemKeys} selected={selectedYear} onChange={(year: number) => setSelectedYear(year)} />
            </CardHeader>
            <CardContent>
                <VerticalBarChart height={cardHeight} width={cardWidth} items={items} currentYear={selectedYear} />
            </CardContent>
        </Card>
    );
};
