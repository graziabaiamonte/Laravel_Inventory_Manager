import { PaginationData } from '@/Components/atomica/DataTable/Pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Link, router } from '@inertiajs/react';
import { Button, buttonVariants } from '@/Components/ui/button';
import { ArrowRight, ArrowUpIcon } from 'lucide-react';
import React from 'react';
import { Select, SelectContent, SelectItem, SelectTrigger } from '@/Components/ui/select';
import TableHeading from '@/Components/atomica/DataTable/TableHeading';
import { Card } from '@/Components/ui/card';
import { RequestParams } from '@/types';
import SelectedItemAction from '@/Components/atomica/DataTable/SelectedItemAction';
import Checkbox from '@/Components/Checkbox';

export type TableHeaderType = {
    label?: string | React.ReactNode;
    key?: string;
    icon?: React.ReactNode;
    value?: (item: object) => string;
    onHeaderClick?: () => void;
    sortable?: boolean;
    action?: any;
    hideAction?: any;
    alignment?: string;
    danger?: boolean;
    width?: string;
};

export interface TableProps {
    selectable?: boolean;
    selected?: Array<any>;
    headers: Array<TableHeaderType>;
    toggleSelectAll?: (selected: boolean) => void;
    sortBy?: string;
    onSort?: (item: string) => void;
    data: TableData;
    onSelect?: (item: any) => void;
    onClearSelection?: (item: any) => void;
    actionText?: string;
    title?: string;
    description?: string;
    action?: () => void;
    hideAction?: () => void;
    children?: React.ReactNode;
}

export interface TableData extends PaginationData {
    data: Array<any>;
    meta: PaginationData;
}

export type Sortable = {
    direction?: string;
    key: string;
};
export type TableActions = {
    label?: string | React.ReactNode;
    action: () => void;
    hideAction: () => void;
    danger?: boolean;
    icon?: React.ReactNode;
};

export interface DataTableProps extends TableProps {
    title?: string;
    action?: () => void;
    hideAction?: () => void;
    onPagination?: (page: { page: string | number; per_page: string | number }) => void;
    selectedItemsActions?: Array<TableActions>;
    children?: React.ReactNode;
}

function showLink(link: string) {
    return !link.includes(';');
}

function getHeaderValue(header: TableHeaderType, item: any) {
    let value = header.value ? header.value(item) : null;
    if (header.key && header.key.includes('.') && !header.value) {
        header.key.split('.').map((key: any) => {
            if (!value) {
                value = item[key];
            } else {
                value = value[key];
            }
        });
    }
    return value;
}

function getHeaderAlignment(header: TableHeaderType) {
    return `text-${header.alignment && ['center', 'right'].includes(header.alignment) ? header.alignment : 'left'}`;
}

export default function AtomicaTable({
    data,
    headers,
    title,
    description,
    action,
    actionText,
    selectable = false,
    selected = [],
    onSelect,
    onClearSelection,
    selectedItemsActions,
    toggleSelectAll,
    children,
}: DataTableProps) {
    function getAllParams(url = window.location.href) {
        const obj: Record<string, any> = {};

        const paramsArray = url.match(/([^?=&]+)(=([^&]*))/g);

        if (paramsArray) {
            // Iterate the params array
            paramsArray.forEach(query => {
                // Split the array
                const strings = query.split('=');
                //obj[strings[0]] = strings[1];
                obj[strings[0]] = strings[1] ? decodeURIComponent(strings[1]) : strings[1];
            });
        }

        // Return the object
        return obj;
    }

    const routeParams = route().params as RequestParams;

    function getPrevNextButtonPage(link: any) {
        if (link.label.toLowerCase().includes('precedente') || link.label.toLowerCase().includes('previous')) {
            if (routeParams.page) {
                return +routeParams.page - 1;
            }
        }
        if (!link.url) {
            return 1;
        }
        return routeParams.page ? +routeParams.page + 1 : 2;
    }

    const sortingBy = routeParams.sort ? String(routeParams.sort) : '';

    function getSortColumns(header: TableHeaderType) {
        const activeSortable = sortingBy === header.key || sortingBy === `-${header.key}` ? 'opacity-1' : 'opacity-0';
        if (sortingBy === `-${header.key}`) {
            return activeSortable + 'transform rotate-180';
        }
        return activeSortable;
    }

    const perPageItems = routeParams.per_page ?? '100';

    function getNewSortParam(key: any) {
        if (sortingBy === key) {
            return {
                sort: `-${key}`,
            };
        }
        if (sortingBy === `-${key}`) {
            return {
                sort: '',
            };
        }
        return {
            sort: key,
        };
    }

    function changePerPage(e: any) {
        router.reload({
            data: {
                ...getAllParams(),
                per_page: e,
            },
        });
    }

    function showPagination() {
        if (data?.meta) {
            return data.meta.last_page != 1;
        }
        return data.last_page != 1;
    }

    return (
        <div className='flex flex-col gap-3'>
            <TableHeading actionText={actionText} title={title} description={description} action={action} />
            {Boolean(selected?.length) && Boolean(selectedItemsActions?.length) && (
                <div className=''>
                    <div className='w-full flex mt-8 mb-2 min-h-[36px]'>
                        {selectedItemsActions &&
                            selectedItemsActions.map((action, key) => (
                                <SelectedItemAction action={action} selected={selected} key={key} />
                            ))}
                    </div>
                </div>
            )}
            <Card>
                <Table>
                    <TableHeader>
                        <TableRow>
                            {selectable && toggleSelectAll && (
                                <TableHead className=''>
                                    <Checkbox onChange={(e: any) => toggleSelectAll(e)} />
                                </TableHead>
                            )}
                            {headers.map((header, key) => (
                                <TableHead
                                    key={key}
                                    className={`${getHeaderAlignment(header)} ${header.width ? header.width : ''}`}>
                                    {header.sortable ? (
                                        <Link
                                            href={
                                                Object.keys(route().params).length
                                                    ? route(route().current() as string, route().params)
                                                    : route(route().current() as string)
                                            }
                                            data-test={header.sortable}
                                            data={{ ...getAllParams(), ...getNewSortParam(header.key) }}
                                            className='flex cursor-pointer items-center'>
                                            {header.label}
                                            <div>
                                                <ArrowUpIcon
                                                    className={`h-4 w-4 transform transition-all  font-sm ${getSortColumns(header as TableHeaderType)}`}
                                                />
                                            </div>
                                        </Link>
                                    ) : (
                                        <div className='flex items-center'>
                                            {header.label}
                                            <div>
                                                <ArrowUpIcon
                                                    className={`h-4 w-4 transform transition-all font-sm ${getSortColumns(header as TableHeaderType)}`}
                                                />
                                            </div>
                                        </div>
                                    )}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody className={'border-b'}>
                        {Boolean(data.data.length) &&
                            data.data.map((item, i) => (
                                <TableRow key={item?.id} className={item?.rowClassName || ''}>
                                    {selectable && (
                                        <TableCell className='text-center w-[36px] pl-4'>
                                            <Checkbox
                                                checked={
                                                    item && (selected.includes(item) || selected.includes(item?.id))
                                                }
                                                onChange={(e: any) => {
                                                    if (e && onSelect) {
                                                        if (selected.includes(item) || selected.includes(item?.id)) {
                                                            if (onClearSelection) onClearSelection(item);
                                                        } else {
                                                            onSelect(item);
                                                        }
                                                        return;
                                                    }
                                                }}
                                            />
                                        </TableCell>
                                    )}
                                    {headers.map((header, key) =>
                                        header.action ? (
                                            <TableCell key={key} className={`${getHeaderAlignment(header)}`}>
                                                {(!header.hideAction || !header.hideAction(item)) &&
                                                    (!header.icon ? (
                                                        <Button
                                                            onClick={() => header.action(item)}
                                                            variant={
                                                                (header?.danger as boolean) ? 'destructive' : 'outline'
                                                            }>
                                                            {(header?.value as unknown as React.ReactNode) ||
                                                                header.label}
                                                        </Button>
                                                    ) : (
                                                        <Button
                                                            size={'icon'}
                                                            onClick={() => header.action(item)}
                                                            variant={
                                                                (header?.danger as boolean) ? 'destructive' : 'outline'
                                                            }>
                                                            {header.icon}
                                                        </Button>
                                                    ))}
                                            </TableCell>
                                        ) : (
                                            <TableCell key={key} className={`${getHeaderAlignment(header)}`}>
                                                {getHeaderValue(header as TableHeaderType, item) ||
                                                    (header.key ? item[header.key] : '')}
                                            </TableCell>
                                        ),
                                    )}
                                </TableRow>
                            ))}
                    </TableBody>
                </Table>
                <div className='flex gap-4 justify-between m-3'>
                    <Select onValueChange={changePerPage} defaultValue={perPageItems} value={perPageItems}>
                        <SelectTrigger className='w-[70px]'>{perPageItems}</SelectTrigger>
                        <SelectContent>
                            <SelectItem value='10'>10</SelectItem>
                            <SelectItem value='20'>20</SelectItem>
                            <SelectItem value='30'>30</SelectItem>
                            <SelectItem value='100'>100</SelectItem>
                        </SelectContent>
                    </Select>
                    <div className='flex gap-1'>
                        {showPagination() &&
                            (data.meta ? data.meta.links : data.links).map((link, index) =>
                                showLink(link.label as string) ? (
                                    <Link
                                        preserveState
                                        key={index}
                                        data={{ ...getAllParams(), page: link.label }}
                                        href={
                                            Object.keys(route().params).length
                                                ? route(route().current() as string, route().params)
                                                : route(route().current() as string)
                                        }
                                        className={buttonVariants({
                                            variant: 'outline',
                                            size: 'icon',
                                            className: `${link.active && 'bg-primary text-white hover:text-white hover:bg-primary '}`,
                                        })}>
                                        {link.label}
                                    </Link>
                                ) : (
                                    <Link
                                        preserveState
                                        data={{ ...getAllParams(), page: getPrevNextButtonPage(link) }}
                                        className={buttonVariants({
                                            variant: 'outline',
                                            size: 'icon',
                                            className: `${link.label.toLowerCase().includes('precedente') && !link.url ? 'opacity-[0.4]' : ''}`,
                                        })}
                                        key={index}
                                        href={link.url as string}>
                                        <ArrowRight
                                            className={`${link.label.toLowerCase().includes('precedente') || link.label.toLowerCase().includes('previous') ? 'rotate-180' : ''}`}
                                        />
                                    </Link>
                                ),
                            )}
                    </div>
                </div>
            </Card>
        </div>
    );
}
