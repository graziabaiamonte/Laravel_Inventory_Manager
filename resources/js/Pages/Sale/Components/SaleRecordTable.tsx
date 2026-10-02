import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Card } from '@/Components/ui/card';
import React, { useState, useEffect, useRef } from 'react';
import type { TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { Plus, Trash, X } from 'lucide-react';
import PrimaryButton from '@/Components/PrimaryButton';
import RecordSearchAndPreview from '@/Components/records/RecordSearchAndPreview';
import { Record, SaleRecord } from '@/types';
import Input from '@/Components/atomica/Forms/Input';
import Combo from '@/Components/atomica/Utils/Combo';
import CurrencyInput from '@/Components/atomica/Forms/CurrencyInput';
import { cleanBarcode } from '@/lib/utils';

const tableHeaders: Array<TableHeaderType> = [
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
        key: 'artist_name',
    },
    {
        label: 'Titolo',
        key: 'title',
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
        label: 'Quantità',
        key: 'quantity',
    },
    {
        label: 'Posizione',
        key: 'position',
    },
    {
        label: 'Sconto %',
        key: 'discount',
    },

    {
        label: 'Prezzo dettaglio',
        key: 'price',
    },
    {
        label: 'Totale',
        key: 'total_price',
    },
    {
        label: 'iva',
        key: 'vat',
    },
    {
        label: '',
        key: '', // Empty key for action column
    },
];

interface AttachRecordsTableProps {
    sale_records?: SaleRecord[];
    description?: string;
    onRecordsUpdate?: (records: SaleRecord[]) => void;
    errors?: { [key: string]: string | undefined };
    isEditing?: boolean;
    selectedLocationId?: number;
    types_status?: Array<any>;
}

export default function SaleRecordTable({
    sale_records,
    description,
    onRecordsUpdate,
    errors,
    isEditing,
    selectedLocationId,
    types_status,
}: AttachRecordsTableProps) {
    // Add debug log here to see all importedRecords

    const [showPreview, setShowPreview] = useState(true);
    const [editableRecords, setEditableRecords] = useState<{ [key: string]: SaleRecord }>({});
    // Focus the newly added (first) row's quantity input after each add
    const firstQuantityRef = useRef<{ focus: () => void } | null>(null);
    const [focusTick, setFocusTick] = useState(0);

    useEffect(() => {
        if (focusTick > 0) {
            firstQuantityRef.current?.focus();
        }
    }, [focusTick]);

    //console.log(sale_records);

    // Add useEffect to initialize editableRecords when importedRecords are present
    useEffect(() => {
        if (sale_records?.length) {
            // First, update editable records state
            const records = sale_records.reduce(
                (acc, record, index) => {
                    // Spread the record to make it modifiable if necessary,
                    // though we are not directly modifying it here before assignment anymore.
                    const modifiableRecord = { ...record };

                    // The type WholesaleInRecord should now correctly handle null values for optional prices.

                    acc[index.toString()] = modifiableRecord as SaleRecord;
                    return acc;
                },
                {} as { [key: string]: SaleRecord },
            );
            setEditableRecords(records);
        } else {
            // Clear editableRecords if importedRecords becomes empty/undefined
            setEditableRecords({});
        }
    }, [sale_records]); // Keep onRecordsUpdate out of dependencies

    // Callback for record selection from shared component
    const handleRecordSelect = (record: Record, selectedQuantity = 1) => {
        if (!onRecordsUpdate) return;

        const quantity = selectedQuantity;
        const unitPrice = record.purchase_price ?? 0;
        const discount = 0;

        const retailPrice = record.retail_price ?? 0;

        const total_price = calculateTotalPrice(quantity, retailPrice, discount);

        const saleRecord: SaleRecord = {
            id: 0, // Assuming this is a new record and id will be set later
            stock_id: 0,
            vat: '0',
            record_id: record.id,
            quantity: quantity,
            discount: discount,
            price: retailPrice,
            total_price: total_price,
            parent_record: record,
        };

        const currentRecords = Object.values(editableRecords);
        // Prepend the new record so the most recently added appears as the first row
        const newRecordsArray = [saleRecord, ...currentRecords];
        const newEditableRecordsState = newRecordsArray.reduce(
            (acc, rec, idx) => {
                acc[idx.toString()] = rec;
                return acc;
            },
            {} as { [key: string]: SaleRecord },
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

    const handleRecordChange = (key: string, field: keyof SaleRecord | string, value: string | number) => {
        const record = editableRecords[key];
        if (!record) return;

        let processedValue: string | number;

        const stringValue = String(value);

        if (field === 'quantity' || field === 'discount') {
            const parsed = parseInt(stringValue, 10);
            processedValue = isNaN(parsed) ? 0 : parsed;
        } else if (field === 'price') {
            // Keep as string to preserve decimal format (e.g., "140.00")
            // parseFloat() strips decimals and causes Money to interpret as cents
            processedValue = stringValue;
        } else if (field === 'barcode') {
            // Apply barcode cleanup
            processedValue = cleanBarcode(stringValue);
        } else {
            processedValue = stringValue;
        }

        const updatedRecord = {
            ...record,
            [field]: processedValue,
        };

        // Recalculate total price if necessary
        if (['quantity', 'price', 'discount'].includes(field)) {
            const qVal = updatedRecord.quantity;
            const upVal = updatedRecord.price;
            const dVal = updatedRecord.discount;

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
            {} as { [key: string]: SaleRecord },
        );

        // Update local state
        setEditableRecords(newEditableRecordsState);
        // Notify parent with the calculated array

        if (onRecordsUpdate) {
            onRecordsUpdate(newRecordsArray);
        }
    };

    return (
        <div className='space-y-4'>
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
                                        {header.key ? (
                                            (() => {
                                                // Column: Total Price (Display Only)
                                                if (header.key === 'total_price') {
                                                    let totalPriceNum: number;
                                                    if (typeof item.total_price === 'string') {
                                                        totalPriceNum = parseFloat(item.total_price.replace(',', '.'));
                                                    } else if (typeof item.total_price === 'number') {
                                                        totalPriceNum = item.total_price;
                                                    } else {
                                                        totalPriceNum = 0;
                                                    }
                                                    //return (isNaN(totalPriceNum) ? 0 : totalPriceNum).toFixed(2);
                                                    const formattedPrice = new Intl.NumberFormat('it-IT', {
                                                        style: 'currency',
                                                        currency: 'EUR',
                                                    }).format(isNaN(totalPriceNum) ? 0 : totalPriceNum);
                                                    return formattedPrice;
                                                }

                                                if (header.key === 'position') {
                                                    const stocks = item?.parent_record?.stock ?? [];

                                                    const filteredStocks = stocks.filter(stock =>
                                                        stock.area_location_ids?.some(
                                                            location => location.id === selectedLocationId,
                                                        ),
                                                    );

                                                    return (
                                                        <Combo
                                                            items={filteredStocks}
                                                            displayValue={'area_name'}
                                                            selected={stocks.find(s => s.id === item.stock_id)}
                                                            onChange={stock => {
                                                                if (stock) {
                                                                    handleRecordChange(key, 'stock_id', stock.id);
                                                                }
                                                            }}
                                                            onClear={() => {
                                                                handleRecordChange(key, 'stock_id', '');
                                                            }}
                                                        />
                                                    );
                                                }

                                                // Column: Quantity, Discount, VAT (Numeric Inputs for all records)
                                                if (['quantity'].includes(header.key)) {
                                                    return (
                                                        <Input
                                                            type='number'
                                                            value={String(item[header.key as keyof SaleRecord] ?? '')}
                                                            onChange={e =>
                                                                handleRecordChange(
                                                                    key,
                                                                    header.key as keyof SaleRecord,
                                                                    e.target.value,
                                                                )
                                                            }
                                                            className='w-full h-8'
                                                            ref={key === '0' ? firstQuantityRef : undefined}
                                                        />
                                                    );
                                                }

                                                // Column: Unit Price, Wholesale Price, Retail Price (Currency Inputs for manually added records)
                                                if (['price'].includes(header.key)) {
                                                    const valueKey = header.key as keyof SaleRecord;
                                                    const currentValue =
                                                        item[valueKey] ?? item.parent_record?.retail_price;
                                                    //const currentValue = item.parent_record?.retail_price ?? '';

                                                    const valueForInput =
                                                        currentValue !== null && currentValue !== undefined
                                                            ? String(currentValue)
                                                            : '0';

                                                    return (
                                                        <CurrencyInput
                                                            value={valueForInput}
                                                            onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                                                                handleRecordChange(key, valueKey, e.target.value)
                                                            }
                                                            name={`${header.key}_${key}`}
                                                            id={`${header.key}_${key}`}
                                                            className='w-32 h-8'
                                                        />
                                                    );
                                                }

                                                // Column: Quantity, Discount, VAT (Numeric Inputs for all records)
                                                if (['discount', 'vat'].includes(header.key)) {
                                                    return (
                                                        <Input
                                                            type='number'
                                                            value={String(item[header.key as keyof SaleRecord] ?? '')}
                                                            onChange={e =>
                                                                handleRecordChange(
                                                                    key,
                                                                    header.key as keyof SaleRecord,
                                                                    e.target.value,
                                                                )
                                                            }
                                                            className='w-20 h-8'
                                                        />
                                                    );
                                                }

                                                // Column: Text Inputs for manually added records
                                                if (!item.record_id) {
                                                    let fieldNameForKey = header.key;
                                                    if (header.key === 'artist_name') fieldNameForKey = 'artist';
                                                    else if (header.key === 'format.name') fieldNameForKey = 'format';
                                                    else if (header.key === 'label.name') fieldNameForKey = 'label';

                                                    if (
                                                        [
                                                            'cat_number',
                                                            'barcode',
                                                            'title',
                                                            'artist',
                                                            'format',
                                                            'label',
                                                        ].includes(fieldNameForKey)
                                                    ) {
                                                        return (
                                                            <Input
                                                                type='text'
                                                                value={String(
                                                                    item[fieldNameForKey as keyof SaleRecord] ?? '',
                                                                )}
                                                                onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                                                                    handleRecordChange(
                                                                        key,
                                                                        fieldNameForKey as keyof SaleRecord,
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

                                                // Column: Display Values for imported records
                                                let displayValue: string | number | null | undefined = '';
                                                if (header.key === 'artist_name') {
                                                    displayValue = item.parent_record?.artist?.name ?? '';
                                                } else if (header.key === 'format.name') {
                                                    displayValue = item.parent_record?.format?.name ?? '';
                                                } else if (header.key === 'label.name') {
                                                    displayValue = item.parent_record?.label?.name ?? '';
                                                } else if (header.key.includes('.') && item.parent_record) {
                                                    const [parent, child] = header.key.split('.');
                                                    const parentObj = item.parent_record[parent as keyof Record];
                                                    if (
                                                        typeof parentObj === 'object' &&
                                                        parentObj !== null &&
                                                        child in parentObj
                                                    ) {
                                                        displayValue = parentObj[child as keyof typeof parentObj];
                                                    }
                                                } else {
                                                    // Get value from item first, then fallback to parent_record
                                                    const itemValue = item[header.key as keyof SaleRecord];
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
                                        ) : (
                                            <div className='flex gap-2 justify-center'>
                                                <PrimaryButton
                                                    type={'button'}
                                                    onClick={() => handleRemoveFromMainTable(key)}
                                                    className='w-8 h-8 !p-0 flex items-center justify-center bg-red-600 hover:bg-red-700'>
                                                    <Trash className='h-4 w-4' />
                                                </PrimaryButton>
                                            </div>
                                        )}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>
        </div>
    );
}
