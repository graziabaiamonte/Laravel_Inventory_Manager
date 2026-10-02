import Authenticated from '@/Layouts/AuthenticatedLayout';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { PageProps, RouteParams, EnumShape } from '@/types';
import { router } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { Trash, Pencil } from 'lucide-react';
import Combo from '@/Components/atomica/Utils/Combo';
import Autocomplete from '@/Components/atomica/Utils/Autocomplete';
import { useEffect, useState, useMemo } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import DateRangeInput from '@/Components/atomica/Forms/DateRangeInput';
import InputLabel from '@/Components/InputLabel';
import { getUpdatedSelection } from '@/lib/utils';
import { CardSalesSimple } from '@/Components/atomica/CardSalesSimple';
import { useSaleExport } from '@/Hooks/useSaleExport';
import ExportProgressModal from '@/Pages/Record/Components/ExportProgressModal';
import { Button } from '@/Components/ui/button';

export default function Index({
    sales,
    locations,
    types,
    applied_filters,
    selectedSupplier,
    salesSummary,
}: PageProps<{
    sales: TableData;
    locations: any;
    types: Array<EnumShape>;
    applied_filters: Array<any>;
    selectedSupplier?: any;
    salesSummary: {
        day: number;
        month: number;
        range: number;
    };
}>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'sale', only: 'sales' });

    // Export hook
    const { progress, isExporting, startExport, cancelExport, downloadExport, resetExport } = useSaleExport();
    const [showExportModal, setShowExportModal] = useState(false);

    const tableHeaders = [
        {
            label: '',
            icon: <Pencil />,
            action: (item: any) => router.visit(route('sale.edit', item.saleId)),
            hideAction: (item: any) => !item.showActions,
        },
        {
            label: 'Data',
            key: 'date',
            value: (item: any) => item.dateFormatted || '',
            sortable: true,
        },
        {
            label: 'Location',
            key: 'location',
            value: (item: any) => item.location || '',
        },
        {
            label: 'Stato',
            key: 'type_name',
            sortable: false,
            value: (item: any) => {
                if (!item.record_type) return '-';
                return (
                    <span
                        className={`px-2 py-5 rounded-md font-bold uppercase text-[11px] ${
                            item.record_type === 'new' ? 'bg-black text-white' : 'bg-green-100 text-green-800'
                        }`}>
                        {item.record_type_name}
                    </span>
                );
            },
        },
        {
            label: 'Artista',
            key: 'artist',
        },
        {
            label: 'Titolo',
            key: 'title',
        },
        {
            label: 'Fmt',
            key: 'format',
        },
        {
            label: 'Etichetta',
            key: 'label',
        },
        {
            label: 'Fornitore',
            key: 'supplier',
        },
        {
            label: 'Quantità',
            key: 'quantity',
        },
        {
            label: 'Totale',
            key: 'total_price',
            value: (item: any) => item.total_price_formatted || '',
        },
        {
            label: 'Totale Vendita',
            key: 'amount',
            value: (item: any) => item.amount || '',
        },
        {
            label: '',
            icon: <Trash />,
            danger: true,
            action: (item: any) => useDeleteItem.changeRecordToDelete(item.saleId),
            hideAction: (item: any) => !item.showActions,
        },
    ];

    const selectedFilters = route().params as RouteParams;

    //console.log(selectedFilters);

    const [filters, setFilters] = useState<any>({
        date: selectedFilters.filter?.date
            ? {
                  startDate: selectedFilters.filter.date.startDate,
                  endDate: selectedFilters.filter.date.endDate,
              }
            : null,
        // NOTE: uses a relationship filter: AllowedFilter::exact('locations.id')
        'locations.id': (selectedFilters?.filter?.['locations.id'] as number) || '', // Will be transformed to "locations.id" by Helpers.indexFilters.cleanUp()
        // NOTE: type is a direct column filter: AllowedFilter::exact('type')
        type: (selectedFilters?.filter?.type as number) || null, // Should be null when no filter is applied
        'suppliers.id': (selectedFilters?.filter?.['suppliers.id'] as number) || '',
        search: '',
    });

    const [selectedItems, setSelectedItems] = useState<number[]>([]);
    const [summary, setSummary] = useState(salesSummary);

    const selectedItemsActions = [
        {
            label: 'Delete',
            danger: true,
            action: () => useDeleteItem.changeRecordToDelete(selectedItems),
            hideAction: () => {},
        },
    ];

    useEffect(() => {
        const filters = Helpers.indexFilters.setupFromResponse(applied_filters, locations);
        setFilters((prev: typeof filters) => ({ ...prev, ...filters }));
    }, []);

    useEffect(() => {
        setSummary(salesSummary);
    }, [salesSummary]);

    // Transform sales data to show one row per SaleRecord
    const transformedSalesData = useMemo(() => {
        const rows: any[] = [];

        sales.data.forEach((sale: any, saleIndex: number) => {
            if (sale.sale_records && sale.sale_records.length > 0) {
                sale.sale_records.forEach((saleRecord: any, index: number) => {
                    const record = saleRecord.parent_record;
                    rows.push({
                        id: `${sale.id}-${saleRecord.id}`,
                        saleId: sale.id,
                        saleRecordId: saleRecord.id,
                        artist: record?.artist_name || '-',
                        title: record?.title || '-',
                        format: record?.format_name || '-',
                        record_type: record?.type || null,
                        record_type_name: record?.type_name || '-',
                        label: record?.label_name || '-',
                        supplier: saleRecord.supplier_name || '-',
                        quantity: saleRecord.quantity,
                        total_price: saleRecord.total_price,
                        total_price_formatted: saleRecord.total_price_formatted,
                        // Sale info (only for first record)
                        date: index === 0 ? sale.date : '',
                        dateFormatted: index === 0 ? sale.date_formatted : '',
                        location: index === 0 ? sale.location?.name || '-' : '',
                        type: index === 0 ? types.find(t => t.value === sale.type)?.description || sale.type : '',
                        amount: index === 0 ? sale.amount_formatted : '',
                        showActions: index === 0,
                        rowClassName: saleIndex % 2 === 0 ? 'bg-gray-100' : '',
                    });
                });
            } else {
                // If no sale records, show an empty row with sale info
                rows.push({
                    id: sale.id,
                    saleId: sale.id,
                    saleRecordId: null,
                    artist: '-',
                    title: '-',
                    format: '-',
                    record_type: null,
                    record_type_name: '-',
                    label: '-',
                    supplier: '-',
                    quantity: '-',
                    total_price: '-',
                    date: sale.date,
                    dateFormatted: sale.date_formatted,
                    location: sale.location?.name || '-',
                    type: types.find(t => t.value === sale.type)?.description || sale.type,
                    amount: sale.amount_formatted,
                    showActions: true,
                    rowClassName: saleIndex % 2 === 0 ? 'bg-gray-100' : '',
                });
            }
        });

        return {
            ...sales,
            data: rows,
        };
    }, [sales, types]);

    return (
        <Authenticated
            title={'Elenco vendite'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);

                if (value === '' || value.length >= 3) {
                    Helpers.getItems({
                        routeName: 'sale',
                        filter: newFilter,
                    });
                }
            }}
            search={filters?.search ? filters.search : ''}>
            <Modal
                type='danger'
                onClose={() => useDeleteItem.changeRecordToDelete(null)}
                onConfirm={() => useDeleteItem.doDelete()}
                show={Boolean(useDeleteItem.recordToDelete)}
            />

            <div className='mt-5 border p-4 bg-gray-50'>
                <div className='px-0 grid grid-cols-2 md:grid-cols-4 gap-3'>
                    <DateRangeInput
                        label='Data'
                        filter={filters.date}
                        onFilterChange={dates => {
                            const newFilter = { ...filters, date: dates };
                            setFilters(newFilter);
                            Helpers.getItems({
                                routeName: 'sale',
                                filter: newFilter,
                                page: 1,
                            });
                        }}
                    />
                    <div className=''>
                        <InputLabel>{'Filtra per tipo'}</InputLabel>
                        <Combo
                            items={types}
                            displayValue={'description'}
                            selected={filters.type !== null ? types.find(t => t.value == filters.type) : null}
                            onChange={item => {
                                const newFilter = { ...filters, type: item.value };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'sale',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, type: null };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'sale',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>
                    <div className=''>
                        <InputLabel>{'Filtra per location'}</InputLabel>
                        <Combo
                            items={locations}
                            displayValue={'name'}
                            selected={
                                filters['locations.id']
                                    ? locations.find((l: any) => l.id == filters['locations.id'])
                                    : ''
                            }
                            onChange={item => {
                                const newFilter = { ...filters, 'locations.id': item.id };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'sale',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, 'locations.id': '' };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'sale',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>
                    <div>
                        <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                            Fornitore
                        </InputLabel>
                        <Autocomplete
                            placeholder={'Digita...'}
                            routeName='supplier.index'
                            initialValue={selectedSupplier}
                            value={selectedSupplier}
                            className={'mt-2'}
                            onChange={item => {
                                const newFilter = {
                                    ...filters,
                                    supplier_id: item && typeof item === 'object' && item.id ? item.id : '',
                                };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'sale',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, supplier_id: '' };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'sale', filter: newFilter });
                            }}
                        />
                    </div>
                </div>
            </div>

            <div className='mt-5 mb-5'>
                <CardSalesSimple title='Vendite' description='Andamento delle vendite' salesData={summary} />
            </div>

            <div className='flex justify-end mb-4'>
                <Button
                    onClick={async () => {
                        setShowExportModal(true);
                        await startExport(filters);
                    }}
                    disabled={isExporting}>
                    {isExporting ? 'Esportazione...' : 'Esporta Vendite'}
                </Button>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={false}
                onClearSelection={item => setSelectedItems(selectedItems.filter(sale => item.id !== sale))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Nuova vendita'}
                action={() => router.visit(route('sale.create'))}
                headers={tableHeaders as Array<TableHeaderType>}
                data={transformedSalesData}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(transformedSalesData.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'sale',
                        page: sales.current_page,
                        per_page: sales.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
                description='Vendite'
            />

            <ExportProgressModal
                isOpen={showExportModal}
                progress={progress}
                onClose={() => setShowExportModal(false)}
                onDownload={downloadExport}
                onCancel={cancelExport}
                onReset={resetExport}
            />
        </Authenticated>
    );
}
