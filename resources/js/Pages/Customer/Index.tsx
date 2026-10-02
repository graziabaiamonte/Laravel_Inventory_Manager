import Authenticated from '@/Layouts/AuthenticatedLayout';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { PageProps } from '@/types';
import { router } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { Trash, Pencil } from 'lucide-react';
import { useState, useEffect } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { Button } from '@/Components/ui/button';
import { getUpdatedSelection } from '@/lib/utils';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';
import InputLabel from '@/Components/InputLabel';
import Combo from '@/Components/atomica/Utils/Combo';
import DateInput from '@/Components/atomica/Forms/DateInput';

export default function Index({
    customers,
    applied_filters,
}: PageProps<{ customers: TableData; applied_filters: Record<string, string>[] }>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'customer', only: 'customers' });
    const [filters, setFilters] = useState<Record<string, string>>({
        search: '',
        no_recent_orders: '',
        no_recent_orders_date: '',
    });

    const [selectedItems, setSelectedItems] = useState<number[]>([]);
    const [showCustomDatePicker, setShowCustomDatePicker] = useState(false);

    const periodOptions = [
        { id: '1month', description: "nell'ultimo mese" },
        { id: '3months', description: 'negli ultimi 3 mesi' },
        { id: '6months', description: 'negli ultimi 6 mesi' },
        { id: '1year', description: "nell'ultimo anno" },
        { id: 'custom', description: 'personalizzato' },
    ];
    const selectedItemsActions = [
        {
            label: 'Delete',
            danger: true,
            action: () => useDeleteItem.changeRecordToDelete(selectedItems),
            hideAction: () => {},
        },
    ];

    useEffect(() => {
        const filters = Helpers.indexFilters.setupFromResponse(applied_filters);
        setFilters((prev: typeof filters) => ({ ...prev, ...filters }));

        // Check if custom period is selected
        if (filters.no_recent_orders === 'custom') {
            setShowCustomDatePicker(true);
        }
    }, [applied_filters]);

    const tableHeaders = [
        {
            label: '',
            icon: <Pencil />,
            action: (item: { id: number }) => router.visit(route('customer.edit', item.id)),
        },
        {
            label: 'Nome',
            key: 'name',
            sortable: true,
        },
        {
            label: 'Cognome',
            key: 'last_name',
            sortable: true,
        },
        {
            label: 'Email',
            key: 'email',
            sortable: true,
        },
        {
            label: 'Telefono',
            key: 'phone',
        },
        {
            label: 'Stato',
            value: (item: { status: number }) => (item.status === 1 ? 'Attivo' : 'Non attivo'),
            sortable: true,
        },
        {
            label: '',
            icon: <Trash />,
            danger: true,
            action: (item: { id: number }) => useDeleteItem.changeRecordToDelete(item.id),
        },
    ];

    return (
        <Authenticated
            title={'Elenco clienti'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);
                Helpers.getItems({
                    routeName: 'customer',
                    filter: newFilter,
                });
            }}
            search={filters?.search ? filters.search : ''}>
            <Modal
                type='danger'
                onClose={() => useDeleteItem.changeRecordToDelete(null)}
                onConfirm={() => useDeleteItem.doDelete()}
                show={Boolean(useDeleteItem.recordToDelete)}
            />

            <div className='my-5'>
                <div className='grid items-start gap-x-2 grid-cols-2 md:grid-cols-4'>
                    <div className=''>
                        <InputLabel>{'Senza ordini recenti'}</InputLabel>
                        <Combo
                            items={periodOptions}
                            displayValue={'description'}
                            selected={
                                filters.no_recent_orders
                                    ? periodOptions.find((p: { id: string }) => p.id === filters.no_recent_orders)
                                    : ''
                            }
                            onChange={item => {
                                const newFilter = { ...filters, no_recent_orders: item.id };
                                setFilters(newFilter);
                                setShowCustomDatePicker(item.id === 'custom');

                                // Only apply filter if not custom, or if custom and date is already set
                                if (item.id !== 'custom' || (item.id === 'custom' && filters.no_recent_orders_date)) {
                                    Helpers.getItems({
                                        routeName: 'customer',
                                        filter: newFilter,
                                    });
                                }
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, no_recent_orders: '', no_recent_orders_date: '' };
                                setFilters(newFilter);
                                setShowCustomDatePicker(false);
                                Helpers.getItems({
                                    routeName: 'customer',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>

                    <div>
                        {showCustomDatePicker && (
                            <DateInput
                                label='Senza ordini dal'
                                initialDate={filters.no_recent_orders_date || null}
                                onDateChange={date => {
                                    const newFilter = { ...filters, no_recent_orders_date: date };
                                    setFilters(newFilter);
                                    if (date) {
                                        Helpers.getItems({
                                            routeName: 'customer',
                                            filter: newFilter,
                                        });
                                    }
                                }}
                            />
                        )}
                    </div>

                    <div></div>

                    <div className='h-full flex justify-end items-end'>
                        <Button
                            type='button'
                            className='bg-destructive hover:bg-destructive/90 md:mb-[20px]'
                            onClick={() => {
                                router.visit(route('customerstrash.index'));
                            }}>
                            <Trash className='mr-2' /> {'Cestino'}
                        </Button>
                    </div>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(customer => item.id !== customer))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Crea cliente'}
                action={() => router.visit(route('customer.create'))}
                headers={tableHeaders as Array<TableHeaderType>}
                data={customers}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(customers.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'customer',
                        page: customers.current_page,
                        per_page: customers.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
            />
        </Authenticated>
    );
}
