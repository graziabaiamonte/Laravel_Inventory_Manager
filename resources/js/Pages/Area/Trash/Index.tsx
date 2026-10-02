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
    areas,
    applied_filters,
}: PageProps<{ areas: TableData; applied_filters: Array<any> }>) {
    const useForceDeleteItem = Hooks.useForceDeleteItem({ routeName: 'area', only: 'areas' });
    const useRestoreItem = Hooks.useRestoreItem({ routeName: 'area', only: 'areas' });

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
            title={'Elenco Aree nel cestino'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);

                if (value === '' || value.length >= 3) {
                    Helpers.getItems({
                        routeName: 'areastrash',
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
                    Permanent delete, and the cascade is wide: areas -> stocks, and
                    stocks -> sale_records via stock_id, plus the per-area allocation
                    rows on carichi and backorders. wholesale_ins.area_id,
                    wholesale_outs.area_id and wholesale_out_records_areas.area_id are
                    RESTRICT, so a referenced area is refused rather than cascaded.
                */}
                <div className='text-left'>
                    <p className='font-semibold text-center'>
                        {Array.isArray(useForceDeleteItem.recordToForceDelete)
                            ? `Eliminare definitivamente ${useForceDeleteItem.recordToForceDelete.length} aree?`
                            : 'Eliminare definitivamente questa area?'}
                    </p>
                    <p className='mt-3 text-sm'>
                        L&apos;operazione non è reversibile. Insieme all&apos;area verranno eliminati anche:
                    </p>
                    <ul className='mt-2 text-sm list-disc list-outside pl-5 space-y-1'>
                        <li>le giacenze di tutti i dischi presenti in quest&apos;area</li>
                        <li>la presenza di quelle giacenze nelle vendite già registrate, ed il relativo storico</li>
                        <li>le quantità assegnate a quest&apos;area nei carichi e nei backorder</li>
                        <li>il collegamento dell&apos;area con le sedi</li>
                    </ul>
                    <p className='mt-3 text-sm'>
                        Le vendite e i carichi non vengono eliminati, ma i loro importi totali non vengono ricalcolati:
                        i documenti passati continueranno a mostrare il totale originale.
                    </p>
                    <p className='mt-3 text-sm font-medium'>
                        Se l&apos;area è usata da un carico o da uno scarico, l&apos;eliminazione viene rifiutata e
                        l&apos;area resta nel cestino.
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
                            router.visit(route('area.index'));
                        }}>
                        <User className='mr-2' /> {'Aree'}
                    </Button>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(area => item.id !== area))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                headers={tableHeaders as Array<TableHeaderType>}
                data={areas}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(areas.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'areastrash',
                        page: areas.current_page,
                        per_page: areas.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
                description='Aree'
            />
        </Authenticated>
    );
}
