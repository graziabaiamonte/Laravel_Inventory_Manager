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
    locations,
    applied_filters,
}: PageProps<{ locations: TableData; applied_filters: Array<any> }>) {
    const useForceDeleteItem = Hooks.useForceDeleteItem({ routeName: 'location', only: 'locations' });
    const useRestoreItem = Hooks.useRestoreItem({ routeName: 'location', only: 'locations' });

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
            title={'Elenco Locations nel cestino'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);

                if (value === '' || value.length >= 3) {
                    Helpers.getItems({
                        routeName: 'locationstrash',
                        filter: newFilter, // cleanUpFilters will handle the role object transformation
                    });
                }
            }}
            search={filters?.search ? filters.search : ''}>
            <Modal
                type='danger'
                confirmText='Elimina definitivamente'
                onClose={() => useForceDeleteItem.changeRecordToForceDelete(null)}
                onConfirm={() => useForceDeleteItem.doForceDelete()}
                show={Boolean(useForceDeleteItem.recordToForceDelete)}>
                {/*
                    Permanent delete, and this one takes whole sales with it, not just
                    their lines: sales.location_id is ON DELETE CASCADE, and sales
                    cascade on to sale_records. Nothing RESTRICTs a location, so there
                    is no database-level refusal to fall back on.
                */}
                <div className='text-left'>
                    <p className='font-semibold text-center'>
                        {Array.isArray(useForceDeleteItem.recordToForceDelete)
                            ? `Eliminare definitivamente ${useForceDeleteItem.recordToForceDelete.length} sedi?`
                            : 'Eliminare definitivamente questa sede?'}
                    </p>
                    <p className='mt-3 text-sm font-medium text-red-700'>
                        Attenzione: verranno eliminate tutte le vendite registrate in questa sede, non solo il loro
                        collegamento con essa.
                    </p>
                    <p className='mt-3 text-sm'>
                        L&apos;operazione non è reversibile. Insieme alla sede verranno eliminati anche:
                    </p>
                    <ul className='mt-2 text-sm list-disc list-outside pl-5 space-y-1'>
                        <li>tutte le vendite registrate in questa sede, ed il relativo storico</li>
                        <li>il collegamento della sede con le aree e con gli utenti</li>
                    </ul>
                    <p className='mt-3 text-sm'>
                        Gli utenti e le aree non vengono eliminati: perdono solo il collegamento con questa sede.
                    </p>
                </div>
            </Modal>

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
                            router.visit(route('location.index'));
                        }}>
                        <User className='mr-2' /> {'Locations'}
                    </Button>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(location => item.id !== location))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                headers={tableHeaders as Array<TableHeaderType>}
                data={locations}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(locations.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'locationstrash',
                        page: locations.current_page,
                        per_page: locations.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
                description='Location'
            />
        </Authenticated>
    );
}
