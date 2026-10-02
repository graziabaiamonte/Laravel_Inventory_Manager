import Authenticated from '@/Layouts/AuthenticatedLayout';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { PageProps, RouteParams } from '@/types';
import { router } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { Trash, Pencil } from 'lucide-react';
import InputLabel from '@/Components/InputLabel';
import Combo from '@/Components/atomica/Utils/Combo';
import { useEffect, useState } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { Button } from '@/Components/ui/button';
import { getUpdatedSelection } from '@/lib/utils';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';

export default function Index({
    users,
    roles,
    applied_filters,
}: PageProps<{ users: TableData; roles: any; applied_filters: Array<any> }>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'user', only: 'users' });

    const tableHeaders = [
        {
            label: '',
            icon: <Pencil />,
            action: (item: any) => router.visit(route('user.edit', item.id)),
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
            label: 'Tipologia',
            key: 'role_name',
            sortable: true,
        },
        {
            label: '',
            icon: <Trash />,
            danger: true,
            action: (item: { id: number }) => useDeleteItem.changeRecordToDelete(item.id),
        },
    ];

    const selectedFilters = route().params as RouteParams;

    const [filters, setFilters] = useState<any>({
        //role: '',
        'roles.id': (selectedFilters?.filter?.['roles.id'] as number) || '',
        search: '',
    });

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
        const filters = Helpers.indexFilters.setupFromResponse(applied_filters, roles);
        setFilters((prev: typeof filters) => ({ ...prev, ...filters }));
    }, []);

    return (
        <Authenticated
            title={'Elenco utenti'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);

                if (value === '' || value.length >= 3) {
                    Helpers.getItems({
                        routeName: 'user',
                        filter: newFilter, // cleanUpFilters will handle the role object transformation
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
                <div className='px-0 grid grid-cols-2 gap-3'>
                    <div className=''>
                        <InputLabel>{'Filtra per tipologia'}</InputLabel>
                        <Combo
                            items={roles}
                            displayValue={'description'}
                            // selected={filters.role}
                            selected={filters['roles.id'] ? roles.find((r: any) => r.id == filters['roles.id']) : ''}
                            onChange={item => {
                                //const newFilter = { ...filters, role: item };
                                const newFilter = { ...filters, 'roles.id': item.id };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'user',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                //const newFilter = { ...filters, role: '' };
                                const newFilter = { ...filters, 'roles.id': '' };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'user',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>
                </div>
            </div>

            <div className='my-5'>
                <div className='flex justify-end'>
                    <Button
                        type='button'
                        className='bg-destructive hover:bg-destructive/90'
                        onClick={() => {
                            router.visit(route('userstrash.index'));
                        }}>
                        <Trash className='mr-2' /> {'Cestino'}
                    </Button>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(user => item.id !== user))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Crea utente'}
                action={() => router.visit(route('user.create'))}
                headers={tableHeaders as Array<TableHeaderType>}
                data={users}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(users.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'user',
                        page: users.current_page,
                        per_page: users.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
                description='Utenti'
            />
        </Authenticated>
    );
}
