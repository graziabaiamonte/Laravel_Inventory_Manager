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

export default function Index({
    labels,
    applied_filters,
}: PageProps<{ labels: TableData; applied_filters: Array<any> }>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'label', only: 'labels' });
    const [filters, setFilters] = useState<any>({ search: '' });

    const [selectedItems, setSelectedItems] = useState<number[]>([]);
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
    }, []);

    const tableHeaders = [
        {
            label: '',
            icon: <Pencil />,
            action: (item: any) => router.visit(route('label.edit', item.id)),
        },
        {
            label: 'Nome',
            key: 'name',
            sortable: true,
        },
        {
            label: 'Stato',
            value: (item: any) => (item.status === 1 ? 'Attivo' : 'Non attivo'),
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
            title={'Elenco etichette'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);
                Helpers.getItems({
                    routeName: 'label',
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

            <div className='px-0 grid grid-cols-2 gap-3 my-5'>
                <div className=''></div>

                <div className='flex justify-end'>
                    <Button
                        type='button'
                        className='bg-destructive hover:bg-destructive/90'
                        onClick={() => {
                            router.visit(route('labelstrash.index'));
                        }}>
                        <Trash className='mr-2' /> {'Cestino'}
                    </Button>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(label => item.id !== label))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Crea etichetta'}
                action={() => router.visit(route('label.create'))}
                headers={tableHeaders as Array<TableHeaderType>}
                data={labels}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(labels.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'label',
                        page: labels.current_page,
                        per_page: labels.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
            />
        </Authenticated>
    );
}
