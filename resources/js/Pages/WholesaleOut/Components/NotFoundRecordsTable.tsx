import React, { useState } from 'react';
import { Plus, Search } from 'lucide-react';
import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import SecondaryButton from '@/Components/SecondaryButton';
import PrimaryButton from '@/Components/PrimaryButton';
import { useDragScroll } from '@/Hooks/useDragScroll';

interface NotFoundRecord {
    row_number: number;
    cat_number: string;
    barcode: string;
    artist: string;
    title: string;
    format: string;
    label: string;
    price?: string;
    quantity: number;
}

interface SearchTerms {
    barcode: string;
    catNumber: string;
    artist: string;
    title: string;
}

interface NotFoundRecordsTableProps {
    notFoundRecords: NotFoundRecord[];
    onSearchRecord?: (searchTerms: Partial<SearchTerms>, quantityForThisSearch: number) => void;
}

export default function NotFoundRecordsTable({ notFoundRecords, onSearchRecord }: NotFoundRecordsTableProps) {
    const dragRef = useDragScroll<HTMLDivElement>();
    // Track quantity changes for each record by index
    const [quantities, setQuantities] = useState<{ [key: number]: number }>({});

    if (!notFoundRecords || notFoundRecords.length === 0) {
        return null;
    }

    // Get the current quantity for a record (user input or original)
    const getQuantityForRecord = (index: number, originalQuantity: number): number => {
        return quantities[index] ?? originalQuantity;
    };

    // Update quantity for a specific record
    const updateQuantity = (index: number, quantity: number) => {
        setQuantities(prev => ({
            ...prev,
            [index]: quantity,
        }));
    };

    const handleSearchRecord = (record: NotFoundRecord, index: number) => {
        const currentQuantity = getQuantityForRecord(index, record.quantity);

        // If callback is provided, use it to trigger in-page search
        if (onSearchRecord) {
            const searchTerms: Partial<SearchTerms> = {};

            // Use barcode if available, otherwise cat_number
            if (record.barcode) {
                searchTerms.barcode = record.barcode;
            } else if (record.cat_number) {
                searchTerms.catNumber = record.cat_number;
            }

            // Add artist and title for additional context if available
            if (record.artist) {
                searchTerms.artist = record.artist;
            }
            if (record.title) {
                searchTerms.title = record.title;
            }

            // Pass the specific quantity for this search
            onSearchRecord(searchTerms, currentQuantity);
            return;
        }

        // Fallback: redirect to the records page with search filters
        const filters: Record<string, string> = {};

        // Use barcode if available, otherwise cat_number
        if (record.barcode) {
            filters.barcode = record.barcode;
        } else if (record.cat_number) {
            filters.cat_number = record.cat_number;
        }

        // Filter to new (not used) records
        filters.type = 'new';

        // Add artist and title for additional context if available
        if (record.artist) {
            filters.artist = record.artist;
        }
        if (record.title) {
            filters.title = record.title;
        }

        // Navigate to records page with search filters
        router.visit(route('record.index'), {
            data: { filter: filters },
            preserveState: false,
        });
    };

    function openCreateRecordWithPrefill(record: NotFoundRecord) {
        const params = new URLSearchParams({
            cat_number: record.cat_number || '',
            barcode: record.barcode || '',
            artist: record.artist || '',
            title: record.title || '',
            format: record.format || '',
            label: record.label || '',
            price: record.price || '',
        }).toString();

        const url = route('record.create') + '?' + params;
        window.open(url, '_blank');
    }

    return (
        <div className='mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4'>
            <h3 className='text-lg font-semibold text-yellow-800 mb-4'>
                Articoli non trovati ({notFoundRecords.length})
            </h3>

            <div ref={dragRef} className='overflow-x-auto cursor-grab'>
                <table className='min-w-full divide-y divide-yellow-200'>
                    <thead className='bg-yellow-100'>
                        <tr>
                            <th className='px-3 py-2 text-left text-xs font-medium text-yellow-800 uppercase tracking-wider'>
                                Riga
                            </th>
                            <th className='px-3 py-2 text-left text-xs font-medium text-yellow-800 uppercase tracking-wider'>
                                Cat#
                            </th>
                            <th className='px-3 py-2 text-left text-xs font-medium text-yellow-800 uppercase tracking-wider'>
                                Barcode
                            </th>
                            <th className='px-3 py-2 text-left text-xs font-medium text-yellow-800 uppercase tracking-wider'>
                                Artista
                            </th>
                            <th className='px-3 py-2 text-left text-xs font-medium text-yellow-800 uppercase tracking-wider'>
                                Titolo
                            </th>
                            <th className='px-3 py-2 text-left text-xs font-medium text-yellow-800 uppercase tracking-wider'>
                                Formato
                            </th>
                            <th className='px-3 py-2 text-left text-xs font-medium text-yellow-800 uppercase tracking-wider'>
                                Quantità
                            </th>
                            <th className='px-3 py-2 text-left text-xs font-medium text-yellow-800 uppercase tracking-wider'>
                                Azioni
                            </th>
                        </tr>
                    </thead>
                    <tbody className='bg-white divide-y divide-yellow-200'>
                        {notFoundRecords.map((record, index) => (
                            <tr key={index} className='hover:bg-yellow-50'>
                                <td className='px-3 py-2 whitespace-nowrap text-sm text-gray-900'>
                                    {record.row_number}
                                </td>
                                <td className='px-3 py-2 whitespace-nowrap text-sm text-gray-900 font-mono'>
                                    {record.cat_number || '-'}
                                </td>
                                <td className='px-3 py-2 whitespace-nowrap text-sm text-gray-900 font-mono'>
                                    {record.barcode || '-'}
                                </td>
                                <td className='px-3 py-2 text-sm text-gray-900'>{record.artist || '-'}</td>
                                <td className='px-3 py-2 text-sm text-gray-900'>{record.title || '-'}</td>
                                <td className='px-3 py-2 whitespace-nowrap text-sm text-gray-900'>
                                    {record.format || '-'}
                                </td>
                                <td className='px-3 py-2 whitespace-nowrap text-sm text-gray-900'>
                                    <input
                                        type='number'
                                        min='1'
                                        value={getQuantityForRecord(index, record.quantity)}
                                        onChange={e => updateQuantity(index, parseInt(e.target.value) || 1)}
                                        className='w-16 px-2 py-1 text-sm border border-gray-300 rounded focus:ring-blue-500 focus:border-blue-500'
                                    />
                                </td>
                                <td className='px-3 py-2 flex gap-2 d-flex whitespace-nowrap text-sm text-gray-900'>
                                    <SecondaryButton
                                        type='button'
                                        onClick={() => handleSearchRecord(record, index)}
                                        title='Cerca questo articolo nel database'>
                                        <Search className='w-3 h-3' />
                                    </SecondaryButton>

                                    <PrimaryButton
                                        type='button'
                                        onClick={() => openCreateRecordWithPrefill(record)}
                                        title='Crea questo articolo'>
                                        <Plus className='w-3 h-3' />
                                    </PrimaryButton>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
