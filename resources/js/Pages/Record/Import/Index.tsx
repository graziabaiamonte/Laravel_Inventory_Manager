import Authenticated from '@/Layouts/AuthenticatedLayout';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { PageProps, RouteParams } from '@/types';
import { router, usePage } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { Trash, Pencil, Import } from 'lucide-react';
import { useState, useEffect } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import InputLabel from '@/Components/InputLabel';
import Autocomplete from '@/Components/atomica/Utils/Autocomplete';
import Combo from '@/Components/atomica/Utils/Combo';
import { Button } from '@/Components/ui/button';
import { getUpdatedSelection } from '@/lib/utils';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';

export default function Index({
    records_imports,
}: PageProps<{
    records_imports: TableData;
}>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'records-import', only: 'records-imports' });
    const selectedFilters = route().params as RouteParams;
    const [filters, setFilters] = useState<any>({
        search: '',
        type: (selectedFilters?.type as string) || '',
        disk_status: (selectedFilters?.disk_status as string) || '',
        cover_status: (selectedFilters?.cover_status as string) || '',
        for_sale_on_discogs: (selectedFilters?.for_sale_on_discogs as string) || '',
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

    const tableHeaders = [
        {
            label: 'Id',
            key: 'id',
            sortable: false,
        },
        {
            label: 'Importati il',
            key: 'created_at',
            sortable: true,
        },
        {
            label: 'Stato',
            key: 'draft_label',
        },
        {
            label: '',
            icon: <Pencil />,
            action: (item: any) => router.visit(route('records-import.edit', item.id)),
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
            title={'Elenco Importazioni Record'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);
                Helpers.getItems({
                    routeName: 'record',
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

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item =>
                    setSelectedItems(selectedItems.filter(record_import => item.id !== record_import))
                }
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Importa Records'}
                action={() => router.visit(route('records-import.create'))}
                headers={tableHeaders as Array<TableHeaderType>}
                data={records_imports}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(records_imports.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'records-import',
                        page: records_imports.current_page,
                        per_page: records_imports.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
            />
        </Authenticated>
    );
}
