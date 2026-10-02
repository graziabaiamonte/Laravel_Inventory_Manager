import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { CardSales } from '@/Components/atomica/CardSales';
import { CardCustomerAlert } from '@/Components/atomica/CardCustomerAlert';
import { PageProps } from '@/types';

interface SalesData {
    day: number;
    month: number;
    range: number;
}

export default function Dashboard({
    sales,
    customersWithoutRecentOrders,
}: PageProps<{ sales: SalesData; customersWithoutRecentOrders: number }>) {
    return (
        <AuthenticatedLayout header={<h2 className='text-xl font-semibold leading-tight text-gray-800'>Dashboard</h2>}>
            <Head title='Dashboard' />
            <div className='flex flex-col justify-between gap-4'>
                <div className='flex flex-col sm:flex-row justify-between gap-4 sm:gap-8 md:gap-12'>
                    <CardCustomerAlert count={customersWithoutRecentOrders} />
                    <CardSales title='Vendite' description='Andamento delle vendite' salesData={sales} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
