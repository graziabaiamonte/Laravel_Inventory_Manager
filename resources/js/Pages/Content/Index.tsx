import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { CardSales } from '@/Components/atomica/CardSales';
import { CardCustomerAlert } from '@/Components/atomica/CardCustomerAlert';
import { PageProps } from '@/types';

export default function Index() {
    const contentCards = [
        {
            name: 'Formati',
            href: route('format.index'),
            description: 'Gestisci i formati dei dischi',
            icon: '',
        },
        {
            name: 'Artisti',
            href: route('artist.index'),
            description: 'Gestisci gli artisti',
            icon: '',
        },
        {
            name: 'Etichette',
            href: route('label.index'),
            description: 'Gestisci le etichette discografiche',
            icon: '',
        },
        {
            name: 'Clienti',
            href: route('customer.index'),
            description: 'Gestisci i clienti',
            icon: '',
        },
        {
            name: 'Fornitori',
            href: route('supplier.index'),
            description: 'Gestisci i fornitori',
            icon: '',
        },
    ];

    return (
        <AuthenticatedLayout
            header={<h2 className='text-xl font-semibold leading-tight text-gray-800'>Gestione Contenuti</h2>}>
            <Head title='Gestione Contenuti' />
            <div className='py-6'>
                <div className='grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6'>
                    {contentCards.map(card => (
                        <Link
                            key={card.name}
                            href={card.href}
                            className='block p-6 bg-white rounded-lg shadow hover:shadow-lg transition-shadow duration-300 border border-gray-200 hover:border-blue-500'>
                            <div className='flex items-center gap-4'>
                                <div className='text-4xl'>{card.icon}</div>
                                <div>
                                    <h3 className='text-lg font-semibold text-gray-900'>{card.name}</h3>
                                    <p className='text-sm text-gray-600 mt-1'>{card.description}</p>
                                </div>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
