import Authenticated from '@/Layouts/AuthenticatedLayout';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { PageProps } from '@/types';
import { router } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { Trash, Pencil, RotateCcw, User } from 'lucide-react';
import InputLabel from '@/Components/InputLabel';
import Combo from '@/Components/atomica/Utils/Combo';
import { useEffect, useState } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { Button } from '@/Components/ui/button';
import { getUpdatedSelection } from '@/lib/utils';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';

export default function Index({
    customers,
    applied_filters,
}: PageProps<{ customers: TableData; applied_filters: Array<any> }>) {
    const useForceDeleteItem = Hooks.useForceDeleteItem({ routeName: 'customer', only: 'customers' });
    const useRestoreItem = Hooks.useRestoreItem({ routeName: 'customer', only: 'customers' });

    const [selectedItems, setSelectedItems] = useState<number[]>([]);
    const selectedItemsActions = [
        {
            label: 'Delete',
            danger: true,
            action: () => useForceDeleteItem.changeRecordToForceDelete(selectedItems),
            hideAction: () => {},
        },
    ];

    const tableHeaders = [
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
            label: <span>{'Ripristina'}</span>,
            icon: <RotateCcw />,
            danger: false,
            action: (item: { id: number }) => useRestoreItem.changeRecordToRestore(item.id),
        },
        {
            label: <span>{'Elimina definitivamente'}</span>,
            icon: <Trash />,
            danger: true,
            action: (item: { id: number }) => useForceDeleteItem.changeRecordToForceDelete(item.id),
        },
    ];

    const [filters, setFilters] = useState<any>({
        search: '',
    });

    useEffect(() => {
        const filters = Helpers.indexFilters.setupFromResponse(applied_filters);
        setFilters((prev: typeof filters) => ({ ...prev, ...filters }));
    }, []);

    return (
        <Authenticated
            title={'Elenco clienti nel cestino'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);

                if (value === '' || value.length >= 3) {
                    Helpers.getItems({
                        routeName: 'customerstrash',
                        filter: newFilter,
                    });
                }
            }}
            search={filters?.search ? filters.search : ''}>
            <Modal
                type='danger'
                onClose={() => useForceDeleteItem.changeRecordToForceDelete(null)}
                onConfirm={() => useForceDeleteItem.doForceDelete()}
                show={Boolean(useForceDeleteItem.recordToForceDelete)}
            />

            <Modal
                type='primary'
                onClose={() => useRestoreItem.changeRecordToRestore(null)}
                onConfirm={() => useRestoreItem.doRestore()}
                show={Boolean(useRestoreItem.recordToRestore)}
            />

            <div className='px-0 grid grid-cols-2 gap-3 my-5'>
                <div></div>
                <div className='flex justify-end items-end'>
                    <Button
                        type='button'
                        onClick={() => {
                            router.visit(route('customer.index'));
                        }}>
                        <User className='mr-2' /> {'Clienti'}
                    </Button>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(customer => item.id !== customer))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                headers={tableHeaders as Array<TableHeaderType>}
                data={customers}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(customers.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'customerstrash',
                        page: customers.current_page,
                        per_page: customers.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
                description='Clienti'
            />
        </Authenticated>
    );
}
