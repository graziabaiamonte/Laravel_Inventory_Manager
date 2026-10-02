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
    artists,
    applied_filters,
}: PageProps<{ artists: TableData; applied_filters: Array<any> }>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'artist', only: 'artists' });
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
            action: (item: any) => router.visit(route('artist.edit', item.id)),
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
            title={'Elenco artisti'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);
                Helpers.getItems({
                    routeName: 'artist',
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
                            router.visit(route('artiststrash.index'));
                        }}>
                        <Trash className='mr-2' /> {'Cestino'}
                    </Button>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(artist => item.id !== artist))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Crea artista'}
                action={() => router.visit(route('artist.create'))}
                headers={tableHeaders as Array<TableHeaderType>}
                data={artists}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(artists.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'artist',
                        page: artists.current_page,
                        per_page: artists.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
            />
        </Authenticated>
    );
}
