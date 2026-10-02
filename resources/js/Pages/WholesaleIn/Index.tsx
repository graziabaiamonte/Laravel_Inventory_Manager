import Authenticated from '@/Layouts/AuthenticatedLayout';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { PageProps, RouteParams } from '@/types';
import { router } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { Trash, Pencil } from 'lucide-react';
import Combo from '@/Components/atomica/Utils/Combo';
import { useEffect, useState } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import InputLabel from '@/Components/InputLabel';
import { getUpdatedSelection } from '@/lib/utils';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';
import DateRangeInput from '@/Components/atomica/Forms/DateRangeInput';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';

export default function Index({
    wholesaleIns,
    suppliers,
    areas,
    applied_filters,
}: PageProps<{ wholesaleIns: TableData; suppliers: any; areas: any; applied_filters: Array<any> }>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'wholesale-in', only: 'wholesaleIns' });

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

    const tableHeaders = [
        {
            label: '',
            icon: <Pencil />,
            action: (item: any) => router.visit(route('wholesale-in.edit', item.id)),
        },
        {
            label: 'N. Doc.',
            key: 'doc_num',
            sortable: true,
        },
        {
            label: 'Stato',
            key: 'status',
            value: (item: any) => {
                return (
                    <span
                        className={`px-2 py-5 rounded-md font-bold uppercase text-[11px] whitespace-nowrap ${
                            item.status === 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                        }`}>
                        {/* {item.status === 1 ? 'Processato' : 'Pending'} */}
                        {item.status === 1 ? 'Attivo' : 'Non attivo'}
                    </span>
                );
            },
            sortable: true,
        },
        {
            label: 'Fornitore',
            key: 'suppliers.name',
            value: (item: any) => item.supplier.name,
        },
        {
            label: 'Importo Totale',
            key: 'total_price',
            value: (item: any) => item.total_price_formatted,
            sortable: true,
        },
        {
            label: 'Data',
            key: 'created_at',
            sortable: true,
        },
        {
            label: '',
            icon: <Trash />,
            danger: true,
            action: (item: any) => useDeleteItem.changeRecordToDelete(item.id),
        },
    ];

    const [filters, setFilters] = useState<any>({
        'suppliers.id': (selectedFilters?.filter?.['suppliers.id'] as number) || '',
        'areas.id': (selectedFilters?.filter?.['areas.id'] as number) || '',
        status: (selectedFilters?.filter?.['status'] as number) ?? '',
        search: '',
    });

    useEffect(() => {
        const filters = Helpers.indexFilters.setupFromResponse(applied_filters, suppliers);
        setFilters((prev: typeof filters) => ({ ...prev, ...filters }));
    }, []);

    return (
        <Authenticated
            title={'Elenco carichi'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);

                if (value === '' || value.length >= 3) {
                    Helpers.getItems({
                        routeName: 'wholesale-in',
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

            <div className='mt-5 border p-4 bg-gray-50 mb-5'>
                <div className='px-0 grid grid-cols-2 md:grid-cols-3 gap-3'>
                    <div className=''>
                        <InputLabel>{'Filtra per fornitore'}</InputLabel>
                        <Combo
                            items={suppliers}
                            displayValue={'name'}
                            selected={
                                filters['suppliers.id']
                                    ? suppliers.find((s: any) => s.id == filters['suppliers.id'])
                                    : ''
                            }
                            onChange={item => {
                                const newFilter = { ...filters, 'suppliers.id': item.id };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'wholesale-in',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, 'suppliers.id': '' };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'wholesale-in',
                                    filter: newFilter,
                                });
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
                                routeName: 'wholesale-in',
                                filter: newFilter,
                                page: 1,
                            });
                        }}
                    />

                    <div className='mt-1'>
                        <StatusCombo
                            label='Filtra per stato'
                            value={filters.status}
                            onChange={value => {
                                const newFilter = { ...filters, status: value };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'wholesale-in', filter: newFilter });
                            }}
                        />
                    </div>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(wholesail => item.id !== wholesail))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Nuovo carico'}
                action={() => router.visit(route('wholesale-in.create'))}
                headers={tableHeaders as Array<TableHeaderType>}
                data={wholesaleIns}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(wholesaleIns.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'wholesale-in',
                        page: wholesaleIns.current_page,
                        per_page: wholesaleIns.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
                description='Carichi'
            />
        </Authenticated>
    );
}
