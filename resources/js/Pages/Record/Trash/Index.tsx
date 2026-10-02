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
    records,
    applied_filters,
}: PageProps<{ records: TableData; applied_filters: Array<any> }>) {
    const useForceDeleteItem = Hooks.useForceDeleteItem({ routeName: 'record', only: 'records' });
    const useRestoreItem = Hooks.useRestoreItem({ routeName: 'record', only: 'records' });

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
            label: 'Id',
            key: 'rr_uid',
            sortable: false,
        },
        {
            label: 'Titolo',
            key: 'title',
            sortable: true,
        },
        {
            label: 'Barcode',
            key: 'barcode',
            sortable: true,
        },
        {
            label: 'Artista',
            key: 'artist_name',
            sortable: true,
        },
        {
            label: 'Fmt',
            key: 'format_name',
            sortable: true,
        },
        {
            label: 'Etichetta',
            key: 'label_name',
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
            title={'Elenco records nel cestino'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);

                if (value === '' || value.length >= 3) {
                    Helpers.getItems({
                        routeName: 'recordstrash',
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
                    This is the permanent delete, not the soft delete on the records
                    index, and the default modal copy does not say what goes with it.
                    The list below is the actual cascade closure from `records`:
                    stocks, sale_records, wholesale_in_records and its areas. Sales
                    and wholesale-ins are NOT deleted, only the deleted record's
                    presence in them, and their stored totals (sales.amount,
                    wholesale_ins.total_price) are not recalculated by the cascade.
                    wholesale_out_records is ON DELETE RESTRICT on both record_id and
                    stock_id, so wholesale-outs are never touched: they block instead.
                */}
                <div className='text-left'>
                    <p className='font-semibold text-center'>
                        {Array.isArray(useForceDeleteItem.recordToForceDelete)
                            ? `Eliminare definitivamente ${useForceDeleteItem.recordToForceDelete.length} record?`
                            : 'Eliminare definitivamente questo record?'}
                    </p>
                    <p className='mt-3 text-sm'>
                        L&apos;operazione non è reversibile. Insieme al record verranno eliminati anche:
                    </p>
                    <ul className='mt-2 text-sm list-disc list-outside pl-5 space-y-1'>
                        <li>le giacenze in tutte le aree di magazzino</li>
                        <li>la sua presenza nelle vendite già registrate, ed il relativo storico</li>
                        <li>la sua presenza nei carichi già registrati, ed il relativo storico</li>
                    </ul>
                    <p className='mt-3 text-sm'>
                        Le vendite e i carichi non vengono eliminati, ma i loro importi totali non vengono ricalcolati:
                        i documenti passati continueranno a mostrare il totale originale pur non contenendo più questo
                        record.
                    </p>
                    <p className='mt-3 text-sm font-medium'>
                        Gli scarichi non sono interessati. Se il record è presente in uno scarico, l&apos;eliminazione
                        viene rifiutata e il record resta nel cestino.
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
                            router.visit(route('record.index'));
                        }}>
                        <User className='mr-2' /> {'Records'}
                    </Button>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(record => item.id !== record))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                headers={tableHeaders as Array<TableHeaderType>}
                data={records}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(records.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'recordstrash',
                        page: records.current_page,
                        per_page: records.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
                description='Records'
            />
        </Authenticated>
    );
}
