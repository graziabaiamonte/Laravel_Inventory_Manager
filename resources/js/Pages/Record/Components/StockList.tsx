import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Trash2, Pencil, Plus, Save, Grip, MoveVertical } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { Button } from '@/Components/ui/button';
import { Stock } from '@/types';
import Input from '@/Components/atomica/Forms/Input';
import Combo from '@/Components/atomica/Utils/Combo';
import { router } from '@inertiajs/react';
import Modal from '@/Components/atomica/Utils/Modal';
import Hooks from '@/Components/atomica/Utils/Hooks';

export default function StockList({
    items,
    areas,
    editableAreaIds,
    total_stocks,
    record_id,
    onStocksUpdate,
    createMode = false,
}: {
    items?: Stock[];
    areas: Array<any>;
    editableAreaIds?: number[] | null;
    total_stocks?: number;
    record_id?: number;
    onStocksUpdate: (stock: Stock[]) => void;
    createMode?: boolean;
}) {
    const useDeleteItem = Hooks.useDeleteItem({ routeName: 'stock', only: 'stocks' });

    const previewTableHeaders = [
        { key: 'order_column', label: 'Ordina' },
        { key: 'area', label: 'Area' },
        { key: 'quantity', label: 'Quantità' },
        { key: 'description', label: 'Descrizione' },
    ];

    if (createMode) {
        previewTableHeaders.shift();
    }

    items = items || [];

    areas = areas || [];

    // Helper to check if an area is editable
    const isAreaEditable = (areaId: number) => {
        if (editableAreaIds === null || editableAreaIds === undefined) {
            // Admin (null) or not provided - can edit all
            return true;
        }
        return editableAreaIds.includes(areaId);
    };

    const emptyStockItem: Stock = {
        id: 0,
        record_id: 0,
        area_id: 0,
        quantity: 0,
        description: '',
        order_column: 0,
    };

    //console.log(items);

    const [stockItems, setStockItems] = useState<Stock[]>(items);

    useEffect(() => {
        // Only update if items prop actually changes from parent
        setStockItems(items);
    }, [items]);

    useEffect(() => {
        // Notify parent only when stockItems changes
        // Use a comparison to avoid infinite loops
        const hasChanged = JSON.stringify(stockItems) !== JSON.stringify(items);
        if (hasChanged) {
            onStocksUpdate(stockItems);
        }
    }, [stockItems, items]);

    const [dragging, setDragging] = useState<Stock | null>(null);
    const [draggingOver, setDraggingOver] = useState<Stock | null>(null);

    function handleDragStart(item: any) {
        setDragging(item);
    }

    function swapOrder(e: any) {
        e.preventDefault();
        if (createMode) return;

        if (dragging && draggingOver && dragging !== draggingOver) {
            router.patch(
                route('stock.swap', {
                    dragging: dragging.id,
                    target: draggingOver.id,
                }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        // After successful swap, fetch the updated records
                        router.get(
                            route('record.edit', record_id),
                            {},
                            {
                                preserveScroll: true,
                                preserveState: true,
                                only: ['records'],
                                onSuccess: page => {
                                    const updatedStocks = (page.props.stocks as Stock[]) || [];
                                    if (updatedStocks.length) {
                                        setStockItems(updatedStocks);
                                    }
                                },
                            },
                        );
                    },
                    onFinish: () => {
                        setDraggingOver(null);
                        setDragging(null);
                    },
                },
            );
        }
    }

    return (
        <div className='flex flex-col w-full'>
            <Modal
                type='danger'
                onClose={() => useDeleteItem.changeRecordToDelete(null)}
                onConfirm={() => useDeleteItem.doDelete()}
                show={Boolean(useDeleteItem.recordToDelete)}
            />

            <div className='flex items-center w-full'>
                <div className='text-sm font-semibold'>Totale Stock: {total_stocks}</div>
                <div className='ml-auto'>
                    <Button
                        onClick={e => {
                            e.preventDefault();
                            setStockItems([emptyStockItem, ...stockItems]);
                        }}>
                        {'Aggiungi Stock'} <Plus className='ml-3' />
                    </Button>
                </div>
            </div>

            <span className='text-xs text-muted-foreground mt-5'>
                L'ordinamento è usato per il re-listing su Discogs.
            </span>
            <span className='text-xs text-muted-foreground mb-3'>
                Ordinamento e cancellazione avranno effetto immediato.
            </span>

            <div className='w-full'>
                <Table>
                    <TableHeader>
                        <TableRow>
                            {previewTableHeaders.map((header, key) => (
                                <TableHead key={key}>{header.label}</TableHead>
                            ))}
                            <TableHead>Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {stockItems &&
                            stockItems.map((item, key) => (
                                <TableRow
                                    draggable={!createMode}
                                    onDrop={swapOrder}
                                    onDragOver={e => {
                                        e.preventDefault();
                                        setDraggingOver(item);
                                    }}
                                    onDragStart={() => handleDragStart(item)}
                                    key={key}>
                                    {!createMode && (
                                        <TableCell>
                                            <div className='flex no-wrap cursor-grab mt-2 mb-[20px]'>
                                                <MoveVertical className='w-4' />
                                                <Grip className='w-5' />
                                            </div>
                                        </TableCell>
                                    )}
                                    <TableCell>
                                        {item.area_id && !isAreaEditable(item.area_id) ? (
                                            // Stock area is not editable by user - show read-only
                                            <div className='text-sm py-3 px-3 mb-3 bg-gray-100 rounded text-muted-foreground'>
                                                {item.area?.name || `Area #${item.area_id}`}
                                            </div>
                                        ) : (
                                            <Combo
                                                items={areas.filter(
                                                    a =>
                                                        editableAreaIds === null ||
                                                        editableAreaIds === undefined ||
                                                        editableAreaIds.includes(a.id),
                                                )}
                                                displayValue={'name'}
                                                selected={item.area_id ? areas.find(l => l.id === item.area_id) : null}
                                                onChange={area => {
                                                    if (area) {
                                                        setStockItems((prev: any) => {
                                                            const updatedItems = [...prev];
                                                            updatedItems[key] = {
                                                                ...updatedItems[key],
                                                                area_id: parseInt(area.id),
                                                            };
                                                            return updatedItems;
                                                        });
                                                    }
                                                }}
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell className='w-24'>
                                        <Input
                                            type='number'
                                            value={item.quantity}
                                            disabled={item.area_id ? !isAreaEditable(item.area_id) : false}
                                            onChange={e => {
                                                setStockItems((prev: any) => {
                                                    const updatedItems = [...prev];
                                                    updatedItems[key] = {
                                                        ...updatedItems[key],
                                                        quantity: e.target.value,
                                                    };
                                                    return updatedItems;
                                                });
                                            }}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Input
                                            value={item.description}
                                            disabled={item.area_id ? !isAreaEditable(item.area_id) : false}
                                            onChange={e => {
                                                setStockItems((prev: any) => {
                                                    const updatedItems = [...prev];
                                                    updatedItems[key] = {
                                                        ...updatedItems[key],
                                                        description: e.target.value,
                                                    };
                                                    return updatedItems;
                                                });
                                            }}
                                        />
                                    </TableCell>
                                    <TableCell className='align-top'>
                                        <div className='flex items-start mt-2'>
                                            <Button
                                                variant={'destructive'}
                                                className='mt-0 ml-2 w-10 h-10'
                                                size={'icon'}
                                                disabled={item.area_id ? !isAreaEditable(item.area_id) : false}
                                                onClick={e => {
                                                    e.preventDefault();
                                                    if (item.id) {
                                                        useDeleteItem.changeRecordToDelete(item.id);
                                                    } else {
                                                        setStockItems(prev => {
                                                            const updatedItems = [...prev];
                                                            updatedItems.splice(key, 1);
                                                            return updatedItems;
                                                        });
                                                    }
                                                }}>
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
