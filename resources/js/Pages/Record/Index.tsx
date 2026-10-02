import Authenticated from '@/Layouts/AuthenticatedLayout';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { PageProps, RouteParams } from '@/types';
import { router } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { Trash, Pencil, Printer, FileUp, Download, FileSpreadsheet, ChevronDown, Link, X } from 'lucide-react';
import { useState, useEffect } from 'react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import InputLabel from '@/Components/InputLabel';
import Autocomplete from '@/Components/atomica/Utils/Autocomplete';
import Combo from '@/Components/atomica/Utils/Combo';
import { Button } from '@/Components/ui/button';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';
import { getUpdatedSelection } from '@/lib/utils';
import SecondaryButton from '@/Components/SecondaryButton';
import { useRecordExport } from '@/Hooks/useRecordExport';
import ExportProgressModal from './Components/ExportProgressModal';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';

export default function Index({
    records,
    applied_filters,
    types,
    diskstatus,
    coverstatus,
    forsalediscogsstatus,
    selectedArtist,
    selectedFormat,
    selectedLabel,
    selectedSupplier,
}: PageProps<{
    records: TableData;
    applied_filters: Array<any>;
    types: Array<any>;
    diskstatus: Array<any>;
    coverstatus: Array<any>;
    forsalediscogsstatus: Array<any>;
    selectedArtist?: any;
    selectedFormat?: any;
    selectedLabel?: any;
    selectedSupplier?: any;
}>) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'record', only: 'records' });
    const selectedFilters = route().params as RouteParams;

    // Single export hook for both regular and wholesale export
    const { progress, isExporting, startExport, cancelExport, downloadExport, resetExport } = useRecordExport();
    const [showExportModal, setShowExportModal] = useState(false);
    const [filters, setFilters] = useState<any>({
        search: (selectedFilters?.search as string) || '',
        title: (selectedFilters?.title as string) || '',
        type: (selectedFilters?.type as string) || '',
        disk_status: (selectedFilters?.disk_status as string) || '',
        cover_status: (selectedFilters?.cover_status as string) || '',
        for_sale_on_discogs: (selectedFilters?.for_sale_on_discogs as string) || '',
        artist_id: (selectedFilters?.artist_id as number) || '',
        format_id: (selectedFilters?.format_id as number) || '',
        label_id: (selectedFilters?.label_id as number) || '',
        supplier_id: (selectedFilters?.supplier_id as number) || '',
        wholesale: (selectedFilters?.wholesale as boolean) || false,
        price_type: (selectedFilters?.price_type as string) || '',
        price_min: (selectedFilters?.price_min as string) || '',
        price_max: (selectedFilters?.price_max as string) || '',
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
        const filters = Helpers.indexFilters.setupFromResponse(applied_filters);
        setFilters((prev: typeof filters) => ({ ...prev, ...filters }));
    }, []);

    const handleExport = async (isWholesale: boolean, uppercase: boolean) => {
        const cleanedFilters = Helpers.indexFilters.cleanUp(filters);
        setShowExportModal(true);
        await startExport(cleanedFilters, isWholesale, uppercase);
    };

    const tableHeaders = [
        {
            label: '',
            icon: <Printer />,
            action: (item: any) => window.open(route('record.barcode', item.id), '_blank'),
        },
        {
            label: '',
            icon: <Pencil />,
            action: (item: any) => window.open(route('record.edit', item.id), '_blank'),
        },
        {
            label: 'Id',
            key: 'rr_uid',
            sortable: true,
        },
        {
            label: (
                <>
                    Nuovo/
                    <br />
                    Usato
                </>
            ),
            key: 'type_name',
            sortable: false,
            value: (item: any) => {
                return (
                    <span
                        className={`px-2 py-5 rounded-md font-bold uppercase text-[11px] ${
                            item.type === 'new' ? 'bg-black text-white' : 'bg-green-100 text-green-800'
                        }`}>
                        {item.type_name}
                    </span>
                );
            },
        },
        {
            label: 'Cat. #',
            key: 'cat_number',
            sortable: true,
        },
        {
            label: 'Artista',
            key: 'artist_name',
            sortable: true,
            width: 'min-w-[200px]',
        },
        {
            label: 'Titolo',
            key: 'title',
            sortable: true,
            width: 'min-w-[200px]',
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
            label: 'Barcode',
            key: 'barcode',
            sortable: true,
        },
        {
            label: 'Tot. Stock',
            key: 'total_stocks',
            sortable: true,
        },
        {
            label: 'Prezzo acquisto',
            key: 'purchase_price_formatted',
            sortable: false,
        },
        {
            label: 'Prezzo ingrosso',
            key: 'wholesale_price_formatted',
            sortable: false,
        },
        {
            label: 'Prezzo dettaglio',
            key: 'retail_price_formatted',
            sortable: false,
        },
        {
            label: 'Cond. Disco',
            key: 'disk_status',
            value: (item: any) => {
                return item.disk_status_name || '';
            },
            sortable: true,
        },
        {
            label: 'Cover',
            key: 'cover_status',
            value: (item: any) => {
                return item.cover_status_name || '';
            },
            sortable: true,
        },
        {
            label: 'Data creazione',
            key: 'created_at',
            sortable: false,
        },
        {
            label: 'Ultima vendita',
            key: 'last_sale',
            sortable: false,
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
            title={'Elenco Records'}
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

            <div className='mt-5 border p-4 bg-gray-50'>
                <div className='grid items-start gap-x-2 grid-cols-2 md:grid-cols-6'>
                    <div>
                        <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                            Artista
                        </InputLabel>
                        <Autocomplete
                            placeholder={'Digita...'}
                            routeName='artist.index'
                            //initialValue={filters.artist_id}
                            initialValue={selectedArtist} // Usa l'oggetto completo invece dell'ID
                            value={selectedArtist}
                            className={'mt-1'}
                            inputClassName={'max-h-[40px]'}
                            onChange={item => {
                                const newFilter = {
                                    ...filters,
                                    artist_id: item && typeof item === 'object' && item.id ? item.id : '',
                                };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, artist_id: '' };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'record', filter: newFilter });
                            }}
                        />
                    </div>

                    <div>
                        <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                            Titolo
                        </InputLabel>
                        <input
                            type='text'
                            className='mt-1 border-gray-300 shadow-sm text-sm rounded-md w-full max-h-[40px]'
                            placeholder='Digita...'
                            value={filters.title}
                            onChange={e => {
                                setFilters({ ...filters, title: e.target.value });
                            }}
                            onKeyDown={e => {
                                if (e.key === 'Enter') {
                                    Helpers.getItems({ routeName: 'record', filter: filters });
                                }
                            }}
                            onBlur={() => {
                                Helpers.getItems({ routeName: 'record', filter: filters });
                            }}
                        />
                    </div>

                    <div>
                        <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                            Formato
                        </InputLabel>
                        <Autocomplete
                            placeholder={'Digita...'}
                            routeName='format.index'
                            initialValue={selectedFormat}
                            value={selectedFormat}
                            className={'mt-1'}
                            onChange={item => {
                                const newFilter = {
                                    ...filters,
                                    format_id: item && typeof item === 'object' && item.id ? item.id : '',
                                };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, format_id: '' };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'record', filter: newFilter });
                            }}
                        />
                    </div>

                    <div>
                        <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                            Etichetta
                        </InputLabel>
                        <Autocomplete
                            placeholder={'Digita...'}
                            routeName='label.index'
                            initialValue={selectedLabel}
                            value={selectedLabel}
                            className={'mt-1'}
                            onChange={item => {
                                const newFilter = {
                                    ...filters,
                                    label_id: item && typeof item === 'object' && item.id ? item.id : '',
                                };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, label_id: '' };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'record', filter: newFilter });
                            }}
                        />
                    </div>

                    <div>
                        <Combo
                            items={types}
                            label={'Nuovo/Usato'}
                            displayValue={'description'}
                            selected={filters.type && types.find(type => type.value === filters.type)}
                            onChange={type => {
                                const newFilter = { ...filters, type: type.value };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, type: '' };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>

                    <div>
                        <Combo
                            items={diskstatus}
                            label={'Condizione Disco'}
                            displayValue={'description'}
                            selected={
                                filters.disk_status !== ''
                                    ? diskstatus.find(
                                          disk_status => parseInt(disk_status.value) === parseInt(filters.disk_status),
                                      )
                                    : null
                            }
                            onChange={disk_status => {
                                const newFilter = { ...filters, disk_status: parseInt(disk_status.value) };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, disk_status: '' };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>

                    <div>
                        <Combo
                            items={coverstatus}
                            label={'Condizione Copertina'}
                            displayValue={'description'}
                            selected={
                                filters.cover_status !== ''
                                    ? coverstatus.find(
                                          cover_status =>
                                              parseInt(cover_status.value) === parseInt(filters.cover_status),
                                      )
                                    : null
                            }
                            onChange={cover_status => {
                                const newFilter = { ...filters, cover_status: parseInt(cover_status.value) };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, cover_status: '' };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>

                    <div className=''>
                        <Combo
                            items={forsalediscogsstatus}
                            label={'Su Discogs'}
                            displayValue={'description'}
                            selected={
                                filters.for_sale_on_discogs !== ''
                                    ? forsalediscogsstatus.find(
                                          for_sale_on_discogs =>
                                              parseInt(for_sale_on_discogs.value) ===
                                              parseInt(filters.for_sale_on_discogs),
                                      )
                                    : null
                            }
                            onChange={for_sale_on_discogs => {
                                const newFilter = {
                                    ...filters,
                                    for_sale_on_discogs: parseInt(for_sale_on_discogs.value),
                                };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, for_sale_on_discogs: '' };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                        />
                    </div>

                    <div>
                        <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                            Fornitore
                        </InputLabel>
                        <Autocomplete
                            placeholder={'Digita...'}
                            routeName='supplier.index'
                            initialValue={selectedSupplier}
                            value={selectedSupplier}
                            className={'mt-1'}
                            onChange={item => {
                                const newFilter = {
                                    ...filters,
                                    supplier_id: item && typeof item === 'object' && item.id ? item.id : '',
                                };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}
                            onClear={() => {
                                const newFilter = { ...filters, supplier_id: '' };
                                setFilters(newFilter);
                                Helpers.getItems({ routeName: 'record', filter: newFilter });
                            }}
                        />
                    </div>

                    <div>
                        <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                            Tipo Prezzo
                        </InputLabel>
                        <select
                            className='mt-1 border-gray-300 shadow-sm text-sm rounded-md w-full'
                            value={filters.price_type}
                            onChange={e => {
                                const newFilter = {
                                    ...filters,
                                    price_type: e.target.value,
                                    // Clear price min/max when empty option is selected
                                    ...(e.target.value === '' && { price_min: '', price_max: '' }),
                                };
                                setFilters(newFilter);
                                // Only send request if at least one price input is populated
                                if (filters.price_min || filters.price_max) {
                                    Helpers.getItems({
                                        routeName: 'record',
                                        filter: newFilter,
                                    });
                                }
                            }}>
                            <option value=''>Nessuno</option>
                            <option value='purchase_price'>Prezzo acquisto</option>
                            <option value='wholesale_price'>Prezzo ingrosso</option>
                            <option value='retail_price'>Prezzo dettaglio</option>
                        </select>
                    </div>

                    {filters.price_type && (
                        <>
                            <div>
                                <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                                    Prezzo Min (≥)
                                </InputLabel>
                                <input
                                    type='number'
                                    step='0.01'
                                    className='mt-1 border-gray-300 shadow-sm text-sm rounded-md w-full'
                                    placeholder='0.00'
                                    value={filters.price_min}
                                    onChange={e => {
                                        const newFilter = { ...filters, price_min: e.target.value };
                                        setFilters(newFilter);
                                    }}
                                    onBlur={() => {
                                        Helpers.getItems({
                                            routeName: 'record',
                                            filter: filters,
                                        });
                                    }}
                                />
                            </div>

                            <div>
                                <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                                    Prezzo Max (≤)
                                </InputLabel>
                                <input
                                    type='number'
                                    step='0.01'
                                    className='mt-1 border-gray-300 shadow-sm text-sm rounded-md w-full'
                                    placeholder='0.00'
                                    value={filters.price_max}
                                    onChange={e => {
                                        const newFilter = { ...filters, price_max: e.target.value };
                                        setFilters(newFilter);
                                    }}
                                    onBlur={() => {
                                        Helpers.getItems({
                                            routeName: 'record',
                                            filter: filters,
                                        });
                                    }}
                                />
                            </div>
                        </>
                    )}

                    <div>
                        <InputLabel className='block text-sm font-medium leading-6 text-gray-700 cursor-pointer'>
                            Ingrosso
                        </InputLabel>
                        <select
                            className='mt-1 border-gray-300 shadow-sm text-sm w-full'
                            value={filters.wholesale ?? ''}
                            onChange={e => {
                                const value = e.target.value === '' ? '' : e.target.value;
                                const newFilter = { ...filters, wholesale: value };
                                setFilters(newFilter);
                                Helpers.getItems({
                                    routeName: 'record',
                                    filter: newFilter,
                                });
                            }}>
                            <option value=''>Tutti</option>
                            <option value='yes'>Sì</option>
                            <option value='no'>No</option>
                        </select>
                    </div>

                    <div>
                        {Object.entries(filters).some(([key, value]) => value !== '' && value !== false) && (
                            <Button
                                type='button'
                                variant={'link'}
                                onClick={() => router.visit(route('record.index'))}
                                className='text-sm text-primary underline mt-6'>
                                <X className='me-1 h-4 w-4' />
                                Pulisci filtri
                            </Button>
                        )}
                    </div>
                </div>
            </div>

            <div className='pt-5 md:pt-0 px-0 md:grid md:grid-cols-2 md:gap-3 my-5'>
                <div className='hidden md:block'></div>

                <div className='grid grid-cols-2 gap-2 md:gap-0 md:flex md:justify-end'>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type='button'
                                className='bg-primary hover:bg-primary/90 md:mr-5'
                                disabled={isExporting}>
                                <Download className='mr-2' />
                                {isExporting ? 'Esportazione...' : 'Esporta'}
                                <ChevronDown className='ml-2 h-4 w-4' />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align='end'>
                            <DropdownMenuItem onClick={() => handleExport(false, false)}>
                                Export Default
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => handleExport(false, true)}>
                                Export Maiuscolo
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <Button
                        type='button'
                        className='bg-primary hover:bg-primary/90 md:mr-5'
                        disabled={isExporting}
                        onClick={async () => {
                            const cleanedFilters = Helpers.indexFilters.cleanUp(filters);
                            setShowExportModal(true);
                            await startExport(cleanedFilters, true);
                        }}>
                        <FileSpreadsheet className='mr-2' />
                        {isExporting ? 'Esportazione ingrosso...' : 'Esporta ingrosso'}
                    </Button>

                    <Button
                        type='button'
                        className='bg-primary hover:bg-primary/90 md:mr-5'
                        onClick={() => {
                            router.visit(route('records-import.index'));
                        }}>
                        <FileUp className='mr-2' /> {'Importa'}
                    </Button>

                    <SecondaryButton
                        type='button'
                        onClick={() => window.open(route('records-import.download-template'), '_blank')}
                        title='Scarica Template'
                        className='flex items-center gap-2 md:mr-5'>
                        <FileSpreadsheet className='h-4 w-4' />
                        Template
                    </SecondaryButton>

                    <Button
                        type='button'
                        className='bg-destructive hover:bg-destructive/90'
                        onClick={() => {
                            router.visit(route('recordstrash.index'));
                        }}>
                        <Trash className='mr-2' /> {'Cestino'}
                    </Button>
                </div>
            </div>

            <AtomicaTable
                selectedItemsActions={selectedItemsActions}
                selected={selectedItems}
                selectable={UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_clients'] })}
                onClearSelection={item => setSelectedItems(selectedItems.filter(record => item.id !== record))}
                onSelect={item => setSelectedItems(prev => [...prev, item.id])}
                actionText={'Crea Record'}
                action={() => router.visit(route('record.create'))}
                headers={tableHeaders as Array<TableHeaderType>}
                data={records}
                toggleSelectAll={(event: boolean) =>
                    setSelectedItems(getUpdatedSelection(records.data, event, selectedItems))
                }
                onSort={sort =>
                    Helpers.getItems({
                        routeName: 'record',
                        page: records.current_page,
                        per_page: records.per_page,
                        sort_by: sort,
                    })
                }
                sortBy={route().params?.sort ? String(route().params?.sort) : ''}
            />

            <ExportProgressModal
                isOpen={showExportModal}
                progress={progress}
                onClose={() => setShowExportModal(false)}
                onDownload={downloadExport}
                onCancel={cancelExport}
                onReset={resetExport}
            />
        </Authenticated>
    );
}
