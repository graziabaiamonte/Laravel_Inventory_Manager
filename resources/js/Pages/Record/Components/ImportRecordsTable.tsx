import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Card } from '@/Components/ui/card';
import React, { useState, useEffect, useRef } from 'react';
import type { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { Plus, FileUp, Check, Trash, X } from 'lucide-react';
import { router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import { ImportRecordItem, Record } from '@/types';
import Input from '@/Components/atomica/Forms/Input';
import CurrencyInput from '@/Components/atomica/Forms/CurrencyInput';
import Combo from '@/Components/atomica/Utils/Combo';
import { cleanBarcode } from '@/lib/utils';

class PreviewRecord {
    barcode: string = '';
    cat_number: string = '';
    artist: string = '';
    title: string = '';
    format: { id?: number; name?: string } | string = '';
    label: { id?: number; name?: string } | string = '';
    purchase_price: number = 0;

    //new fields
    supplier: { id?: number; name?: string } | string = '';
    condition_disk: string = '';
    condition_cover: string = '';
    comments: string = '';
    description: string = '';
    is_deleted: boolean = false;
    is_discogs_deleted: boolean = false;
}

const tableHeaders: Array<TableHeaderType> = [
    {
        label: 'Soft Delete',
        key: 'soft_delete',
    },
    {
        label: 'Delete',
        key: 'delete',
    },
    {
        label: 'D-Delete',
        key: 'd_delete',
    },
    {
        label: 'Record ID',
        key: 'record_id',
    },
    {
        label: 'RRUID',
        key: 'record_id',
    },
    {
        label: 'Cat. #',
        key: 'cat_number',
    },
    {
        label: 'Barcode',
        key: 'barcode',
    },
    {
        label: 'Artista',
        key: 'artist.name',
    },
    {
        label: 'Titolo',
        key: 'title',
    },
    {
        label: 'Release ID',
        key: 'release_id',
    },
    {
        label: 'Fmt',
        key: 'format.name',
    },
    {
        label: 'Etichetta',
        key: 'label.name',
    },
    {
        label: 'Prezzo di acquisto',
        key: 'purchase_price',
    },
    {
        label: 'Prezzo ingrosso',
        key: 'wholesale_price',
    },
    {
        label: 'Prezzo dettaglio',
        key: 'retail_price',
    },
    {
        label: 'Condizione Disco',
        key: 'condition_disk',
    },
    {
        label: 'Condizione Copertina',
        key: 'condition_cover',
    },
    { label: 'Stocks', key: 'stocks_tmp' },

    {
        label: 'Commenti',
        key: 'comments',
    },
    {
        label: 'Descrizione',
        key: 'description',
    },
    {
        label: '',
        key: '', // Empty key for action column
    },
];

interface AttachRecordsTableProps {
    data: TableData;
    description?: string;
    onRecordsUpdate?: (records: ImportRecordItem[]) => void;
    onFileSelect?: (file: File) => void;
    importedRecords?: ImportRecordItem[];
    errors?: { [key: string]: string | undefined };
    isEditing?: boolean;
    isDraftImport?: boolean; // Add this prop to indicate if the import is in draft mode
    coverStatus: Array<any>;
    diskStatus: Array<any>;
    importId?: number; // Add this prop for the import ID
}

export const getInitialTableData = (): TableData => ({
    data: [],
    meta: {
        current_page: 1,
        from: 0,
        last_page: 1,
        links: [],
        per_page: 10,
        to: 0,
        path: '',
        total: 0,
        onPagination: () => {},
        perPage: [10],
    },
    current_page: 1,
    from: 0,
    last_page: 1,
    links: [],
    per_page: 10,
    to: 0,
    path: '',
    total: 0,
    first_page_url: undefined,
    last_page_url: undefined,
    next_page_url: undefined,
    prev_page_url: undefined,
    onPagination: () => {},
    perPage: [10],
});

export default function ImportRecordsTable({
    data,
    description,
    onRecordsUpdate,
    onFileSelect,
    importedRecords,
    errors,
    isEditing,
    coverStatus,
    diskStatus,
    importId,
}: AttachRecordsTableProps) {
    const getTableHeaders = () => {
        if (isEditing) {
            return tableHeaders.filter(header => {
                return (
                    header.key != 'soft_delete' &&
                    header.key != 'd_delete' &&
                    header.key != 'delete' &&
                    header.key != ''
                );
            });
        }
        return tableHeaders;
    };

    const currentTableHeaders = getTableHeaders();

    // Add debug log here to see all importedRecords
    // console.log('AttachRecordsTable importedRecords:', importedRecords);

    //const [previewData, setPreviewData] = useState<Record[]>([]);
    const [filters, setFilters] = useState(new PreviewRecord());
    const [editableRecords, setEditableRecords] = useState<{ [key: string]: ImportRecordItem }>({});

    const [showPreview, setShowPreview] = useState(false);

    const [openStocks, setOpenStocks] = useState<{ [key: string]: boolean }>({});

    // Add a ref to track the latest changed record
    const latestRecord = React.useRef<ImportRecordItem | null>(null);

    // Add file input ref
    const fileInputRef = useRef<HTMLInputElement>(null);

    const [selectedFile, setSelectedFile] = useState<File | null>(null); // Add state for selected file

    // State for error styling
    const [showErrorStyle, setShowErrorStyle] = useState(false);

    const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setSelectedFile(file);
            // Clear any manually added records
            setEditableRecords({});
            //setPreviewData([]);
            onFileSelect?.(file);
        }

        // Clear the file input after processing
        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    // Add useEffect to initialize editableRecords when importedRecords are present
    useEffect(() => {
        if (importedRecords?.length) {
            // First, update editable records state
            const records = importedRecords.reduce(
                (acc, record, index) => {
                    // Spread the record to make it modifiable if necessary,
                    // though we are not directly modifying it here before assignment anymore.
                    const modifiableRecord = { ...record };

                    // The type WholesaleInRecord should now correctly handle null values for optional prices.

                    acc[index.toString()] = modifiableRecord as ImportRecordItem;
                    return acc;
                },
                {} as { [key: string]: ImportRecordItem },
            );
            setEditableRecords(records);
            // THEN: Immediately notify the parent component with the initial imported records
            const initialRecordsArray = Object.values(records);
            if (onRecordsUpdate) {
                onRecordsUpdate(initialRecordsArray);
                // console.log('Initial imported records sent to parent:', initialRecordsArray); // Add log
            }
        }
    }, [importedRecords]); // Keep onRecordsUpdate out of dependencies to avoid potential loops if its reference changes

    const handleAddToMainTable = () => {
        if (!onRecordsUpdate) return;

        const ImportRecord: ImportRecordItem = {
            id: 0,
            draft: 1,
        };

        // Calculate the next state FIRST
        const currentRecords = Object.values(editableRecords);
        const newRecordsArray = [...currentRecords, ImportRecord];
        const newEditableRecordsState = newRecordsArray.reduce(
            (acc, rec, idx) => {
                acc[idx.toString()] = rec;
                return acc;
            },
            {} as { [key: string]: ImportRecordItem },
        );

        // Update local state
        setEditableRecords(newEditableRecordsState);
        // Notify parent with the calculated array
        onRecordsUpdate(newRecordsArray);
    };

    const handleRecordChange = (key: string, field: keyof ImportRecordItem, value: string | number | boolean) => {
        const record = editableRecords[key];
        if (!record) return;

        let processedValue: string | number | boolean = value;

        if (['quantity', 'discount', 'vat'].includes(field as string)) {
            processedValue = parseInt(value as string) || 0;
        } else if (['unit_price', 'wholesale_price', 'purchase_price', 'total_price'].includes(field as string)) {
            // Keep as string to preserve decimal format (e.g., "140.00")
            // parseFloat() strips decimals and causes Money to interpret as cents
            processedValue = String(value);
        } else if (['soft_delete', 'delete', 'd_delete'].includes(field as string)) {
            processedValue = Boolean(value);
        } else if (field === 'barcode') {
            // Apply barcode cleanup
            processedValue = cleanBarcode(String(value));
        }

        const updatedRecord = {
            ...record,
            [field]: processedValue,
        };

        // Calculate the next state FIRST
        const newEditableRecordsState = {
            ...editableRecords,
            [key]: updatedRecord,
        };
        const newRecordsArray = Object.values(newEditableRecordsState);

        // Update local state
        setEditableRecords(newEditableRecordsState);
        // Notify parent with the calculated array
        if (onRecordsUpdate) {
            onRecordsUpdate(newRecordsArray);
        }
    };

    const handleRemoveFromMainTable = (key: string) => {
        // Calculate the next state FIRST
        const currentEditableRecords = { ...editableRecords };
        delete currentEditableRecords[key];
        const newRecordsArray = Object.values(currentEditableRecords);

        // Re-index the state object if necessary (optional but cleaner)
        const newEditableRecordsState = newRecordsArray.reduce(
            (acc, rec, idx) => {
                acc[idx.toString()] = rec;
                return acc;
            },
            {} as { [key: string]: ImportRecordItem },
        );

        // Update local state
        setEditableRecords(newEditableRecordsState);
        // Notify parent with the calculated array
        if (onRecordsUpdate) {
            onRecordsUpdate(newRecordsArray);
        }
    };

    // New method for removing records from the database in edit mode
    const handleRemoveFromDBTable = (key: string) => {
        if (!importId) {
            console.error('Import ID is required for deleting records from database');
            return;
        }

        const record = editableRecords[key];
        if (!record?.id) {
            console.error('Record ID is required for deleting records from database');
            return;
        }

        // Call the delete route
        router.delete(route('records-import.delete-record', [importId, record.id]), {
            onSuccess: () => {
                // After successful deletion, also remove from local state
                const currentEditableRecords = { ...editableRecords };
                delete currentEditableRecords[key];
                const newRecordsArray = Object.values(currentEditableRecords);

                // Re-index the state object if necessary (optional but cleaner)
                const newEditableRecordsState = newRecordsArray.reduce(
                    (acc, rec, idx) => {
                        acc[idx.toString()] = rec;
                        return acc;
                    },
                    {} as { [key: string]: ImportRecordItem },
                );

                // Update local state
                setEditableRecords(newEditableRecordsState);
                // Notify parent with the calculated array
                if (onRecordsUpdate) {
                    onRecordsUpdate(newRecordsArray);
                }
            },
            onError: errors => {
                console.error('Error deleting record:', errors);
            },
        });
    };

    useEffect(() => {
        if (errors?.file) {
            setSelectedFile(null);
            setShowErrorStyle(true);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
            // Remove error styling after 2 seconds
            const timer = setTimeout(() => {
                setShowErrorStyle(false);
            }, 2000);

            return () => clearTimeout(timer);
        }
    }, [errors]);

    function handleAddRow() {
        const newRecord = {
            id: 0, // Required by ImportRecordItem interface
            record_id: 0,
            draft: 1,
            cat_number: '',
            barcode: '',
            artist: '',
            title: '',
            release_id: '', // Add release_id field
            format: '',
            label: '',
            purchase_price: 0,
            wholesale_price: 0,
            retail_price: 0,
            //new fields
            supplier: '',
            condition_disk: '',
            condition_cover: '',
            comments: '',
            description: '',
            soft_delete: false,
            delete: false,
            d_delete: false,
        } as unknown as ImportRecordItem; // Type assertion to work with the flexible interface

        // Get current records as an array and prepend the new record
        const currentRecordsArray = Object.values(editableRecords);
        const newRecordsArray = [newRecord, ...currentRecordsArray];

        // Re-index all records with proper sequential keys
        const records = newRecordsArray.reduce(
            (acc, record, index) => {
                acc[index.toString()] = record;
                return acc;
            },
            {} as { [key: string]: ImportRecordItem },
        );

        setEditableRecords(records);

        // Notify parent component if needed
        if (onRecordsUpdate) {
            onRecordsUpdate(newRecordsArray);
        }
    }

    return (
        <div className='space-y-4'>
            {/* Actions Bar */}
            <div className='flex items-center justify-between'>
                <p className='text-sm font-medium text-gray-900'>{description}</p>
                <div className='flex gap-2'>
                    {/* Control file upload and add record buttons */}
                    <div className='flex gap-2'>
                        {/* Show file upload if no manually added records */}
                        {(!isEditing && !Object.keys(editableRecords).length) || errors?.file ? (
                            <>
                                <input
                                    type='file'
                                    ref={fileInputRef}
                                    className='hidden'
                                    accept='.xlsx'
                                    onChange={handleFileUpload}
                                />
                                <div className='flex items-center gap-2'>
                                    {selectedFile && <span className='me-2 text-green-700'>{selectedFile.name}</span>}
                                    <PrimaryButton
                                        type='button'
                                        onClick={() => fileInputRef.current?.click()}
                                        title='Carica XLS'
                                        className={`${selectedFile ? 'bg-green-600 hover:bg-green-700' : ''} ${
                                            showErrorStyle
                                                ? 'bg-red-600 hover:bg-red-700 transition-colors duration-500'
                                                : ''
                                        }`}>
                                        <FileUp className='h-5 w-5' />
                                    </PrimaryButton>
                                </div>
                            </>
                        ) : null}

                        {/* Show add record button if no file selected */}
                        {!isEditing && (
                            <PrimaryButton
                                type='button'
                                onClick={() => handleAddRow()}
                                title={'Aggiungi Disco'}
                                className={showPreview ? 'bg-red-600 hover:bg-red-700' : ''}>
                                {<Plus className='h-5 w-5' />}
                            </PrimaryButton>
                        )}
                    </div>
                </div>
            </div>

            {/* Main Table */}
            <Card>
                <div className='relative overflow-x-hidden' style={{ maxWidth: '100%' }}>
                    <div className='overflow-x-hidden'>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {currentTableHeaders.map((header, idx) => (
                                        <TableHead key={idx}>{header.label}</TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {Object.entries(editableRecords).map(([key, item]) => (
                                    <TableRow key={key} className={`${item.record_id ? 'bg-gray-100' : ''}`}>
                                        {currentTableHeaders.map((header, idx) => (
                                            <TableCell key={idx}>
                                                <div
                                                    className={
                                                        header.key &&
                                                        !['soft_delete', 'delete', 'd_delete'].includes(header.key)
                                                            ? 'w-[150px]'
                                                            : ''
                                                    }>
                                                    {header.key ? (
                                                        (() => {
                                                            // Column: Unit Price, Wholesale Price, Retail Price (Currency Inputs for manually added records)
                                                            if (
                                                                //!item.record_id &&
                                                                //(!isEditing && header.key === 'unit_price') ||
                                                                !isEditing &&
                                                                [
                                                                    'unit_price',
                                                                    'purchase_price',
                                                                    'wholesale_price',
                                                                    'retail_price',
                                                                ].includes(header.key)
                                                            ) {
                                                                const valueKey = header.key as keyof ImportRecordItem;
                                                                const currentValue = item[valueKey];
                                                                const valueForInput =
                                                                    currentValue !== null && currentValue !== undefined
                                                                        ? String(currentValue)
                                                                        : '0';

                                                                return (
                                                                    <CurrencyInput
                                                                        value={valueForInput}
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            handleRecordChange(
                                                                                key,
                                                                                valueKey,
                                                                                e.target.value,
                                                                            )
                                                                        }
                                                                        name={`${header.key}_${key}`}
                                                                        id={`${header.key}_${key}`}
                                                                        className='w-full h-8'
                                                                    />
                                                                );
                                                            }

                                                            if (!isEditing && header.key === 'record_id') {
                                                                return (
                                                                    <Input
                                                                        type='number'
                                                                        value={String(item.record_id ?? 0)}
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            handleRecordChange(
                                                                                key,
                                                                                'record_id',
                                                                                parseInt(e.target.value) || 0,
                                                                            )
                                                                        }
                                                                        name={`record_id_${key}`}
                                                                        id={`record_id_${key}`}
                                                                        className='w-full h-8'
                                                                        min='0'
                                                                    />
                                                                );
                                                            }

                                                            if (header.key === 'stocks_tmp') {
                                                                let stocks: {
                                                                    area_id: number;
                                                                    area_name: string;
                                                                    quantity: number;
                                                                }[] = [];
                                                                try {
                                                                    stocks = item.stocks_tmp
                                                                        ? JSON.parse(item.stocks_tmp)
                                                                        : [];
                                                                } catch {
                                                                    stocks = [];
                                                                }
                                                                const isOpen = openStocks[key] ?? false;
                                                                const hasStocks = stocks.length > 0;

                                                                return (
                                                                    <div className='relative flex flex-col gap-1'>
                                                                        <button
                                                                            type='button'
                                                                            className={`text-xs px-2 py-1 ${hasStocks ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'} hover:bg-green-200 transition`}
                                                                            onClick={() =>
                                                                                setOpenStocks(prev => ({
                                                                                    ...prev,
                                                                                    [key]: !isOpen,
                                                                                }))
                                                                            }>
                                                                            {hasStocks
                                                                                ? 'Stock presenti'
                                                                                : 'Stock non presenti'}
                                                                            <span className='ml-1'>
                                                                                {isOpen ? '▲' : '▼'}
                                                                            </span>
                                                                        </button>
                                                                        {isOpen && (
                                                                            <div className='absolute top-[50px]  z-30 bg-white shadow-md border rounded p-2 mt-1'>
                                                                                {hasStocks ? (
                                                                                    stocks.map((stock, idx) => (
                                                                                        <div
                                                                                            key={idx}
                                                                                            className='flex gap-2 items-center mb-1'>
                                                                                            <Input
                                                                                                type='text'
                                                                                                value={stock.area_name}
                                                                                                readOnly
                                                                                                className='w-32'
                                                                                            />
                                                                                            <Input
                                                                                                type='number'
                                                                                                value={stock.quantity}
                                                                                                // readOnly
                                                                                                onChange={(
                                                                                                    e: React.ChangeEvent<HTMLInputElement>,
                                                                                                ) => {
                                                                                                    // Aggiorna la quantità nello stock
                                                                                                    const newQuantity =
                                                                                                        parseInt(
                                                                                                            e.target
                                                                                                                .value,
                                                                                                        ) || 0;
                                                                                                    const updatedStocks =
                                                                                                        stocks.map(
                                                                                                            (
                                                                                                                s,
                                                                                                                stockIdx,
                                                                                                            ) =>
                                                                                                                stockIdx ===
                                                                                                                idx
                                                                                                                    ? {
                                                                                                                          ...s,
                                                                                                                          quantity:
                                                                                                                              newQuantity,
                                                                                                                      }
                                                                                                                    : s,
                                                                                                        );

                                                                                                    // Aggiorna il record con i nuovi stocks
                                                                                                    handleRecordChange(
                                                                                                        key,
                                                                                                        'stocks_tmp',
                                                                                                        JSON.stringify(
                                                                                                            updatedStocks,
                                                                                                        ),
                                                                                                    );
                                                                                                }}
                                                                                                className='w-16'
                                                                                            />
                                                                                        </div>
                                                                                    ))
                                                                                ) : (
                                                                                    <div className='text-xs text-gray-400'>
                                                                                        Nessuno stock presente
                                                                                    </div>
                                                                                )}
                                                                            </div>
                                                                        )}
                                                                    </div>
                                                                );
                                                            }

                                                            // Column: Text Inputs for manually added records
                                                            let fieldNameForKey = header.key;
                                                            if (header.key === 'artist.name')
                                                                fieldNameForKey = 'artist';
                                                            else if (header.key === 'format.name')
                                                                fieldNameForKey = 'format';
                                                            else if (header.key === 'label.name')
                                                                fieldNameForKey = 'label';
                                                            else if (header.key === 'supplier.name')
                                                                fieldNameForKey = 'supplier';

                                                            if (
                                                                //!item.record_id &&
                                                                !isEditing &&
                                                                ['artist', 'format', 'label', 'supplier'].includes(
                                                                    fieldNameForKey,
                                                                )
                                                            ) {
                                                                if (isEditing) {
                                                                    if (header.key === 'artist.name')
                                                                        fieldNameForKey = 'artist_name';
                                                                    else if (header.key === 'format.name')
                                                                        fieldNameForKey = 'format_name';
                                                                    else if (header.key === 'label.name')
                                                                        fieldNameForKey = 'label_name';
                                                                    else if (header.key === 'supplier.name')
                                                                        fieldNameForKey = 'supplier_name';
                                                                }

                                                                const fieldValue =
                                                                    item[fieldNameForKey as keyof ImportRecordItem];
                                                                const displayValue =
                                                                    typeof fieldValue === 'object' &&
                                                                    fieldValue !== null &&
                                                                    'name' in fieldValue
                                                                        ? fieldValue.name
                                                                        : fieldValue;

                                                                return (
                                                                    <Input
                                                                        type='text'
                                                                        value={String(displayValue ?? '')}
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            handleRecordChange(
                                                                                key,
                                                                                fieldNameForKey as keyof ImportRecordItem,
                                                                                e.target.value,
                                                                            )
                                                                        }
                                                                        name={`${header.key}_${key}`}
                                                                        id={`${header.key}_${key}`}
                                                                        className='w-full h-8'
                                                                    />
                                                                );
                                                            }

                                                            if (
                                                                !isEditing &&
                                                                ['cat_number', 'barcode'].includes(fieldNameForKey)
                                                            ) {
                                                                return (
                                                                    <Input
                                                                        type='text'
                                                                        value={String(
                                                                            item[
                                                                                fieldNameForKey as keyof ImportRecordItem
                                                                            ] ?? '',
                                                                        )}
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            handleRecordChange(
                                                                                key,
                                                                                fieldNameForKey as keyof ImportRecordItem,
                                                                                e.target.value,
                                                                            )
                                                                        }
                                                                        name={`${header.key}_${key}`}
                                                                        id={`${header.key}_${key}`}
                                                                        className='w-full h-8'
                                                                    />
                                                                );
                                                            }

                                                            if (
                                                                //!item.record_id &&
                                                                !isEditing &&
                                                                ['title', 'release_id'].includes(fieldNameForKey)
                                                            ) {
                                                                return (
                                                                    <Input
                                                                        type='text'
                                                                        value={String(
                                                                            item[
                                                                                fieldNameForKey as keyof ImportRecordItem
                                                                            ] ?? '',
                                                                        )}
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            handleRecordChange(
                                                                                key,
                                                                                fieldNameForKey as keyof ImportRecordItem,
                                                                                e.target.value,
                                                                            )
                                                                        }
                                                                        name={`${header.key}_${key}`}
                                                                        id={`${header.key}_${key}`}
                                                                        className='w-full h-8'
                                                                    />
                                                                );
                                                            }

                                                            // Column: Display Values for imported records
                                                            let displayValue: string | number | null | undefined = '';

                                                            /** new fields */

                                                            if (
                                                                //!item.record_id &&
                                                                !isEditing &&
                                                                ['comments', 'description'].includes(header.key)
                                                            ) {
                                                                return (
                                                                    <Input
                                                                        type='text'
                                                                        value={String(
                                                                            item[
                                                                                header.key as keyof ImportRecordItem
                                                                            ] ?? '',
                                                                        )}
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            handleRecordChange(
                                                                                key,
                                                                                header.key as keyof ImportRecordItem,
                                                                                e.target.value,
                                                                            )
                                                                        }
                                                                        name={`${header.key}_${key}`}
                                                                        id={`${header.key}_${key}`}
                                                                        className='w-full h-8'
                                                                    />
                                                                );
                                                            }

                                                            if (
                                                                //!item.record_id &&
                                                                !isEditing &&
                                                                ['condition_disk'].includes(header.key)
                                                            ) {
                                                                return (
                                                                    <Combo
                                                                        items={diskStatus}
                                                                        displayValue='description'
                                                                        selected={
                                                                            diskStatus.find(
                                                                                ds =>
                                                                                    // Primo caricamento: controlla description
                                                                                    ds.description ===
                                                                                    item.condition_disk,
                                                                            ) || diskStatus[0]
                                                                        }
                                                                        //selected={console.log(item.condition_disk)}
                                                                        onChange={selectedItem => {
                                                                            handleRecordChange(
                                                                                key,
                                                                                'condition_disk',
                                                                                selectedItem.description,
                                                                            );
                                                                        }}
                                                                    />
                                                                );
                                                            }

                                                            if (
                                                                //!item.record_id &&
                                                                !isEditing &&
                                                                ['condition_cover'].includes(header.key)
                                                            ) {
                                                                return (
                                                                    <Combo
                                                                        items={coverStatus}
                                                                        displayValue='description'
                                                                        selected={
                                                                            coverStatus.find(
                                                                                cs =>
                                                                                    cs.description ===
                                                                                    item.condition_cover,
                                                                            ) || coverStatus[0]
                                                                        }
                                                                        onChange={selectedItem => {
                                                                            handleRecordChange(
                                                                                key,
                                                                                'condition_cover',
                                                                                selectedItem.description,
                                                                            );
                                                                        }}
                                                                    />
                                                                );
                                                            }

                                                            // Aggiungi questo per i campi booleani
                                                            if (
                                                                ['soft_delete', 'delete', 'd_delete'].includes(
                                                                    header.key,
                                                                )
                                                            ) {
                                                                return (
                                                                    <Input
                                                                        type='checkbox'
                                                                        checked={Boolean(
                                                                            item[header.key as keyof ImportRecordItem],
                                                                        )}
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            handleRecordChange(
                                                                                key,
                                                                                header.key as keyof ImportRecordItem,
                                                                                e.target.checked,
                                                                            )
                                                                        }
                                                                        name={`${header.key}_${key}`}
                                                                        id={`${header.key}_${key}`}
                                                                        className='w-4 h-4 border mx-auto my-2'
                                                                    />
                                                                );
                                                            }

                                                            // Aggiungi questo per il fornitore
                                                            if (header.key === 'supplier.name') {
                                                                displayValue =
                                                                    typeof item.supplier === 'object'
                                                                        ? item.supplier?.name
                                                                        : (item.supplier ?? item.supplier_name ?? '');
                                                            }

                                                            /** end new fields **/

                                                            // Handle record_id display
                                                            if (header.key === 'record_id') {
                                                                displayValue = item.record_id ?? '';
                                                            } else if (header.key === 'release_id') {
                                                                displayValue = item.release_id ?? '';
                                                            } else if (header.key === 'artist.name') {
                                                                displayValue =
                                                                    typeof item.artist === 'object'
                                                                        ? item.artist?.name
                                                                        : (item.artist ?? item.artist_name ?? '');
                                                            } else if (header.key === 'format.name') {
                                                                displayValue =
                                                                    typeof item.format === 'object'
                                                                        ? item.format?.name
                                                                        : (item.format ?? item.format_name ?? '');
                                                            } else if (header.key === 'label.name') {
                                                                displayValue =
                                                                    typeof item.label === 'object'
                                                                        ? item.label?.name
                                                                        : (item.label ?? item.label_name ?? '');
                                                            } else if (header.key.includes('.') && item) {
                                                                const [parent, child] = header.key.split('.');
                                                                const parentObj =
                                                                    item[parent as keyof ImportRecordItem];
                                                                if (
                                                                    typeof parentObj === 'object' &&
                                                                    parentObj !== null &&
                                                                    child in parentObj
                                                                ) {
                                                                    displayValue =
                                                                        parentObj[child as keyof typeof parentObj];
                                                                }
                                                            } else {
                                                                displayValue =
                                                                    item[header.key as keyof ImportRecordItem] ??
                                                                    item?.[header.key as keyof ImportRecordItem];
                                                            }
                                                            return String(displayValue ?? '');
                                                        })()
                                                    ) : (
                                                        <div className='flex gap-2 justify-center'>
                                                            <PrimaryButton
                                                                type={'button'}
                                                                onClick={() =>
                                                                    importId
                                                                        ? handleRemoveFromDBTable(key)
                                                                        : handleRemoveFromMainTable(key)
                                                                }
                                                                className='w-8 h-8 !p-0 flex items-center justify-center bg-red-600 hover:bg-red-700'>
                                                                <Trash className='h-4 w-4' />
                                                            </PrimaryButton>
                                                        </div>
                                                    )}
                                                </div>
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </Card>
        </div>
    );
}
