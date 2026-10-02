import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Card } from '@/Components/ui/card';
import React, { useState, useEffect } from 'react';
import type { TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { BackOrderRecord, AreaQuantity, Area, WholesaleOutLabelDiscount, Stock } from '@/types';
import Input from '@/Components/atomica/Forms/Input';
import InputError from '@/Components/InputError';
import Combo from '@/Components/atomica/Utils/Combo';

interface AttachRecordsTableProps {
    dataSource?: BackOrderRecord[];
    description?: string;
    onRecordsUpdate: (records: BackOrderRecord[]) => void;
    onFileSelect?: (file: File) => void;
    onBulkDiscountUpdate?: (labelDiscounts: WholesaleOutLabelDiscount[]) => void;
    errors?: { [key: string]: string | undefined };
    isEditing?: boolean;
    importWarnings?: string[];
    areas?: Area[];
    isEditingDisabled?: boolean;
}

export default function AttachRecordsTable({
    description,
    onRecordsUpdate,
    onFileSelect,
    onBulkDiscountUpdate,
    dataSource,
    errors,
    isEditing,
    importWarnings = [],
    areas,
    isEditingDisabled = false,
}: AttachRecordsTableProps) {
    const [editableRecords, setEditableRecords] = useState<{ [key: string]: BackOrderRecord }>({});

    const getTableHeaders = (): Array<TableHeaderType> => {
        const baseHeaders = [
            { label: 'Cat. #', key: 'cat_number' },
            { label: 'Barcode', key: 'barcode' },
            {
                label: 'Artista',
                key: 'artist_name',
                value: (item: object) => {
                    // item is of type object as per TableHeaderType
                    const record = item as BackOrderRecord;
                    return record.parent_record?.artist?.name ?? record.artist_name ?? '';
                },
            },
            { label: 'Titolo', key: 'title' }, // Assuming title comes directly from BackOrderRecord or its parent_record.title
            {
                label: 'Fmt',
                key: 'format_name',
                value: (item: object) => {
                    const record = item as BackOrderRecord;
                    return record.parent_record?.format?.name ?? record.format_name ?? '';
                },
            },
            {
                label: 'Etichetta',
                key: 'label_name',
                value: (item: object) => {
                    const record = item as BackOrderRecord;
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
                    const record = item as BackOrderRecord;
                    // Use the available_quantity field from the API response
                    return record.available_quantity?.toString() ?? '0';
                },
            },
            {
                label: 'Spedito',
                key: 'shipped_quantity',
                value: (item: object) => {
                    const record = item as BackOrderRecord;
                    return record.shipped_quantity?.toString() ?? '0';
                },
            },
            // },
            { label: 'Prezzo unitario', key: 'unit_price' },
            { label: 'Sconto %', key: 'discount' },
            { label: 'Totale', key: 'total_price' },
            { label: 'IVA', key: 'vat' },
            //{ label: '', key: '' }, // For actions column
        );

        return baseHeaders;
    };

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

    useEffect(() => {
        if (dataSource?.length) {
            const records = dataSource.reduce(
                (acc, record, index) => {
                    // Explicitly preserve all fields including area_quantities
                    const modifiableRecord: BackOrderRecord = {
                        ...record,
                        // Explicitly ensure area_quantities is preserved
                        area_quantities: record.area_quantities || [],
                    };
                    acc[index.toString()] = modifiableRecord;
                    return acc;
                },
                {} as { [key: string]: BackOrderRecord },
            );
            setEditableRecords(records);
        } else {
            setEditableRecords({});
        }
    }, [dataSource]);

    // Main table record change handler
    const handleRecordChange = (key: string, field: keyof BackOrderRecord, value: string | number | AreaQuantity[]) => {
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
                // parseFloat() strips decimals and causes Money to interpret as cents
                processedValue = stringValue;
            } else if (field === 'vat') {
                processedValue = stringValue;
            } else {
                processedValue = stringValue;
            }
        } else {
            return; // Invalid value type
        }

        const updatedRecord: BackOrderRecord = {
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

            updatedRecord.total_price = Number(
                (qVal * (isNaN(numUnitPrice) ? 0 : numUnitPrice) * (1 - dVal / 100)).toFixed(2),
            );
        }

        const newEditableRecords = { ...editableRecords, [key]: updatedRecord };
        setEditableRecords(newEditableRecords);
        onRecordsUpdate?.(Object.values(newEditableRecords));
    };

    return (
        <div className='space-y-4'>
            {/* Actions Bar */}
            <div className='flex items-center justify-between'>
                <p className='text-sm font-medium text-gray-900'>{description}</p>
            </div>

            {/* Main Table */}
            <Card className={''}>
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
                                        {header.key ? (
                                            (() => {
                                                const currentItem = item as BackOrderRecord;
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
                                                    //return (isNaN(totalPriceNum) ? 0 : totalPriceNum).toFixed(2);
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

                                                    const selectedArea = areas?.find(area => area.id === currentAreaId);

                                                    const stockRecords = item.parent_record?.stock || [];

                                                    // Create areas with stock quantities
                                                    const areasWithQuantities =
                                                        areas?.map(area => {
                                                            const matchingStock = stockRecords.find((stock: Stock) => {
                                                                return stock.area_id === area.id;
                                                            });

                                                            const stockQuantity = matchingStock?.quantity || 0;

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

                                                    // Determine highlight class based on quantity comparison
                                                    const getQuantityHighlight = () => {
                                                        if (availableQuantity === 0) {
                                                            return 'bg-yellow-100 border-yellow-600'; // No stock available
                                                        } else if (currentQuantity > availableQuantity) {
                                                            return 'bg-red-100 border-red-600'; // Overselling
                                                        } else if (
                                                            currentQuantity > 0 &&
                                                            currentQuantity <= availableQuantity
                                                        ) {
                                                            return 'bg-green-100 border-green-600'; // Safe quantity (underselling or perfect match)
                                                        }
                                                        return ''; // Default state (quantity is 0 and available > 0)
                                                    };

                                                    return (
                                                        <div className='relative flex flex-col gap-2'>
                                                            <div className='flex gap-2'>
                                                                <Input
                                                                    type='number'
                                                                    value={String(currentQuantity)}
                                                                    className={`w-20 h-8 ${getQuantityHighlight()}`}
                                                                    readOnly
                                                                />
                                                            </div>
                                                        </div>
                                                    );
                                                }

                                                if (header.key === 'unit_price') {
                                                    const valueKey = header.key as keyof BackOrderRecord;
                                                    const currentValue = currentItem[valueKey];
                                                    const valueForInput =
                                                        currentValue !== null && currentValue !== undefined
                                                            ? parseFloat(String(currentValue).replace(',', '.'))
                                                            : 0;
                                                    return new Intl.NumberFormat('it-IT', {
                                                        style: 'currency',
                                                        currency: 'EUR',
                                                    }).format(isNaN(valueForInput) ? 0 : valueForInput);
                                                }

                                                // Default rendering for other keys: direct property access
                                                // This will render properties like cat_number, barcode, title directly
                                                // if they are not handled by a value function or specific input type.
                                                return String(currentItem[header.key as keyof BackOrderRecord] ?? '');
                                            })()
                                        ) : (
                                            <></>
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
                            <li key={idx}>{warn}</li> // Correctly renders string warnings
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
