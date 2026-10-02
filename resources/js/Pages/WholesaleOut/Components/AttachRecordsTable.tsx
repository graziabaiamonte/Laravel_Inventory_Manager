import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Card } from '@/Components/ui/card';
import React, { useState, useEffect, useRef } from 'react';
import type { TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { Plus, FileUp, Trash, X, FileSpreadsheet, Pencil } from 'lucide-react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import RecordSearchAndPreview from '@/Components/records/RecordSearchAndPreview';
import NotFoundRecordsTable from './NotFoundRecordsTable';
import { WholesaleOutRecord, Record, AreaQuantity, Area, WholesaleOutLabelDiscount, PageProps } from '@/types';
import Input from '@/Components/atomica/Forms/Input';
import CurrencyInput from '@/Components/atomica/Forms/CurrencyInput';
import InputError from '@/Components/InputError';
import Combo from '@/Components/atomica/Utils/Combo';
import { usePage } from '@inertiajs/react';

interface NotFoundRecord {
    row_number: number;
    cat_number: string;
    barcode: string;
    artist: string;
    title: string;
    format: string;
    label: string;
    quantity: number;
}

interface SearchTerms {
    barcode: string;
    catNumber: string;
    artist: string;
    title: string;
}

interface AttachRecordsTableProps {
    dataSource?: WholesaleOutRecord[];
    description?: string;
    onRecordsUpdate: (records: WholesaleOutRecord[]) => void;
    onFileSelect?: (file: File) => void;
    onBulkDiscountUpdate?: (labelDiscounts: WholesaleOutLabelDiscount[]) => void;
    errors?: { [key: string]: string | undefined };
    isEditing?: boolean;
    importWarnings?: string[];
    areas?: Area[];
    existingLabelDiscounts?: WholesaleOutLabelDiscount[];
    isEditingDisabled?: boolean; // True when WholesaleOut status is active (1) and records should be read-only
    defaultAreaId?: number; // Default area ID for initial selection
    initialSearchTerms?: Partial<SearchTerms>; // For pre-populating search from manual searches (parent)
    onClearSearchTerms?: () => void; // Callback to clear search terms from parent
    notFoundRecords?: NotFoundRecord[]; // Not-found records from import
    hideFileUpload?: boolean; // Hide file upload button for active WholesaleOuts
    types_status?: Array<any>;
}

export default function AttachRecordsTable(props: AttachRecordsTableProps) {
    const {
        description,
        onRecordsUpdate,
        onFileSelect,
        onBulkDiscountUpdate,
        dataSource,
        errors,
        isEditing,
        importWarnings,
        areas,
        existingLabelDiscounts,
        isEditingDisabled,
        defaultAreaId,
        initialSearchTerms,
        onClearSearchTerms,
        notFoundRecords,
        hideFileUpload,
        types_status,
    } = props;

    const { auth } = usePage<PageProps>().props;

    // Check if user has permission to edit quantities using auth.permissions from Inertia
    // Only apply this restriction when editing existing records (isEditing=true)
    // For create operations, there are no existing values to protect, so everyone can set quantities
    const canEditQuantities =
        !isEditing || auth.permissions.includes('all') || auth.permissions.includes('edit_wholesaleout_quantities');

    // Create dynamic table headers based on multiple areas setting
    const getTableHeaders = (): Array<TableHeaderType> => {
        const baseHeaders = [
            { label: '', key: '__edit' },
            { label: 'Cat. #', key: 'cat_number' },
            { label: 'Barcode', key: 'barcode' },
            {
                label: 'Artista',
                key: 'artist_name',
                value: (item: object) => {
                    const record = item as WholesaleOutRecord;
                    return record.parent_record?.artist?.name ?? record.artist_name ?? '';
                },
            },
            { label: 'Titolo', key: 'title' },
            {
                label: 'Fmt',
                key: 'format_name',
                value: (item: object) => {
                    const record = item as WholesaleOutRecord;
                    return record.parent_record?.format?.name ?? record.format_name ?? '';
                },
            },
            {
                label: 'Etichetta',
                key: 'label_name',
                value: (item: object) => {
                    const record = item as WholesaleOutRecord;
                    return record.parent_record?.label?.name ?? record.label_name ?? '';
                },
            },
        ];

        // Add area column (single-area mode)
        baseHeaders.push({ label: 'Area', key: 'area_id' });

        // Add remaining headers
        baseHeaders.push(
            { label: 'Quantità', key: 'quantity' },
            {
                label: 'Disponibile',
                key: 'available_quantity',
                value: (item: object) => {
                    const record = item as WholesaleOutRecord;
                    return record.available_quantity?.toString() ?? '0';
                },
            },
            {
                label: 'Stock Magazzino',
                key: 'warehouse_stock_quantity',
                value: (item: object) => {
                    const record = item as WholesaleOutRecord;
                    return record.warehouse_stock_quantity?.toString() ?? '0';
                },
            },
            {
                label: 'Spedito',
                key: 'shipped_quantity',
                value: (item: object) => {
                    const record = item as WholesaleOutRecord;
                    return record.shipped_quantity?.toString() ?? '0';
                },
            },
            { label: 'Prezzo ingrosso', key: 'unit_price' },
            { label: 'Sconto %', key: 'discount' },
            { label: 'Totale', key: 'total_price' },
            { label: 'IVA', key: 'vat' },
            { label: '', key: '' }, // For actions column
        );

        return baseHeaders;
    };
    const [showPreview, setShowPreview] = useState(false);
    const [editableRecords, setEditableRecords] = useState<{ [key: string]: WholesaleOutRecord }>({});
    // Focus the newly added (first) row's quantity input after each add
    const firstQuantityRef = useRef<{ focus: () => void } | null>(null);
    const [focusTick, setFocusTick] = useState(0);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [showErrorStyle, setShowErrorStyle] = useState(false);

    useEffect(() => {
        if (focusTick > 0) {
            firstQuantityRef.current?.focus();
        }
    }, [focusTick]);

    // State for current not-found record quantity (will be cleared after use)
    const [currentNotFoundQuantity, setCurrentNotFoundQuantity] = useState<number | undefined>(undefined);

    // Internal state for search terms (separate from parent's initialSearchTerms)
    const [internalSearchTerms, setInternalSearchTerms] = useState<Partial<SearchTerms>>({});

    // State for bulk discount by label
    const [showBulkDiscount, setShowBulkDiscount] = useState(false);
    const [bulkDiscounts, setBulkDiscounts] = useState<{ [labelName: string]: number }>({});

    // Extract unique labels from current records
    const getUniqueLabels = () => {
        const labels = new Set<string>();
        Object.values(editableRecords).forEach(record => {
            const labelName = record.parent_record?.label?.name ?? record.label_name;
            if (labelName) {
                labels.add(labelName);
            }
        });
        return Array.from(labels).sort();
    };

    // Apply bulk discount to all records with the same label
    const applyBulkDiscountByLabel = (labelName: string, discount: number) => {
        const updatedRecords = { ...editableRecords };

        Object.entries(updatedRecords).forEach(([key, record]) => {
            const recordLabelName = record.parent_record?.label?.name ?? record.label_name;
            if (recordLabelName === labelName) {
                const updatedRecord = { ...record, discount };

                // Recalculate total price
                const qVal = updatedRecord.quantity;
                const upVal = updatedRecord.unit_price;
                const numUnitPrice = typeof upVal === 'string' ? parseFloat(upVal.replace(/,/g, '')) : Number(upVal);

                updatedRecord.total_price = Number(
                    (qVal * (isNaN(numUnitPrice) ? 0 : numUnitPrice) * (1 - discount / 100)).toFixed(2),
                );

                updatedRecords[key] = updatedRecord;
            }
        });

        setEditableRecords(updatedRecords);
        onRecordsUpdate?.(Object.values(updatedRecords));
    };

    // Handle bulk discount input change
    const handleBulkDiscountChange = (labelName: string, value: string) => {
        const discount = parseInt(value, 10);
        const validDiscount = isNaN(discount) ? 0 : Math.max(0, Math.min(100, discount));

        setBulkDiscounts(prev => ({
            ...prev,
            [labelName]: validDiscount,
        }));

        applyBulkDiscountByLabel(labelName, validDiscount);

        // If onBulkDiscountUpdate callback is provided, collect all label discounts and send to parent
        if (onBulkDiscountUpdate) {
            const allLabelDiscounts: WholesaleOutLabelDiscount[] = [];

            // Get all unique labels and their discounts
            const uniqueLabels = getUniqueLabels();
            uniqueLabels.forEach(label => {
                // Find a record with this label to get its label_id
                const recordWithLabel = Object.values(editableRecords).find(
                    record => (record.parent_record?.label?.name ?? record.label_name) === label,
                );

                if (recordWithLabel && recordWithLabel.parent_record?.label?.id) {
                    const labelDiscount = label === labelName ? validDiscount : bulkDiscounts[label] || 0;
                    if (labelDiscount > 0) {
                        allLabelDiscounts.push({
                            label_id: recordWithLabel.parent_record.label.id,
                            label_name: label,
                            discount: labelDiscount,
                        });
                    }
                }
            });

            onBulkDiscountUpdate(allLabelDiscounts);
        }
    };

    // Handle delete bulk discount
    const handleDeleteBulkDiscount = (labelName: string) => {
        // Remove the discount from state
        const updatedBulkDiscounts = { ...bulkDiscounts };
        delete updatedBulkDiscounts[labelName];
        setBulkDiscounts(updatedBulkDiscounts);

        // Reset discount to 0 for all records with this label
        applyBulkDiscountByLabel(labelName, 0);

        // Update parent component with remaining discounts
        if (onBulkDiscountUpdate) {
            const allLabelDiscounts: WholesaleOutLabelDiscount[] = [];

            // Get all unique labels and their discounts (excluding the deleted one)
            const uniqueLabels = getUniqueLabels();
            uniqueLabels.forEach(label => {
                if (label !== labelName) {
                    // Find a record with this label to get its label_id
                    const recordWithLabel = Object.values(editableRecords).find(
                        record => (record.parent_record?.label?.name ?? record.label_name) === label,
                    );

                    if (recordWithLabel && recordWithLabel.parent_record?.label?.id) {
                        const labelDiscount = updatedBulkDiscounts[label] || 0; // Use updated state
                        if (labelDiscount > 0) {
                            allLabelDiscounts.push({
                                label_id: recordWithLabel.parent_record.label.id,
                                label_name: label,
                                discount: labelDiscount,
                            });
                        }
                    }
                }
            });

            onBulkDiscountUpdate(allLabelDiscounts);
        }
    };

    // File upload handler
    const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setSelectedFile(file);
            setEditableRecords({});
            onFileSelect?.(file);
        }
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    useEffect(() => {
        if (dataSource?.length) {
            const records = dataSource.reduce(
                (acc, record, index) => {
                    // Explicitly preserve all fields including area_quantities
                    const modifiableRecord: WholesaleOutRecord = {
                        ...record,
                        // Explicitly ensure area_quantities is preserved
                        area_quantities: record.area_quantities || [],
                    };
                    acc[index.toString()] = modifiableRecord;
                    return acc;
                },
                {} as { [key: string]: WholesaleOutRecord },
            );
            setEditableRecords(records);

            // Clear file state when records are imported successfully
            setSelectedFile(null);
            // Clear the file input as well
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        } else {
            setEditableRecords({});
        }
    }, [dataSource]);

    useEffect(() => {
        if (errors?.file) {
            setSelectedFile(null);
            setShowErrorStyle(true);
            if (fileInputRef.current) fileInputRef.current.value = '';
            const timer = setTimeout(() => setShowErrorStyle(false), 2000);
            return () => clearTimeout(timer);
        }
    }, [errors]);

    // Initialize bulk discounts from existing label discounts
    useEffect(() => {
        if (existingLabelDiscounts && existingLabelDiscounts.length > 0) {
            const initialBulkDiscounts: { [labelName: string]: number } = {};
            existingLabelDiscounts.forEach(discount => {
                initialBulkDiscounts[discount.label_name] = discount.discount;
            });
            setBulkDiscounts(initialBulkDiscounts);
        }
    }, [existingLabelDiscounts]);

    // Automatically open preview when search terms are provided from not-found records
    useEffect(() => {
        if (initialSearchTerms && Object.keys(initialSearchTerms).length > 0) {
            setShowPreview(true);
        }
    }, [initialSearchTerms]);

    // Main table record change handler
    const handleRecordChange = (
        key: string,
        field: keyof WholesaleOutRecord,
        value: string | number | AreaQuantity[],
    ) => {
        const record = editableRecords[key];
        if (!record) return;

        let processedValue: string | number | AreaQuantity[];

        if (Array.isArray(value) && field === 'area_quantities') {
            processedValue = value;
        } else if (typeof value === 'string' || typeof value === 'number') {
            const stringValue = String(value);

            if (field === 'quantity' || field === 'discount') {
                const parsed = parseInt(stringValue, 10);
                processedValue = isNaN(parsed) ? 0 : parsed;
            } else if (field === 'unit_price' || field === 'total_price') {
                // Keep as string to preserve decimal format (e.g., "140.00")
                // parseFloat() would strip decimals: parseFloat("140.00") = 140
                // Backend receives integer 140, which Money interprets as 140 cents = $1.40
                // With string "140.00", backend casts to float 140.0 = $140.00
                processedValue = stringValue;
            } else if (field === 'vat') {
                processedValue = stringValue;
            } else {
                processedValue = stringValue;
            }
        } else {
            return; // Invalid value type
        }

        const updatedRecord: WholesaleOutRecord = {
            ...record,
            [field]: processedValue,
        };

        // Handle area_quantities updates based on field changes
        if (field !== 'area_quantities') {
            // If quantity changed, update area_quantities to reflect the new quantity
            if (field === 'quantity' && record.area_quantities && record.area_quantities.length > 0) {
                updatedRecord.area_quantities = record.area_quantities.map(area => ({
                    ...area,
                    quantity: Number(processedValue),
                }));
            } else {
                // Preserve existing area_quantities for other field changes
                updatedRecord.area_quantities = record.area_quantities || [];
            }
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

            updatedRecord.total_price = Number(
                (qVal * (isNaN(numUnitPrice) ? 0 : numUnitPrice) * (1 - dVal / 100)).toFixed(2),
            );
        }

        const newEditableRecords = { ...editableRecords, [key]: updatedRecord };
        setEditableRecords(newEditableRecords);

        const allRecords = Object.values(newEditableRecords);
        onRecordsUpdate?.(allRecords);
    };

    const handleRemoveFromMainTable = (key: string) => {
        const newEditableRecords = { ...editableRecords };
        delete newEditableRecords[key];
        setEditableRecords(newEditableRecords);
        onRecordsUpdate?.(Object.values(newEditableRecords));
    };

    // Callback for record selection from shared component
    const handleRecordSelect = (record: Record, selectedQuantity: number = 1) => {
        if (!onRecordsUpdate) return;

        // Use current not-found quantity if available, otherwise use the selected quantity
        const quantity = currentNotFoundQuantity !== undefined ? currentNotFoundQuantity : selectedQuantity;

        // Clear the not-found quantity after using it (so next manual search is clean)
        if (currentNotFoundQuantity !== undefined) {
            setCurrentNotFoundQuantity(undefined);
        }

        const unitPrice = record.wholesale_price || 0; // Default unit price is the record's wholesale price
        const discount = 0;
        const total_price = Number((quantity * unitPrice * (1 - discount / 100)).toFixed(2));

        // Determine stock_id. If record.stock is an array, pick the first available.
        const stockItem = record.stock?.find(s => s.quantity > 0) ?? record.stock?.[0];
        const stock_id = stockItem?.id ?? 0; // Default to 0 if no suitable stock item is found

        const outRecord: WholesaleOutRecord = {
            // Don't set id for new records - let the backend assign it
            record_id: record.id, // This is the ID of the base Record
            stock_id: stock_id, // The ID of the stock item being sold out
            quantity,
            unit_price: unitPrice,
            discount,
            total_price,
            vat: 22, // Default VAT, consider making this configurable
            parent_record: record, // Keep the full Record object for UI display needs
            area_quantities: [], // Initialize empty area_quantities for manually added records
            available_quantity: record.total_stocks || 0, // Add available quantity from total stocks
            warehouse_stock_quantity: record.total_warehouse_stocks || 0, // Add warehouse stock quantity

            // Fields for display fallback or if backend expects them flat.
            cat_number: record.cat_number,
            barcode: record.barcode,
            artist_name: record.artist?.name, // Fallback if parent_record.artist.name is not used in display
            title: record.title,
            format_name: record.format?.name, // Fallback
            label_name: record.label?.name, // Fallback
        };

        const currentRecords = Object.values(editableRecords);
        // Prepend the new record so the most recently added appears as the first row
        const newRecordsArray = [outRecord, ...currentRecords];
        const newEditableRecords = newRecordsArray.reduce(
            (acc, rec, idx) => {
                acc[idx.toString()] = rec;
                return acc;
            },
            {} as { [key: string]: WholesaleOutRecord },
        );
        setEditableRecords(newEditableRecords);
        setFocusTick(t => t + 1);
        onRecordsUpdate(newRecordsArray);
    };

    // Internal callback for not-found record searches - directly triggers search with quantity
    const handleNotFoundRecordSearch = (searchTerms: Partial<SearchTerms>, quantity: number) => {
        // Store the quantity for this specific not-found record search
        setCurrentNotFoundQuantity(quantity);

        // Store the search terms internally
        setInternalSearchTerms(searchTerms);

        // Open the preview - the search terms will be passed to RecordSearchAndPreview
        setShowPreview(true);
    };

    // Callback for record removal from shared component
    // Nothing to do on removal, the preview handles its own state
    const handleRecordRemove = () => {};

    return (
        <div className='space-y-4'>
            {/* Display not-found records from import with search functionality */}
            {notFoundRecords && notFoundRecords.length > 0 && (
                <NotFoundRecordsTable notFoundRecords={notFoundRecords} onSearchRecord={handleNotFoundRecordSearch} />
            )}

            {/* Actions Bar */}
            <div className='flex items-center justify-between'>
                <p className='text-sm font-medium text-gray-900'>{description}</p>
                <div className='flex gap-2'>
                    {/* Control file upload and add record buttons - hidden when editing is disabled */}
                    {!isEditingDisabled && (
                        <div className='flex gap-2'>
                            {/* Template download button - shown before file upload */}
                            {(!Object.keys(editableRecords).length || errors?.file || isEditing) && (
                                <SecondaryButton
                                    type='button'
                                    onClick={() => window.open(route('wholesale-out.download-template'), '_blank')}
                                    title='Scarica Template'
                                    className='flex items-center gap-2'>
                                    <FileSpreadsheet className='h-5 w-5' />
                                    Template
                                </SecondaryButton>
                            )}

                            {/* Bulk Discount Toggle Button - moved to left of upload button */}
                            {Object.keys(editableRecords).length > 0 && (
                                <SecondaryButton
                                    type='button'
                                    onClick={() => setShowBulkDiscount(!showBulkDiscount)}
                                    title={showBulkDiscount ? 'Nascondi Sconti Bulk' : 'Sconti Bulk'}>
                                    Sconti Bulk
                                </SecondaryButton>
                            )}

                            {/* Show file upload button - allow in both create and edit modes */}
                            {!hideFileUpload && (!Object.keys(editableRecords).length || errors?.file || isEditing) ? (
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

                            <PrimaryButton
                                type='button'
                                onClick={() => {
                                    // If opening manually (not from initialSearchTerms), clear search terms
                                    if (!showPreview && onClearSearchTerms) {
                                        onClearSearchTerms();
                                    }
                                    setShowPreview(!showPreview);
                                }}
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
                    showWarehouseStock={true}
                    onRecordSelect={handleRecordSelect}
                    onRecordRemove={handleRecordRemove}
                    initialSearchTerms={
                        Object.keys(internalSearchTerms).length > 0 ? internalSearchTerms : initialSearchTerms
                    }
                    types_status={types_status}
                />
            )}
            {/* Bulk Discount Section */}
            {!isEditingDisabled && showBulkDiscount && Object.keys(editableRecords).length > 0 && (
                <Card>
                    <div className='p-4'>
                        <div className='mb-4'>
                            <h3 className='text-lg font-semibold'>Sconto Bulk per Etichetta</h3>
                        </div>
                        <div className='grid gap-3'>
                            {getUniqueLabels().map(labelName => (
                                <div key={labelName} className='flex items-center gap-4'>
                                    <div className='w-1/2'>
                                        <span className='text-sm font-medium text-gray-900'>{labelName}</span>
                                    </div>
                                    <div className='w-1/4'>
                                        <Input
                                            type='number'
                                            placeholder='Sconto %'
                                            value={bulkDiscounts[labelName] || ''}
                                            onChange={e => handleBulkDiscountChange(labelName, e.target.value)}
                                            min={0}
                                            max={100}
                                            className='text-center'
                                        />
                                    </div>
                                    <div className='w-1/6 text-sm text-gray-500'>
                                        {
                                            Object.values(editableRecords).filter(
                                                record =>
                                                    (record.parent_record?.label?.name ?? record.label_name) ===
                                                    labelName,
                                            ).length
                                        }{' '}
                                        record(s)
                                    </div>
                                    <div className='w-1/12 flex justify-center'>
                                        <PrimaryButton
                                            type='button'
                                            onClick={() => handleDeleteBulkDiscount(labelName)}
                                            className='w-8 h-8 !p-0 flex items-center justify-center bg-red-600 hover:bg-red-700'
                                            title='Elimina sconto bulk'>
                                            <Trash className='h-4 w-4' />
                                        </PrimaryButton>
                                    </div>
                                </div>
                            ))}
                            {getUniqueLabels().length === 0 && (
                                <p className='text-gray-500 text-sm'>
                                    Nessuna etichetta disponibile per lo sconto bulk.
                                </p>
                            )}
                        </div>
                    </div>
                </Card>
            )}
            {/* Main Table */}
            <Card className={showPreview ? 'rounded-t-none' : ''}>
                <Table>
                    <TableHeader>
                        <TableRow>
                            {getTableHeaders().map((header, idx) => (
                                <TableHead key={idx}>{header.label}</TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {Object.entries(editableRecords).map(([key, item]) => (
                            <TableRow key={key}>
                                {getTableHeaders().map((header, idx) => (
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
                                                const currentItem = item as WholesaleOutRecord;
                                                // Prefer header.value for complex rendering
                                                if (header.value && typeof header.value === 'function') {
                                                    return header.value(currentItem);
                                                }

                                                // Handle specific keys for inputs or special formatting
                                                if (header.key === 'total_price') {
                                                    let totalPriceNum: number;
                                                    if (typeof currentItem.total_price === 'string') {
                                                        totalPriceNum = parseFloat(
                                                            String(currentItem.total_price).replace(',', '.'),
                                                        );
                                                    } else if (typeof currentItem.total_price === 'number') {
                                                        totalPriceNum = currentItem.total_price;
                                                    } else {
                                                        totalPriceNum = 0;
                                                    }
                                                    // return (isNaN(totalPriceNum) ? 0 : totalPriceNum).toFixed(2);
                                                    const formattedPrice = new Intl.NumberFormat('it-IT', {
                                                        style: 'currency',
                                                        currency: 'EUR',
                                                    }).format(isNaN(totalPriceNum) ? 0 : totalPriceNum);
                                                    return formattedPrice;
                                                }
                                                // Column: Area selection (single-area mode)
                                                if (header.key === 'area_id') {
                                                    // Get the current area_id from area_quantities or fallback
                                                    // Handle both imported (not yet persisted) and persisted data
                                                    const currentAreaId =
                                                        Array.isArray(item.area_quantities) &&
                                                        item.area_quantities.length > 0
                                                            ? item.area_quantities[0]?.area_id
                                                            : null;

                                                    let selectedArea = areas?.find(area => area.id === currentAreaId);

                                                    if (!selectedArea && defaultAreaId) {
                                                        selectedArea = areas?.find(area => area.id === defaultAreaId);
                                                    }

                                                    const stockRecords = item.parent_record?.stock || item?.stock || [];

                                                    // Create areas with stock quantities
                                                    const areasWithQuantities =
                                                        areas?.map(area => {
                                                            // Find the stock quantity for this area from the parent record
                                                            const stockQuantity =
                                                                stockRecords.find(stock => stock.area_id === area.id)
                                                                    ?.quantity || 0;

                                                            return {
                                                                ...area,
                                                                displayName: `(${stockQuantity}) ${area.name}`,
                                                            };
                                                        }) || [];

                                                    return (
                                                        <div className={'w-48'}>
                                                            <Combo
                                                                items={areasWithQuantities || []}
                                                                displayValue={'displayName'}
                                                                //selected={selectedArea || null}
                                                                selected={
                                                                    areasWithQuantities.find(
                                                                        area => area.id === selectedArea?.id,
                                                                    ) || null
                                                                }
                                                                disabled={isEditingDisabled}
                                                                onChange={selectedArea => {
                                                                    if (isEditingDisabled) return;

                                                                    // Update the record with the selected area
                                                                    const updatedRecord = {
                                                                        ...editableRecords[key],
                                                                        area_quantities: selectedArea
                                                                            ? [
                                                                                  {
                                                                                      area_id: selectedArea.id,
                                                                                      area_name: selectedArea.name,
                                                                                      quantity: Number(
                                                                                          editableRecords[key]
                                                                                              .quantity || 0,
                                                                                      ),
                                                                                  },
                                                                              ]
                                                                            : [],
                                                                    };

                                                                    handleRecordChange(
                                                                        key,
                                                                        'area_quantities',
                                                                        updatedRecord.area_quantities,
                                                                    );
                                                                }}
                                                            />
                                                        </div>
                                                    );
                                                }
                                                // Column: Quantity (single-area mode)
                                                if (header.key === 'quantity') {
                                                    const currentQuantity = Number(item.quantity || 0);

                                                    const availableQuantity = Number(item.available_quantity || 0);

                                                    // Resolve the currently selected area the same way the Area column does,
                                                    // so the highlight follows the area selected on the row
                                                    const selectedAreaId =
                                                        (Array.isArray(item.area_quantities) &&
                                                        item.area_quantities.length > 0
                                                            ? item.area_quantities[0]?.area_id
                                                            : null) ??
                                                        defaultAreaId ??
                                                        null;

                                                    const stockRecords = item.parent_record?.stock || item?.stock || [];

                                                    // Stock held by the selected area only
                                                    const selectedAreaStock = selectedAreaId
                                                        ? stockRecords
                                                              .filter(stock => stock.area_id === selectedAreaId)
                                                              .reduce(
                                                                  (sum, stock) => sum + Number(stock.quantity || 0),
                                                                  0,
                                                              )
                                                        : 0;

                                                    // Determine highlight class based on quantity comparison
                                                    const getQuantityHighlight = () => {
                                                        if (availableQuantity === 0) {
                                                            return 'bg-yellow-100 border-yellow-600'; // No stock available
                                                        } else if (currentQuantity > availableQuantity) {
                                                            return 'bg-red-100 border-red-600'; // Overselling
                                                        } else if (currentQuantity > 0) {
                                                            return currentQuantity <= selectedAreaStock
                                                                ? 'bg-green-100 border-green-600' // Enough stock in the selected area
                                                                : 'bg-orange-200 border-orange-600'; // Not enough here, but covered by other areas
                                                        }
                                                        return ''; // Default state (quantity is 0 and available > 0)
                                                    };

                                                    return (
                                                        <div className='relative flex flex-col gap-2'>
                                                            <div className='flex gap-2'>
                                                                <Input
                                                                    type='number'
                                                                    value={String(currentQuantity)}
                                                                    min={0}
                                                                    disabled={isEditingDisabled || !canEditQuantities}
                                                                    onChange={e =>
                                                                        handleRecordChange(
                                                                            key,
                                                                            header.key as keyof WholesaleOutRecord,
                                                                            e.target.value,
                                                                        )
                                                                    }
                                                                    className={`w-20 h-8 ${getQuantityHighlight()}`}
                                                                    ref={key === '0' ? firstQuantityRef : undefined}
                                                                />
                                                            </div>
                                                        </div>
                                                    );
                                                }

                                                // Column: Discount and VAT (Numeric Inputs)
                                                if (['discount', 'vat'].includes(header.key)) {
                                                    return (
                                                        <Input
                                                            type='number'
                                                            value={String(
                                                                currentItem[header.key as keyof WholesaleOutRecord] ??
                                                                    '',
                                                            )}
                                                            disabled={isEditingDisabled}
                                                            onChange={e =>
                                                                handleRecordChange(
                                                                    key,
                                                                    header.key as keyof WholesaleOutRecord,
                                                                    e.target.value,
                                                                )
                                                            }
                                                            className='w-20 h-8'
                                                        />
                                                    );
                                                }
                                                if (header.key === 'unit_price') {
                                                    const valueKey = header.key as keyof WholesaleOutRecord;
                                                    const currentValue = currentItem[valueKey];
                                                    const valueForInput =
                                                        currentValue !== null && currentValue !== undefined
                                                            ? String(currentValue)
                                                            : '0';

                                                    return (
                                                        <CurrencyInput
                                                            value={valueForInput}
                                                            disabled={isEditingDisabled}
                                                            onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                                                                handleRecordChange(key, valueKey, e.target.value)
                                                            }
                                                            name={`${header.key}_${key}`}
                                                            id={`${header.key}_${key}`}
                                                        />
                                                    );
                                                }
                                                // Default rendering for other keys: direct property access
                                                // This will render properties like cat_number, barcode, title directly
                                                // if they are not handled by a value function or specific input type.
                                                return String(
                                                    currentItem[header.key as keyof WholesaleOutRecord] ?? '',
                                                );
                                            })()
                                        ) : (
                                            <div className='flex gap-2 justify-center'>
                                                {!isEditingDisabled && (
                                                    <PrimaryButton
                                                        type={'button'}
                                                        onClick={() => handleRemoveFromMainTable(key)}
                                                        className='w-8 h-8 !p-0 flex items-center justify-center bg-red-600 hover:bg-red-700'>
                                                        <Trash className='h-4 w-4' />
                                                    </PrimaryButton>
                                                )}
                                            </div>
                                        )}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>
            {errors && errors.records && <InputError message={errors.records} className='mt-2' />}
            {importWarnings && importWarnings.length > 0 && (
                <div className='my-2 p-3 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700'>
                    <p className='font-bold'>Avvisi Importazione:</p>
                    <ul className='list-disc ml-5'>
                        {importWarnings.map((warn, idx) => (
                            <li key={idx}>{warn}</li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
