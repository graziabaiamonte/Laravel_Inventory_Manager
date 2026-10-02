import { MapByMonth, MapByYear } from '@/types';
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend } from 'chart.js';
import { Bar } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

export const options = {
    responsive: true,
    plugins: {
        legend: {
            position: 'bottom' as const,
        },
    },
    scales: {
        y: {
            ticks: {
                stepSize: 1,
                precision: 0,
            },
        },
    },
};

function getMonthName(month: number) {
    const labels = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
    return labels[month - 1];
}

function getMonthsFromMap(map: MapByMonth) {
    return Object.keys(map).map(month => parseInt(month));
}

function getMonthsNames(months: number[]) {
    return months.map(month => getMonthName(month));
}

function mergeMonthsNumbers(months1: number[], months2: number[]) {
    return [...new Set([...months1, ...months2])].sort((a, b) => a - b);
}

function getLabels(months: number[]) {
    const labels = getMonthsNames(months);
    return labels;
}

function mapValuesToMonths(map: MapByMonth, months: number[]) {
    return months.map(month => map[month] || 0);
}

const hasProperties = (obj: object) => {
    return Object.keys(obj).length > 0;
};

export default function VerticalBarChart({
    width = '100%',
    height = '100%',
    currentYear = new Date().getFullYear(),
    items,
}: {
    width?: string;
    height?: string;
    currentYear?: number;
    items: MapByYear;
}) {
    if (!items || !hasProperties(items))
        return (
            <div className='w-full h-full flex justify-center items-center'>
                <p>Non ci sono dati al momento disponibili</p>
            </div>
        );
    const previousYear = currentYear - 1;
    const itemsCopy = { ...items };
    if (!itemsCopy[previousYear]) {
        itemsCopy[previousYear] = {};
    }
    const months = mergeMonthsNumbers(
        getMonthsFromMap(itemsCopy[currentYear]),
        getMonthsFromMap(itemsCopy[previousYear]),
    );
    const data = {
        labels: getLabels(months),
        datasets: [
            {
                label: previousYear.toString(),
                data: mapValuesToMonths(itemsCopy[previousYear], months),
                backgroundColor: 'rgba(255, 99, 132, 0.5)',
            },
            {
                label: currentYear.toString(),
                data: mapValuesToMonths(itemsCopy[currentYear], months),
                backgroundColor: 'rgba(53, 162, 235, 0.5)',
            },
        ],
    };
    return <Bar options={options} data={data} width={width} height={height} />;
}
