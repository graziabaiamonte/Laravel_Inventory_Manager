import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Card } from '@/Components/ui/card';
import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import Toast from '@/Components/atomica/Alerts/Toast';
import { Check, Trash, Eye } from 'lucide-react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Record } from '@/types';
import Input from '@/Components/atomica/Forms/Input';
import { debounce } from 'lodash';
import axios from 'axios';
import Combo from '@/Components/atomica/Utils/Combo';

// Define the search terms interface
interface SearchTerms {
    barcode: string;
    catNumber: string;
    artist: string;
    title: string;
    format: string;
    type: string;
}

// Define preview record interface for type safety
interface PreviewRecord {
    id: number;
    cat_number: string;
    barcode: string;
    artist: string | { name: string };
    title: string;
    format: string | { name: string };
    label: string | { name: string };
    retail_price: number;
    total_warehouse_stocks?: number;
    [key: string]: unknown;
}

interface RecordSearchAndPreviewProps {
    showPreview: boolean;
    onRecordSelect: (record: Record, quantity?: number) => void;
    onRecordRemove: (record: Record) => void;
    showWarehouseStock?: boolean;
    initialSearchTerms?: Partial<SearchTerms>;
    types_status?: Array<any>;
}

const basePreviewTableHeaders: Array<{ key: string; label: React.ReactNode }> = [
    { key: 'barcode', label: 'Barcode' },
    { key: 'cat_number', label: 'Cat. #' },
    { key: 'artist', label: 'Artista' },
    { key: 'title', label: 'Titolo' },
    { key: 'format', label: 'Fmt' },
    { key: 'label', label: 'Etichetta' },
    {
        key: 'type_name',
        label: (
            <>
                Nuovo/
                <br />
                Usato
            </>
        ),
    },
    { key: 'total_stocks', label: 'Tot. Stock' },
    { key: 'retail_price', label: 'Prezzo di vendita' },
];

export default function RecordSearchAndPreview({
    showPreview,
    onRecordSelect,
    onRecordRemove,
    showWarehouseStock = false,
    initialSearchTerms = {},
    types_status,
}: RecordSearchAndPreviewProps) {
    const [successMessage, setSuccessMessage] = useState('');
    const [showSuccess, setShowSuccess] = useState(false);
    const [previewData, setPreviewData] = useState<Record[]>([]);
    // Kept as a raw string so the field can be emptied while typing (backspace/canc)
    const [previewQuantity, setPreviewQuantity] = useState('1');
    const quantityInputRef = useRef<{ focus: () => void }>(null);

    // Numeric value used when the quantity is actually needed
    const parsedPreviewQuantity = Math.max(1, parseInt(previewQuantity, 10) || 1);
    const [searchTerms, setSearchTerms] = useState<SearchTerms>({
        barcode: initialSearchTerms.barcode || '',
        catNumber: initialSearchTerms.catNumber || '',
        artist: initialSearchTerms.artist || '',
        title: initialSearchTerms.title || '',
        format: initialSearchTerms.format || '',
        type: initialSearchTerms.type || '',
    });

    // Simple update function - no quantity management here
    const updateSearchTerms = (field: keyof SearchTerms, value: string) => {
        setSearchTerms(prev => ({ ...prev, [field]: value }));
    };

    const previewTableHeaders = useMemo(() => {
        const cols = [...basePreviewTableHeaders];
        // Add “Stock magazzino” AFTER “Numero catalogo”
        if (showWarehouseStock) {
            cols.splice(2, 0, { key: 'total_warehouse_stocks', label: 'Stock magazzino' });
        }
        return cols;
    }, [showWarehouseStock]);

    // Search function without debounce
    const performSearch = useCallback(async (terms: SearchTerms) => {
        // Only search if at least one field has content
        const hasSearchTerms = Object.values(terms).some(term => term.trim() !== '');

        if (!hasSearchTerms) {
            setPreviewData([]);
            return;
        }

        try {
            const queryParams = new URLSearchParams();

            // Add filters only if they have values (for AND logic)
            if (terms.catNumber.trim()) {
                queryParams.append('filter[cat_number]', terms.catNumber.trim());
            }
            if (terms.barcode.trim()) {
                queryParams.append('filter[barcode]', terms.barcode.trim());
            }
            if (terms.artist.trim()) {
                queryParams.append('filter[artist]', terms.artist.trim());
            }
            if (terms.title.trim()) {
                queryParams.append('filter[title]', terms.title.trim());
            }
            if (terms.format.trim()) {
                queryParams.append('filter[format]', terms.format.trim());
            }
            if (terms.type.trim()) {
                queryParams.append('filter[type]', terms.type.trim());
            }

            queryParams.append('orderByTotalStock', '1');

            const response = await axios.get(`${route('record.index')}?${queryParams.toString()}`, {
                headers: {
                    Accept: 'application/json',
                },
            });

            // Handle both array response and object with data property
            const records = Array.isArray(response.data) ? response.data : response.data.data || [];
            setPreviewData(records);
        } catch (error) {
            console.error('Search error:', error);
            setPreviewData([]);
        }
    }, []);

    // Debounced search function
    const debouncedSearch = useMemo(() => debounce(performSearch, 300), [performSearch]);

    // Update search when terms change
    useEffect(() => {
        debouncedSearch(searchTerms);
    }, [searchTerms, debouncedSearch]);

    // Cleanup debounced function on unmount
    useEffect(() => {
        return () => {
            debouncedSearch.cancel();
        };
    }, [debouncedSearch]);

    // Reset quantity and auto-focus the quantity field when results change to exactly 1
    useEffect(() => {
        setPreviewQuantity('1');
        if (previewData.length === 1) {
            quantityInputRef.current?.focus();
        }
    }, [previewData.length]);

    // Update search terms when initialSearchTerms change
    const initialBarcode = initialSearchTerms?.barcode || '';
    const initialCatNumber = initialSearchTerms?.catNumber || '';
    const initialArtist = initialSearchTerms?.artist || '';
    const initialTitle = initialSearchTerms?.title || '';
    const initialFormat = initialSearchTerms?.format || '';
    const initialType = initialSearchTerms?.type || '';

    useEffect(() => {
        if (
            showPreview &&
            (initialBarcode || initialCatNumber || initialArtist || initialTitle || initialFormat || initialType)
        ) {
            setSearchTerms({
                barcode: initialBarcode,
                catNumber: initialCatNumber,
                artist: initialArtist,
                title: initialTitle,
                format: initialFormat,
                type: initialType,
            });
        }
    }, [initialBarcode, initialCatNumber, initialArtist, initialTitle, initialFormat, initialType, showPreview]);

    const handleClearFilters = () => {
        setPreviewData([]);
        setSearchTerms({
            barcode: '',
            catNumber: '',
            artist: '',
            title: '',
            format: '',
            type: '',
        });
    };

    const handleAddRecord = (record: Record) => {
        onRecordSelect(record, parsedPreviewQuantity);
        // Reset quantity and filters after adding a record to the main table
        setPreviewQuantity('1');
        handleClearFilters();
        // Show success toast
        setSuccessMessage(`${record.artist?.name} - "${record.title}" aggiunto`);
        setShowSuccess(true);
        setTimeout(() => setShowSuccess(false), 5000);
    };

    const handleRemoveRecord = (record: Record) => {
        onRecordRemove(record);
        // Remove the record from preview table
        setPreviewData(prev => prev.filter(r => r.id !== record.id));
    };

    // Canc/Backspace empty the field even when its content isn't selected: the field is
    // auto-focused programmatically, which leaves the caret at one edge of the value, so
    // the browser's own deletion is a no-op for one of the two keys.
    const handleQuantityKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Backspace' || e.key === 'Delete') {
            e.preventDefault();
            setPreviewQuantity('');

            return;
        }

        handleFilterKeyDown(e);
    };

    const handleFilterKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            e.preventDefault(); // Prevent form submission

            // Only auto-add if there's exactly one result
            if (previewData.length === 1) {
                handleAddRecord(previewData[0]);
            }
        }
    };

    if (!showPreview) {
        return null;
    }

    return (
        <Card className='rounded-b-none bg-gray-100'>
            <Toast
                show={showSuccess}
                variant='success'
                message={successMessage}
                onClose={() => setShowSuccess(false)}
            />
            <div className='p-4 space-y-4'>
                {/* Search Inputs */}
                <div
                    className='grid gap-2 items-end'
                    style={{ gridTemplateColumns: '1fr 1fr 1fr 1fr 1fr 0.6fr 1fr 0.5fr' }}>
                    <div>
                        <Input
                            type='text'
                            value={searchTerms.barcode}
                            onChange={e => updateSearchTerms('barcode', e.target.value)}
                            onKeyDown={handleFilterKeyDown}
                            placeholder='Codice a barre / Id'
                        />
                    </div>
                    <div>
                        <Input
                            type='text'
                            value={searchTerms.catNumber}
                            onChange={e => updateSearchTerms('catNumber', e.target.value)}
                            onKeyDown={handleFilterKeyDown}
                            placeholder='Numero catalogo'
                        />
                    </div>
                    <div>
                        <Input
                            type='text'
                            value={searchTerms.artist}
                            onChange={e => updateSearchTerms('artist', e.target.value)}
                            onKeyDown={handleFilterKeyDown}
                            placeholder='Artista'
                        />
                    </div>
                    <div>
                        <Input
                            type='text'
                            value={searchTerms.title}
                            onChange={e => updateSearchTerms('title', e.target.value)}
                            onKeyDown={handleFilterKeyDown}
                            placeholder='Titolo'
                        />
                    </div>
                    <div>
                        <Input
                            type='text'
                            value={searchTerms.format}
                            onChange={e => updateSearchTerms('format', e.target.value)}
                            onKeyDown={handleFilterKeyDown}
                            placeholder='Formato'
                        />
                    </div>
                    <div>
                        <Input
                            ref={quantityInputRef}
                            type='number'
                            min={1}
                            step={1}
                            value={previewQuantity}
                            onChange={e => setPreviewQuantity(e.target.value)}
                            // Selects the value on focus so any key replaces it
                            onFocus={e => e.target.select()}
                            onBlur={() => setPreviewQuantity(String(parsedPreviewQuantity))}
                            onKeyDown={handleQuantityKeyDown}
                            placeholder='Quantità'
                            className='w-full'
                        />
                    </div>
                    <div>
                        <Combo
                            items={types_status ?? []}
                            placeholder={'Nuovo/Usato'}
                            displayValue={'description'}
                            onChange={e => updateSearchTerms('type', e.value)}
                            selected={
                                searchTerms.type && searchTerms.type.trim() !== ''
                                    ? types_status?.find(t => t.value == searchTerms.type)
                                    : null
                            }
                            onClear={() => {
                                updateSearchTerms('type', '');
                            }}
                        />
                    </div>

                    <div>
                        <div className='flex flex-col items-center'>
                            <SecondaryButton type='button' onClick={handleClearFilters} className='h-10'>
                                Pulisci filtri
                            </SecondaryButton>
                            <div className='h-[20px]'></div>
                        </div>
                    </div>
                </div>

                {/* Preview Table */}
                {previewData.length > 0 && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead></TableHead>
                                {previewTableHeaders.map((header, key) => (
                                    <TableHead key={key}>{header.label}</TableHead>
                                ))}
                                <TableHead>Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {previewData.map((item, i) => (
                                <TableRow key={i}>
                                    <TableCell>
                                        <PrimaryButton
                                            type={'button'}
                                            onClick={() =>
                                                window.open(route('record.edit', { record: item.id }), '_blank')
                                            }
                                            className='w-8 h-8 !p-0 flex items-center justify-center'>
                                            <Eye className='h-4 w-4' />
                                        </PrimaryButton>
                                    </TableCell>
                                    {previewTableHeaders.map((header, key) => (
                                        <TableCell key={key}>
                                            {header.key && item !== null
                                                ? (() => {
                                                      // Special case: 0 if not present
                                                      if (header.key === 'retail_price') {
                                                          const currentValue = String(
                                                              (item as PreviewRecord).retail_price ?? 0,
                                                          );

                                                          const valueForInput =
                                                              currentValue !== null && currentValue !== undefined
                                                                  ? parseFloat(String(currentValue).replace(',', '.'))
                                                                  : 0;
                                                          return new Intl.NumberFormat('it-IT', {
                                                              style: 'currency',
                                                              currency: 'EUR',
                                                          }).format(isNaN(valueForInput) ? 0 : valueForInput);
                                                      }

                                                      // Special case: 0 if not present
                                                      if (header.key === 'total_warehouse_stocks') {
                                                          return String(
                                                              (item as PreviewRecord).total_warehouse_stocks ?? 0,
                                                          );
                                                      }

                                                      const value = (item as Record & PreviewRecord)[
                                                          header.key as keyof PreviewRecord
                                                      ];
                                                      if (value === null || value === undefined) {
                                                          return '';
                                                      }

                                                      if (typeof value === 'object' && value !== null) {
                                                          return (value as { name?: string })?.name || '';
                                                      }

                                                      if (header.key === 'type_name') {
                                                          return (
                                                              <span
                                                                  className={`px-2 py-5 rounded-md font-bold uppercase text-[11px] ${
                                                                      String(value).toLowerCase() === 'nuovo'
                                                                          ? 'bg-black text-white'
                                                                          : 'bg-green-100 text-green-800'
                                                                  }`}>
                                                                  {String(value)}
                                                              </span>
                                                          );
                                                      }

                                                      return String(value);
                                                  })()
                                                : ''}
                                        </TableCell>
                                    ))}
                                    <TableCell className='text-center'>
                                        <div className='flex gap-2 justify-center'>
                                            <PrimaryButton
                                                type={'button'}
                                                onClick={() => handleAddRecord(item)}
                                                className='w-8 h-8 !p-0 flex items-center justify-center'>
                                                <Check className='h-4 w-4' />
                                            </PrimaryButton>
                                            <PrimaryButton
                                                type={'button'}
                                                onClick={() => handleRemoveRecord(item)}
                                                className='w-8 h-8 !p-0 flex items-center justify-center bg-red-600 hover:bg-red-700'>
                                                <Trash className='h-4 w-4' />
                                            </PrimaryButton>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </Card>
    );
}
