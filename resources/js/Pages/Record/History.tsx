import Authenticated from '@/Layouts/AuthenticatedLayout';
import { router } from '@inertiajs/react';
import SecondaryButton from '@/Components/SecondaryButton';
import { Record } from '@/types';
import { ArrowLeft, ExternalLink } from 'lucide-react';
import { Button } from '@/Components/ui/button';

interface HistoryItem {
    id: number;
    created_at: string;
    type: 'WholesaleIn' | 'WholesaleOut' | 'Sale';
    supplier_name?: string;
    customer_name?: string;
    location_name?: string;
    remote_customer_name?: string;
    quantity: number;
}

export default function History({ record, history }: { record: Record; history: HistoryItem[] }) {
    const formatDate = (dateString: string) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        });
    };

    return (
        <Authenticated title={`History - ${record.rr_uid}`}>
            <div className='max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8'>
                <div className='mb-6'>
                    <h1 className='text-2xl font-semibold text-gray-900'>History Record: {record.rr_uid}</h1>
                    {record.artist && (
                        <p className='mt-1 text-sm text-gray-600'>
                            {record.artist.name} - {record.title}
                        </p>
                    )}
                </div>

                <div className='bg-white shadow overflow-hidden sm:rounded-lg'>
                    {history.length === 0 ? (
                        <div className='px-4 py-12 text-center text-gray-500'>
                            Nessuno storico disponibile per questo record.
                        </div>
                    ) : (
                        <ul className='divide-y divide-gray-200'>
                            {history.map((item, index) => (
                                <li key={`${item.type}-${item.id}-${index}`} className='px-4 py-4 sm:px-6'>
                                    <div className='flex items-center justify-between'>
                                        <div className='flex-1'>
                                            <div className='flex items-center space-x-3'>
                                                <span
                                                    className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                        item.type === 'WholesaleIn'
                                                            ? 'bg-green-100 text-green-800'
                                                            : item.type === 'WholesaleOut'
                                                              ? 'bg-red-100 text-red-800'
                                                              : 'bg-blue-100 text-blue-800'
                                                    }`}>
                                                    {item.type === 'WholesaleIn'
                                                        ? 'Carico'
                                                        : item.type === 'WholesaleOut'
                                                          ? 'Scarico'
                                                          : 'Vendita'}
                                                </span>
                                                <span className='text-sm text-gray-500'>
                                                    {formatDate(item.created_at)}
                                                </span>
                                            </div>
                                            <div className='mt-2 space-y-1'>
                                                {item.type === 'WholesaleIn' && (
                                                    <>
                                                        <p className='text-sm text-gray-900'>
                                                            <span className='font-medium'>Fornitore:</span>{' '}
                                                            {item.supplier_name}
                                                        </p>
                                                        <p className='text-sm text-gray-900'>
                                                            <span className='font-medium'>Qty:</span> {item.quantity}
                                                        </p>
                                                    </>
                                                )}
                                                {item.type === 'WholesaleOut' && (
                                                    <>
                                                        <p className='text-sm text-gray-900'>
                                                            <span className='font-medium'>Cliente:</span>{' '}
                                                            {item.customer_name}
                                                        </p>
                                                        <p className='text-sm text-gray-900'>
                                                            <span className='font-medium'>Qty:</span> {item.quantity}
                                                        </p>
                                                    </>
                                                )}
                                                {item.type === 'Sale' && (
                                                    <>
                                                        <p className='text-sm text-gray-900'>
                                                            <span className='font-medium'>Location:</span>{' '}
                                                            {item.location_name || '-'}
                                                        </p>
                                                        {item.remote_customer_name && (
                                                            <p className='text-sm text-gray-900'>
                                                                <span className='font-medium'>Cliente:</span>{' '}
                                                                {item.remote_customer_name}
                                                            </p>
                                                        )}
                                                        <p className='text-sm text-gray-900'>
                                                            <span className='font-medium'>Qty:</span> {item.quantity}
                                                        </p>
                                                    </>
                                                )}
                                            </div>
                                        </div>
                                        <div className='ml-4'>
                                            <Button
                                                variant='outline'
                                                size='sm'
                                                onClick={() => {
                                                    const routeName =
                                                        item.type === 'WholesaleIn'
                                                            ? 'wholesale-in.edit'
                                                            : item.type === 'WholesaleOut'
                                                              ? 'wholesale-out.edit'
                                                              : 'sale.edit';
                                                    router.visit(route(routeName, item.id));
                                                }}>
                                                <ExternalLink className='h-4 w-4 mr-1' />
                                                Apri
                                            </Button>
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className='mt-6 flex justify-start'>
                    <SecondaryButton onClick={() => router.visit(route('record.edit', record.id))}>
                        <ArrowLeft className='h-4 w-4 mr-2' />
                        Indietro
                    </SecondaryButton>
                </div>
            </div>
        </Authenticated>
    );
}
