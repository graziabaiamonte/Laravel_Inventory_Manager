import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Card } from '@/Components/ui/card';
import React, { useState, useEffect, useRef } from 'react';
import type { TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { Plus, FileUp, X, FileSpreadsheet, ArrowDown, Trash, Pencil } from 'lucide-react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { WholesaleInRecord, Record, AreaQuantity } from '@/types';
import Input from '@/Components/atomica/Forms/Input';
import CurrencyInput from '@/Components/atomica/Forms/CurrencyInput';
import { Button } from '@headlessui/react';
import Combo from '@/Components/atomica/Utils/Combo';
import RecordSearchAndPreview from '@/Components/records/RecordSearchAndPreview';
import { cleanBarcode } from '@/lib/utils';

const tableHeaders: Array<TableHeaderType> = [
    {
        label: '',
        key: '__edit',
    },
    {
        label: 'Cat. #',
        key: 'cat_number',
    },
    {
        label: 'Artista',
        key: 'artist_name',
    },
    {
        label: 'Titolo',
        key: 'title',
    },
    {
        label: 'Barcode',
        key: 'barcode',
    },
    {
        label: 'Quantità',
        key: 'quantity',
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
        key: 'unit_price',
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
        label: 'Sconto %',
        key: 'discount',
    },
    {
        label: 'iva',
        key: 'vat',
    },
    {
        label: 'Cond. disco',
        key: 'condition_disk',
    },
    {
        label: 'Cond. copertina',
        key: 'condition_cover',
    },
    {
        label: 'Release ID (Discogs)',
        key: 'release_id',
    },
    {
        label: 'For sale on Discogs',
        key: 'for_sale_on_discogs',
    },
    {
        label: 'Discogs ID',
        key: 'discogs_id',
    },
    {
        label: 'totale',
        key: 'total_price',
    },
    {
        label: '',
        key: '', // Empty key for action column
    },
];

interface AttachRecordsTableProps {
    description?: string;
    onRecordsUpdate?: (records: WholesaleInRecord[]) => void;
    onFileSelect?: (file: File) => void;
    importedRecords?: WholesaleInRecord[];
    errors?: { [key: string]: string | undefined };
    isEditing?: boolean;
    disabled?: boolean; // Add disabled prop to prevent editing
    areas?: Array<any>; // eslint-disable-line @typescript-eslint/no-explicit-any
    types_status?: Array<any>; // eslint-disable-line @typescript-eslint/no-explicit-any
}

export default function AttachRecordsTable({
    description,
    onRecordsUpdate,
    onFileSelect,
    importedRecords,
    errors,
    isEditing,
    disabled = false,
    areas,
    types_status,
}: AttachRecordsTableProps) {
    const [showPreview, setShowPreview] = useState(false);
    const [editableRecords, setEditableRecords] = useState<{ [key: string]: WholesaleInRecord }>({});
    const [openQuantity, setOpenQuantity] = useState<{ [key: string]: boolean }>({});
    // Focus the newly added (first) row's quantity input after each add
    const firstQuantityRef = useRef<{ focus: () => void } | null>(null);
    const [focusTick, setFocusTick] = useState(0);

    useEffect(() => {
        if (focusTick > 0) {
            firstQuantityRef.current?.focus();
        }
    }, [focusTick]);

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
                    // Explicitly preserve all fields including area_quantities
                    const modifiableRecord: WholesaleInRecord = {
                        ...record,
                        // Explicitly ensure area_quantities is preserved
                        area_quantities: record.area_quantities || [],
                    };

                    acc[index.toString()] = modifiableRecord;
                    return acc;
                },
                {} as { [key: string]: WholesaleInRecord },
            );
            setEditableRecords(records);

            // Only notify parent if this is NOT an editing scenario to avoid infinite loops
            // In editing mode, the parent already has the records, so we don't need to notify
            if (!isEditing && onRecordsUpdate) {
                const initialRecordsArray = Object.values(records);
                onRecordsUpdate(initialRecordsArray);
            }

            // Clear file state when records are imported successfully
            setSelectedFile(null);
            // Clear the file input as well
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
        // DO NOT clear editableRecords when importedRecords is empty - manually added records should persist
        // If importedRecords is undefined, do nothing (keep existing editableRecords)
        // DO NOT include onRecordsUpdate in dependencies to avoid infinite loop
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [importedRecords, isEditing]);

    // Callback for record selection from shared component
    const handleRecordSelect = (record: Record, selectedQuantity: number = 1) => {
        if (!onRecordsUpdate) return;

        const quantity = selectedQuantity;
        const unitPrice = record.purchase_price ?? 0;
        const discount = 0;

        const total_price = calculateTotalPrice(quantity, unitPrice, discount);

        const wholesaleInRecord: WholesaleInRecord = {
            // Don't set id for new records - let the backend assign it
            record_id: record.id,
            quantity: quantity,
            unit_price: unitPrice,
            discount: discount,
            total_price: total_price,
            wholesale_price: record.wholesale_price || 0,
            retail_price: record.retail_price || 0,
            vat: '22',
            parent_record: record,
            area_quantities: [], // Initialize empty area_quantities for manually added records
            condition_disk: record.disk_status_name || '',
            condition_cover: record.cover_status_name || '',
            release_id: record.release_id || '',
            for_sale_on_discogs: false,
            discogs_id: record.discogs_id || '', // Use record.discogs_id
        };

        const currentRecords = Object.values(editableRecords);
        // Prepend the new record so the most recently added appears as the first row
        const newRecordsArray = [wholesaleInRecord, ...currentRecords];
        const newEditableRecordsState = newRecordsArray.reduce(
            (acc, rec, idx) => {
                acc[idx.toString()] = rec;
                return acc;
            },
            {} as { [key: string]: WholesaleInRecord },
        );

        // Update local state
        setEditableRecords(newEditableRecordsState);
        setFocusTick(t => t + 1);
        // Notify parent with the calculated array
        onRecordsUpdate(newRecordsArray);
    };

    // Callback for record removal from shared component
    // Nothing to do on removal, the preview handles its own state
    const handleRecordRemove = () => {};

    const handleRecordChange = (
        key: string,
        field: keyof WholesaleInRecord,
        value: string | number | AreaQuantity[] | boolean,
    ) => {
        const record = editableRecords[key];
        if (!record) return;

        let processedValue: string | number | AreaQuantity[] | boolean = value;

        if (Array.isArray(value) && field === 'area_quantities') {
            processedValue = value;
            // const totalQuantity = value.reduce((sum, aq) => sum + (aq.quantity || 0), 0);
            // The total quantity is calculated from area quantities, but we don't need to store it
            // as the main quantity field should remain independent

            //record.quantity = totalQuantity;
        } else if (typeof value === 'boolean') {
            // Handle boolean values for checkboxes
            processedValue = value;
        } else if (typeof value === 'string' || typeof value === 'number') {
            const stringValue = String(value);

            if (field === 'quantity' || field === 'discount') {
                const parsed = parseInt(stringValue, 10);
                processedValue = isNaN(parsed) ? 0 : parsed;
            } else if (
                field === 'unit_price' ||
                field === 'wholesale_price' ||
                field === 'total_price' ||
                field === 'retail_price'
            ) {
                // Keep as string to preserve decimal format (e.g., "140.00")
                // parseFloat() strips decimals and causes Money to interpret as cents
                processedValue = stringValue;
            } else if (field === 'vat') {
                processedValue = stringValue;
            } else if (field === 'barcode') {
                // Apply barcode cleanup
                processedValue = cleanBarcode(stringValue);
            } else {
                processedValue = stringValue;
            }
        } else {
            return; // Invalid value type
        }

        const updatedRecord: WholesaleInRecord = {
            ...record,
            [field]: processedValue,
        };

        // Only preserve area_quantities if we're not updating them
        if (field !== 'area_quantities') {
            updatedRecord.area_quantities = record.area_quantities || [];
        }

        // Recalculate total price if necessary
        if (['quantity', 'unit_price', 'discount', 'area_quantities'].includes(field)) {
            let qVal = updatedRecord.quantity;
            const upVal = updatedRecord.unit_price;
            const dVal = updatedRecord.discount;

            if (Array.isArray(value) && value.length > 0 && field === 'area_quantities') {
                qVal = value.reduce((sum, aq) => sum + (aq.quantity || 0), 0);
            }

            const numUnitPrice = typeof upVal === 'string' ? parseFloat(upVal.replace(/,/g, '')) : Number(upVal);

            updatedRecord.total_price = calculateTotalPrice(qVal, isNaN(numUnitPrice) ? 0 : numUnitPrice, dVal);
        }

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

    const calculateTotalPrice = (quantity: number, unitPrice: number, discount: number) => {
        const total = quantity * unitPrice * (1 - discount / 100);
        return Number(total.toFixed(2)); // Round to 2 decimal places and convert back to number
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
            {} as { [key: string]: WholesaleInRecord },
        );

        // Update local state
        setEditableRecords(newEditableRecordsState);
        // Notify parent with the calculated array
        if (onRecordsUpdate) {
            onRecordsUpdate(newRecordsArray);
        }
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

    return (
        <div className='space-y-4'>
            {/* Actions Bar */}
            <div className='flex items-center justify-between'>
                <p className='text-sm font-medium text-gray-900'>{description}</p>
                <div className='flex gap-2'>
                    {/* Control file upload and add record buttons */}
                    {!disabled && (
                        <div className='flex gap-2'>
                            {/* Template download button - shown before file upload */}
                            {(!Object.keys(editableRecords).length || errors?.file || isEditing) && (
                                <SecondaryButton
                                    type='button'
                                    onClick={() => window.open(route('wholesale-in.download-template'), '_blank')}
                                    title='Scarica Template'
                                    className='flex items-center gap-2'>
                                    <FileSpreadsheet className='h-5 w-5' />
                                    Template
                                </SecondaryButton>
                            )}

                            {/* Show file upload button - allow in both create and edit modes */}
                            {!Object.keys(editableRecords).length || errors?.file || isEditing ? (
                                <>
                                    <input
                                        type='file'
                                        ref={fileInputRef}
                                        className='hidden'
                                        accept='.xlsx'
                                        onChange={handleFileUpload}
                                    />
                                    <div className='flex items-center gap-2'>
                                        {selectedFile && (
                                            <span className='me-2 text-green-700'>{selectedFile.name}</span>
                                        )}
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
                            <PrimaryButton
                                type='button'
                                onClick={() => setShowPreview(!showPreview)}
                                title={showPreview ? 'Chiudi' : 'Aggiungi Disco'}
                                disabled={Boolean(selectedFile) && !Object.keys(editableRecords).length}
                                className={showPreview ? 'bg-red-600 hover:bg-red-700' : ''}>
                                {showPreview ? <X className='h-5 w-5' /> : <Plus className='h-5 w-5' />}
                            </PrimaryButton>
                        </div>
                    )}
                </div>
            </div>

            {/* Preview Section */}
            {showPreview && (
                <RecordSearchAndPreview
                    showPreview={true}
                    onRecordSelect={handleRecordSelect}
                    onRecordRemove={handleRecordRemove}
                    types_status={types_status}
                />
            )}

            {/* Main Table */}
            <Card className={showPreview ? 'rounded-t-none' : ''}>
                <div className='relative' style={{ maxWidth: '100%' }}>
                    <div className=''>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {tableHeaders.map((header, idx) => (
                                        <TableHead key={idx}>{header.label}</TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {Object.entries(editableRecords).map(([key, item]) => (
                                    <TableRow key={key}>
                                        {tableHeaders.map((header, idx) => (
                                            <TableCell key={idx}>
                                                {header.key === '__edit' ? (
                                                    <PrimaryButton
                                                        type={'button'}
                                                        onClick={() =>
                                                            window.open(
                                                                route('record.edit', { record: item.record_id }),
                                                                '_blank',
                                                            )
                                                        }
                                                        className='w-8 h-8 !p-0 flex items-center justify-center'>
                                                        <Pencil className='h-4 w-4' />
                                                    </PrimaryButton>
                                                ) : header.key ? (
                                                    (() => {
                                                        // Column: Total Price (Display Only)
                                                        if (header.key === 'total_price') {
                                                            let totalPriceNum: number;
                                                            if (typeof item.total_price === 'string') {
                                                                totalPriceNum = parseFloat(
                                                                    item.total_price.replace(',', '.'),
                                                                );
                                                            } else if (typeof item.total_price === 'number') {
                                                                totalPriceNum = item.total_price;
                                                            } else {
                                                                totalPriceNum = 0;
                                                            }
                                                            const formattedPrice = new Intl.NumberFormat('it-IT', {
                                                                style: 'currency',
                                                                currency: 'EUR',
                                                            }).format(isNaN(totalPriceNum) ? 0 : totalPriceNum);
                                                            return formattedPrice;
                                                        }

                                                        // Column: Quantity, Discount, VAT (Numeric Inputs for all records)
                                                        if (['quantity'].includes(header.key)) {
                                                            return (
                                                                <div className='relative flex flex-col gap-2'>
                                                                    <div className='flex gap-2'>
                                                                        {Array.isArray(item.area_quantities) &&
                                                                        item.area_quantities.length > 0 ? (
                                                                            <Input
                                                                                type='number'
                                                                                value={Number(
                                                                                    item.area_quantities?.reduce(
                                                                                        (sum, aq) =>
                                                                                            sum + (aq.quantity || 0),
                                                                                        0,
                                                                                    ) || 0,
                                                                                )}
                                                                                readOnly
                                                                                className='w-20 h-8'
                                                                            />
                                                                        ) : (
                                                                            <Input
                                                                                type='number'
                                                                                value={String(
                                                                                    item[
                                                                                        header.key as keyof WholesaleInRecord
                                                                                    ] ?? 0,
                                                                                )}
                                                                                onChange={e =>
                                                                                    handleRecordChange(
                                                                                        key,
                                                                                        header.key as keyof WholesaleInRecord,
                                                                                        e.target.value,
                                                                                    )
                                                                                }
                                                                                className='w-20 h-8'
                                                                                disabled={disabled}
                                                                                ref={
                                                                                    key === '0'
                                                                                        ? firstQuantityRef
                                                                                        : undefined
                                                                                }
                                                                            />
                                                                        )}

                                                                        {Array.isArray(item.area_quantities) &&
                                                                            item.area_quantities.length > 0 && (
                                                                                <Button
                                                                                    onClick={e => {
                                                                                        e.preventDefault();
                                                                                        setOpenQuantity(prev => ({
                                                                                            ...prev,
                                                                                            [key]: !prev[key],
                                                                                        }));
                                                                                    }}
                                                                                    className={
                                                                                        'flex align-top justify-center mt-3'
                                                                                    }>
                                                                                    <ArrowDown className='' />
                                                                                </Button>
                                                                            )}

                                                                        {!disabled && (
                                                                            <Button
                                                                                onClick={e => {
                                                                                    e.preventDefault();

                                                                                    setOpenQuantity(prev => ({
                                                                                        ...prev,
                                                                                        [key]: true,
                                                                                    }));

                                                                                    const updatedRecord = {
                                                                                        ...editableRecords[key],
                                                                                        area_quantities: [
                                                                                            ...(editableRecords[key]
                                                                                                .area_quantities || []),
                                                                                            {
                                                                                                area_id: 0,
                                                                                                area_name: '',
                                                                                                quantity: 0,
                                                                                            },
                                                                                        ],
                                                                                    };

                                                                                    handleRecordChange(
                                                                                        key,
                                                                                        'area_quantities',
                                                                                        updatedRecord.area_quantities,
                                                                                    );
                                                                                }}
                                                                                className={
                                                                                    'flex align-top justify-center mt-3'
                                                                                }>
                                                                                <Plus className='' />
                                                                            </Button>
                                                                        )}
                                                                    </div>

                                                                    {Array.isArray(item.area_quantities) &&
                                                                        item.area_quantities.length > 0 && (
                                                                            <div
                                                                                className={`absolute top-[50px] w-[300px] text-gray-500 bg-white shadow-md z-30 p-2 ${openQuantity[key] ? '' : 'hidden'}`}>
                                                                                {item.area_quantities?.map(
                                                                                    (
                                                                                        aq: AreaQuantity,
                                                                                        index: number,
                                                                                    ) => (
                                                                                        <div
                                                                                            key={index}
                                                                                            className='flex gap-2 items-center'>
                                                                                            {areas &&
                                                                                            areas.length > 0 ? (
                                                                                                <Combo
                                                                                                    items={areas}
                                                                                                    displayValue={
                                                                                                        'name'
                                                                                                    }
                                                                                                    //selected={aq.area_id}
                                                                                                    selected={
                                                                                                        areas.find(
                                                                                                            a =>
                                                                                                                a.id ===
                                                                                                                aq.area_id,
                                                                                                        ) || null
                                                                                                    }
                                                                                                    onChange={value => {
                                                                                                        //console.log(value, aq.area_id);
                                                                                                        const newAreaQuantities =
                                                                                                            [
                                                                                                                ...(item.area_quantities ||
                                                                                                                    []),
                                                                                                            ];
                                                                                                        newAreaQuantities[
                                                                                                            index
                                                                                                        ] = {
                                                                                                            ...newAreaQuantities[
                                                                                                                index
                                                                                                            ],
                                                                                                            area_id:
                                                                                                                value.id,
                                                                                                            area_name:
                                                                                                                value.name,
                                                                                                        };
                                                                                                        handleRecordChange(
                                                                                                            key,
                                                                                                            'area_quantities',
                                                                                                            newAreaQuantities,
                                                                                                        );
                                                                                                    }}
                                                                                                    disabled={disabled}
                                                                                                />
                                                                                            ) : null}
                                                                                            <Input
                                                                                                type='number'
                                                                                                value={String(
                                                                                                    aq.quantity || 0,
                                                                                                )}
                                                                                                disabled={disabled}
                                                                                                onChange={e => {
                                                                                                    const newAreaQuantities =
                                                                                                        [
                                                                                                            ...(item.area_quantities ||
                                                                                                                []),
                                                                                                        ];
                                                                                                    newAreaQuantities[
                                                                                                        index
                                                                                                    ] = {
                                                                                                        ...newAreaQuantities[
                                                                                                            index
                                                                                                        ],
                                                                                                        quantity:
                                                                                                            parseInt(
                                                                                                                e.target
                                                                                                                    .value,
                                                                                                            ) || 0,
                                                                                                    };
                                                                                                    handleRecordChange(
                                                                                                        key,
                                                                                                        'area_quantities',
                                                                                                        newAreaQuantities,
                                                                                                    );
                                                                                                }}
                                                                                                className='w-20'
                                                                                            />

                                                                                            {!disabled && (
                                                                                                <Button
                                                                                                    onClick={() => {
                                                                                                        const newAreaQuantities =
                                                                                                            item.area_quantities?.filter(
                                                                                                                (
                                                                                                                    _,
                                                                                                                    i,
                                                                                                                ) =>
                                                                                                                    i !==
                                                                                                                    index,
                                                                                                            ) || [];

                                                                                                        handleRecordChange(
                                                                                                            key,
                                                                                                            'area_quantities',
                                                                                                            newAreaQuantities,
                                                                                                        );
                                                                                                    }}
                                                                                                    className='text-red-500 hover:text-red-700 mt-[-10px]'>
                                                                                                    <X className='h-4 w-4' />
                                                                                                </Button>
                                                                                            )}
                                                                                        </div>
                                                                                    ),
                                                                                )}
                                                                            </div>
                                                                        )}
                                                                </div>
                                                            );
                                                        }

                                                        if (
                                                            !item.record_id &&
                                                            ['for_sale_on_discogs'].includes(header.key)
                                                        ) {
                                                            return (
                                                                <Input
                                                                    type='checkbox'
                                                                    checked={Boolean(
                                                                        item[header.key as keyof WholesaleInRecord],
                                                                    )}
                                                                    onChange={(
                                                                        e: React.ChangeEvent<HTMLInputElement>,
                                                                    ) =>
                                                                        handleRecordChange(
                                                                            key,
                                                                            header.key as keyof WholesaleInRecord,
                                                                            e.target.checked,
                                                                        )
                                                                    }
                                                                    name={`${header.key}_${key}`}
                                                                    id={`${header.key}_${key}`}
                                                                    className='w-4 h-4 border mx-auto my-2'
                                                                />
                                                            );
                                                        }

                                                        if (
                                                            item.record_id &&
                                                            ['for_sale_on_discogs'].includes(header.key)
                                                        ) {
                                                            const fieldValue =
                                                                item.parent_record?.for_sale_on_discogs ??
                                                                item.for_sale_on_discogs;

                                                            const isTrue = Boolean(fieldValue) && fieldValue !== 0;

                                                            return (
                                                                <div className='flex justify-center'>
                                                                    <div
                                                                        className={`w-3 h-3 rounded-full ${
                                                                            isTrue ? 'bg-green-500' : 'bg-gray-300'
                                                                        }`}
                                                                    />
                                                                </div>
                                                            );
                                                        }

                                                        // Column: Quantity, Discount, VAT (Numeric Inputs for all records)
                                                        if (['discount', 'vat'].includes(header.key)) {
                                                            return (
                                                                <Input
                                                                    type='number'
                                                                    value={String(
                                                                        item[header.key as keyof WholesaleInRecord] ??
                                                                            '',
                                                                    )}
                                                                    onChange={e =>
                                                                        handleRecordChange(
                                                                            key,
                                                                            header.key as keyof WholesaleInRecord,
                                                                            e.target.value,
                                                                        )
                                                                    }
                                                                    className='w-20 h-8'
                                                                    disabled={disabled}
                                                                />
                                                            );
                                                        }

                                                        // Column: Unit Price, Wholesale Price, Retail Price (Currency Inputs for manually added records)
                                                        if (
                                                            header.key === 'unit_price' ||
                                                            (!item.record_id &&
                                                                ['wholesale_price', 'retail_price'].includes(
                                                                    header.key,
                                                                ))
                                                        ) {
                                                            const valueKey = header.key as keyof WholesaleInRecord;
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
                                                                    className='w-32 h-8'
                                                                    disabled={disabled}
                                                                />
                                                            );
                                                        }

                                                        // Column: Text Inputs for manually added records
                                                        if (!item.record_id) {
                                                            let fieldNameForKey = header.key;
                                                            if (header.key === 'artist_name')
                                                                fieldNameForKey = 'artist';
                                                            else if (header.key === 'format.name')
                                                                fieldNameForKey = 'format';
                                                            else if (header.key === 'label.name')
                                                                fieldNameForKey = 'label';

                                                            if (
                                                                [
                                                                    'cat_number',
                                                                    'barcode',
                                                                    'title',
                                                                    'artist',
                                                                    'format',
                                                                    'label',
                                                                    'condition_disk',
                                                                    'condition_cover',
                                                                    'release_id',
                                                                    'discogs_id',
                                                                ].includes(fieldNameForKey)
                                                            ) {
                                                                return (
                                                                    <Input
                                                                        type='text'
                                                                        value={String(
                                                                            item[
                                                                                fieldNameForKey as keyof WholesaleInRecord
                                                                            ] ?? '',
                                                                        )}
                                                                        onChange={(
                                                                            e: React.ChangeEvent<HTMLInputElement>,
                                                                        ) =>
                                                                            handleRecordChange(
                                                                                key,
                                                                                fieldNameForKey as keyof WholesaleInRecord,
                                                                                e.target.value,
                                                                            )
                                                                        }
                                                                        name={`${header.key}_${key}`}
                                                                        id={`${header.key}_${key}`}
                                                                        className='w-32 h-8'
                                                                    />
                                                                );
                                                            }
                                                        }

                                                        // Column: Wholesale Price, Retail Price (Display Only for imported records)
                                                        if (
                                                            item.record_id &&
                                                            ['wholesale_price', 'retail_price'].includes(header.key)
                                                        ) {
                                                            const priceValue =
                                                                item[header.key as keyof WholesaleInRecord];
                                                            const priceNum =
                                                                typeof priceValue === 'string'
                                                                    ? parseFloat(priceValue.replace(',', '.'))
                                                                    : typeof priceValue === 'number'
                                                                      ? priceValue
                                                                      : 0;
                                                            return new Intl.NumberFormat('it-IT', {
                                                                style: 'currency',
                                                                currency: 'EUR',
                                                            }).format(isNaN(priceNum) ? 0 : priceNum);
                                                        }

                                                        // Column: Display Values for imported records
                                                        let displayValue: string | number | null | undefined = '';
                                                        if (header.key === 'artist_name') {
                                                            displayValue =
                                                                item.parent_record?.artist?.name ?? item.artist ?? '';
                                                        } else if (header.key === 'format.name') {
                                                            displayValue =
                                                                item.parent_record?.format?.name ?? item.format ?? '';
                                                        } else if (header.key === 'label.name') {
                                                            displayValue =
                                                                item.parent_record?.label?.name ?? item.label ?? '';
                                                        } else if (header.key === 'condition_disk') {
                                                            // Map disk_status enum from parent_record to display value
                                                            displayValue =
                                                                item.condition_disk ??
                                                                item.parent_record?.disk_status_name ??
                                                                '';
                                                        } else if (header.key === 'condition_cover') {
                                                            // Map cover_status enum from parent_record to display value
                                                            displayValue =
                                                                item.condition_cover ??
                                                                item.parent_record?.cover_status_name ??
                                                                '';
                                                        } else if (header.key.includes('.') && item.parent_record) {
                                                            const [parent, child] = header.key.split('.');
                                                            const parentObj =
                                                                item.parent_record[parent as keyof Record];
                                                            if (
                                                                typeof parentObj === 'object' &&
                                                                parentObj !== null &&
                                                                child in parentObj
                                                            ) {
                                                                displayValue =
                                                                    parentObj[child as keyof typeof parentObj];
                                                            }
                                                        } else {
                                                            // Get value from item first, then fallback to parent_record
                                                            const itemValue =
                                                                item[header.key as keyof WholesaleInRecord];
                                                            if (itemValue !== undefined && itemValue !== null) {
                                                                // Only assign primitive values to displayValue
                                                                if (
                                                                    typeof itemValue === 'string' ||
                                                                    typeof itemValue === 'number'
                                                                ) {
                                                                    displayValue = itemValue;
                                                                }
                                                            } else if (item.parent_record) {
                                                                // Only access primitive fields from parent_record
                                                                const parentValue =
                                                                    item.parent_record[header.key as keyof Record];
                                                                if (
                                                                    typeof parentValue === 'string' ||
                                                                    typeof parentValue === 'number'
                                                                ) {
                                                                    displayValue = parentValue;
                                                                }
                                                            }
                                                        }
                                                        return String(displayValue ?? '');
                                                    })()
                                                ) : !disabled ? (
                                                    <div className='flex gap-2 justify-center'>
                                                        <PrimaryButton
                                                            type={'button'}
                                                            onClick={() => handleRemoveFromMainTable(key)}
                                                            className='w-8 h-8 !p-0 flex items-center justify-center bg-red-600 hover:bg-red-700'>
                                                            <Trash className='h-4 w-4' />
                                                        </PrimaryButton>
                                                    </div>
                                                ) : (
                                                    // Empty cell when disabled
                                                    <div></div>
                                                )}
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
