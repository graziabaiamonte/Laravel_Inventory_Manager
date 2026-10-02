import Authenticated from '@/Layouts/AuthenticatedLayout';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { PageProps } from '@/types';
import { router } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { Trash, Pencil, Truck } from 'lucide-react';
import { useState, useEffect } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { Button } from '@/Components/ui/button';
import { getUpdatedSelection } from '@/lib/utils';
import Combo from '@/Components/atomica/Utils/Combo';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';
import DateRangeInput from '@/Components/atomica/Forms/DateRangeInput';
import InputLabel from '@/Components/InputLabel';

export default function Index({
    backorders,
    applied_filters,
    status_list,
    customers,
}: PageProps<{ backorders: TableData; applied_filters: Array<any>; status_list: Array<any>; customers: Array<any> }>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'backorder', only: 'backorders' });
    const [filters, setFilters] = useState<any>({ search: '' });

    const [selectedItems, setSelectedItems] = useState<number[]>([]);

    useEffect(() => {
        const filters = Helpers.indexFilters.setupFromResponse(applied_filters);
        setFilters((prev: typeof filters) => ({ ...prev, ...filters }));
    }, []);

    const tableHeaders = [
        {
            label: '',
            icon: <Pencil />,
            action: (item: any) => router.visit(route('backorder.edit', item.id)),
        },
        {
            label: 'Cliente',
            key: 'customer_name',
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
                        {item.status === 1 ? 'Attivo' : 'Non attivo'}
                    </span>
                );
            },
            sortable: true,
        },
        {
            label: 'Data Creazione',
            key: 'created_at',
            sortable: true,
        },
        {
            label: 'Scarico',
            icon: <Truck />,
            action: (item: any) => router.visit(route('wholesale-out.edit', item.whole_sale_out_id)),
        },
    ];

    return (
        <Authenticated
            title={'Elenco Backorders'}
            onSearch={(value: string) => {
                const newFilter = { ...filters, search: value };
                setFilters(newFilter);
                Helpers.getItems({
                    routeName: 'backorder',
                    filter: newFilter,
                });
            }}
            search={filters?.search ? filters.search : ''}>
            <div className='mt-5 border p-4 bg-gray-50 mb-5'>
                <div className='px-0 grid grid-cols-2 md:grid-cols-3 gap-3'>
                    <div>
                        <InputLabel>{'Stato'}</InputLabel>
                        <Combo
                            items={status_list}
                            //label={'Stato'}
                            displayValue={'description'}
                            selected={status_list.find(status => status.value === parseInt(filters.status))}
                            onChange={status => {
                                const newFilter = { ...filters, status: status.value };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'backorder',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, status: '' };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'backorder',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>

                    <div>
                        <InputLabel>{'Clienti'}</InputLabel>
                        <Combo
                            items={customers}
                            //label={'Clienti'}
                            displayValue={'name'}
                            selected={
                                filters.customer_id !== null
                                    ? customers.find(customer => customer.id === filters.customer_id)
                                    : null
                            }
                            onChange={customer => {
                                const newFilter = { ...filters, customer_id: customer.id };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'backorder',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, customer_id: null };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'backorder',
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
                                routeName: 'backorder',
                                filter: newFilter,
                                page: 1,
                            });
                        }}
                    />
                </div>
            </div>

            <AtomicaTable
                //selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={false}
                onClearSelection={item => setSelectedItems(selectedItems.filter(backorder => item.id !== backorder))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                headers={tableHeaders as Array<TableHeaderType>}
                data={backorders}
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'backorder',
                        page: backorders.current_page,
                        per_page: backorders.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
            />
        </Authenticated>
    );
}
