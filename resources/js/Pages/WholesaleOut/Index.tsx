import Authenticated from '@/Layouts/AuthenticatedLayout';
import { PageProps, WholesaleOut, RouteParams } from '@/types';
import { router } from '@inertiajs/react';
import AtomicaTable, { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import Hooks from '@/Components/atomica/Utils/Hooks';
import Modal from '@/Components/atomica/Utils/Modal';
import { Pencil, Trash, Undo2 } from 'lucide-react';
import Combo from '@/Components/atomica/Utils/Combo';
import { useEffect, useState } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import InputLabel from '@/Components/InputLabel';
import { getUpdatedSelection } from '@/lib/utils';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';
import DateRangeInput from '@/Components/atomica/Forms/DateRangeInput';

export default function Index({
    wholesaleOuts,
    customers,
    areas,
    applied_filters,
}: PageProps<{ wholesaleOuts: TableData; customers: unknown[]; areas: unknown[]; applied_filters: unknown }>) {
    const useDeleteItem = Hooks.useDeleteItem({
        routeName: 'wholesale-out',
    });

    const [selectedItems, setSelectedItems] = useState<number[]>([]);
    const selectedFilters = route().params as RouteParams;

    const selectedItemsActions = [
        {
            label: 'Delete',
            danger: true,
            action: () => useDeleteItem.changeRecordToDelete(selectedItems),
            hideAction: () => {},
        },
    ];

    const tableHeaders: TableHeaderType[] = [
        {
            label: '',
            icon: <Pencil />,
            action: (item: any) => router.get(route('wholesale-out.edit', item.id)),
        },
        { label: 'Numero Doc.', key: 'doc_num', sortable: true },
        {
            label: 'Stato',
            key: 'status',
            value: (item: any) => {
                return (
                    <span
                        className={`px-2 py-5 rounded-md font-bold uppercase text-[11px] whitespace-nowrap ${
                            item.status === 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                        }`}>
                        {item.status === 1 ? 'Attivo' : 'Non attivo'}
                    </span>
                );
            },
            sortable: true,
        },
        {
            label: 'Cliente',
            key: 'customers.name',
            value: (item: any) => item.customer?.name ?? '',
            sortable: true,
        },
        {
            label: 'Importo Totale',
            key: 'total_price_formatted',
            value: (item: any) => String(item.total_price_formatted ?? ''),
            sortable: true,
        },
        { label: 'Data', key: 'created_at', value: (item: any) => item.created_at ?? '', sortable: true },
        {
            label: 'Backorders',
            icon: <Undo2 />,
            action: (item: any) => router.get(route('backorder.index', { filter: { wholesale_out_id: item.id } })),
            hideAction: (item: any) =>
                !item.backorders || !Array.isArray(item.backorders) || item.backorders.length === 0,
        },
        {
            label: '',
            icon: <Trash />,
            danger: true,
            action: (item: any) => useDeleteItem.changeRecordToDelete(item.id),
        },
    ];

    const statusOptions = [
        { value: 1, description: 'Attivo' },
        { value: 0, description: 'Non attivo' },
    ];

    const [filters, setFilters] = useState<any>({
        'customers.id': (selectedFilters?.filter?.['customers.id'] as number) || '',
        'areas.id': (selectedFilters?.filter?.['areas.id'] as number) || '',
        status: (selectedFilters?.filter?.status as string) ?? '',
        search: '',
    });

    useEffect(() => {
        const currentFilters = Helpers.indexFilters.setupFromResponse(applied_filters, {
            customer: customers,
            area: areas,
        });
        setFilters((prev: any) => ({ ...prev, ...currentFilters }));
    }, [applied_filters, customers, areas]);

    return (
        <Authenticated
            title={'Elenco Vendite Ingrosso'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);
                if (value === '' || value.length >= 3) {
                    Helpers.getItems({ routeName: 'wholesale-out', filter: newFilter });
                }
            }}
            search={String(filters?.search ?? '')}>
            <Modal
                type='danger'
                onClose={() => useDeleteItem.changeRecordToDelete(null)}
                onConfirm={useDeleteItem.doDelete}
                show={!!useDeleteItem.recordToDelete}
            />

            <div className='mt-5 border p-4 bg-gray-50 mb-5'>
                <div className='px-0 grid grid-cols-2 md:grid-cols-3 gap-3'>
                    <div>
                        <InputLabel htmlFor='customer_filter'>{'Filtra per cliente'}</InputLabel>
                        <Combo
                            items={customers}
                            displayValue={'name'}
                            // selected={filters.customer}
                            selected={
                                filters['customers.id']
                                    ? customers.find((c: any) => c.id == filters['customers.id'])
                                    : ''
                            }
                            onChange={item => {
                                //const newFilter = { ...filters, customer: item };
                                const newFilter = { ...filters, 'customers.id': item.id };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'wholesale-out', filter: newFilter });
                            }}
                            onClear={() => {
                                //const newFilter = { ...filters, customer: '' };
                                const newFilter = { ...filters, 'customers.id': '' };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'wholesale-out', filter: newFilter });
                            }}
                        />
                    </div>
                    <div>
                        <InputLabel htmlFor='status_filter'>{'Filtra per stato'}</InputLabel>
                        <Combo
                            items={statusOptions}
                            displayValue={'description'}
                            selected={
                                filters.status !== '' && filters.status !== null && filters.status !== undefined
                                    ? statusOptions.find(s => s.value === Number(filters.status))
                                    : ''
                            }
                            onChange={item => {
                                const newFilter = { ...filters, status: item.value };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'wholesale-out', filter: newFilter });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, status: '' };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'wholesale-out', filter: newFilter });
                            }}
                        />
                    </div>

                    <DateRangeInput
                        label='Data'
                        filter={filters.date}
                        onFilterChange={dates => {
                            const newFilter = { ...filters, date: dates };
                            setFilters(newFilter);
                            Helpers.getItems({
                                routeName: 'wholesale-out',
                                filter: newFilter,
                                page: 1,
                            });
                        }}
                    />
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item =>
                    setSelectedItems(selectedItems.filter(wholesaleout => item.id !== wholesaleout))
                }
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Nuova Vendita'}
                action={() => router.get(route('wholesale-out.create'))}
                headers={tableHeaders}
                data={wholesaleOuts}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(wholesaleOuts.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'wholesale-out',
                        page: wholesaleOuts.current_page,
                        per_page: wholesaleOuts.per_page,
                        sort_by: sort,
                        filter: filters,
                    })
                }
                sortBy={route().params?.sort ? String(route().params.sort) : 'id'}
                description="Elenco delle vendite all'ingrosso."
            />
        </Authenticated>
    );
}
