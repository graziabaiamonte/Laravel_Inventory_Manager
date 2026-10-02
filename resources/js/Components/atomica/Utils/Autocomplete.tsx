import React, { useEffect, useState, forwardRef, useImperativeHandle, useRef } from 'react';
import debounce from 'lodash/debounce';
import axios from 'axios';
import { XMarkIcon } from '@heroicons/react/20/solid';
import { Label } from '@/Components/ui/label';

interface AutocompleteItem {
    id?: string | number;
    name?: string;
    [key: string]: unknown;
}

interface AutocompleteProps {
    routeName: string;
    displayValue?: string;
    value?: AutocompleteItem | string | null;
    onChange: (item: AutocompleteItem | string | null) => void;
    onClear?: () => void;
    placeholder?: string;
    filterKey?: string;
    initialValue?: AutocompleteItem | string | null;
    error?: string | null;
    className?: string;
    inputClassName?: string;
    showErrorSpace?: boolean;
    label?: string;
    allowCreate?: boolean; // When true, shows "Create new" option if no results found
}

export interface AutocompleteRef {
    clear: () => void;
}

const Autocomplete = forwardRef<AutocompleteRef, AutocompleteProps>(
    (
        {
            routeName,
            displayValue = 'name',
            value,
            initialValue,
            onChange,
            onClear,
            placeholder,
            filterKey = 'name',
            error = null,
            className = '',
            inputClassName = '',
            showErrorSpace = true,
            label,
            allowCreate = false, // Default to false for backward compatibility
        },
        ref,
    ) => {
        const [items, setItems] = useState<Array<AutocompleteItem | string>>([]);
        const [selectedItem, setSelectedItem] = useState<AutocompleteItem | string | null>(null);
        const [loading, setLoading] = useState(false);
        const [hasMore, setHasMore] = useState(true);
        const [currentPage, setCurrentPage] = useState(1);
        const [currentSearch, setCurrentSearch] = useState('');

        const inputRef = useRef<HTMLInputElement>(null);
        const dropdownRef = useRef<HTMLUListElement>(null);
        const [isOpen, setIsOpen] = useState(false);
        const [inputValue, setInputValue] = useState('');

        useEffect(() => {
            if (value) {
                setSelectedItem(value);
                setInputValue(typeof value === 'object' ? (value[displayValue] as string) : value);
            } else if (initialValue) {
                setSelectedItem(initialValue);
                setInputValue(typeof initialValue === 'object' ? (initialValue[displayValue] as string) : initialValue);
            }
        }, [value, initialValue, displayValue]);

        const performSearch = async (query: string) => {
            if (query.length < 2) {
                setItems([]);
                setHasMore(false);
                return;
            }

            if (query !== currentSearch) {
                setCurrentSearch(query);
                setCurrentPage(1);
                setItems([]);
                setHasMore(true);
            }

            setLoading(true);

            try {
                const response = await axios.get(
                    route(routeName, {
                        filter: { [filterKey]: query },
                        page: 1,
                        per_page: 100,
                    }),
                    {
                        headers: {
                            Accept: 'application/json',
                        },
                    },
                );

                const responseData = Array.isArray(response.data) ? response.data : response.data.data || [];
                const hasMoreData =
                    response.data.has_more !== undefined ? response.data.has_more : responseData.length >= 100;

                setItems(responseData);
                setHasMore(hasMoreData);
                setCurrentPage(1);
            } catch (error) {
                console.error('Search failed:', error);
                setItems([]);
                setHasMore(false);
            } finally {
                setLoading(false);
            }
        };

        const debouncedSearch = debounce(performSearch, 300);

        const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
            const query = e.target.value;
            setInputValue(query);
            setIsOpen(true);

            // Immediately update the input value for typing responsiveness
            // But debounce the actual search
            debouncedSearch(query);
        };

        const loadMoreItems = async () => {
            if (loading || !hasMore || !currentSearch) return;

            setLoading(true);
            const nextPage = currentPage + 1;

            try {
                const response = await axios.get(
                    route(routeName, {
                        filter: { [filterKey]: currentSearch },
                        page: nextPage,
                        per_page: 100,
                    }),
                    {
                        headers: {
                            Accept: 'application/json',
                        },
                    },
                );

                const responseData = Array.isArray(response.data) ? response.data : response.data.data || [];
                const hasMoreData =
                    response.data.has_more !== undefined ? response.data.has_more : responseData.length >= 100;

                setItems(prev => [...prev, ...responseData]);
                setHasMore(hasMoreData);
                setCurrentPage(nextPage);
            } catch (error) {
                console.error('Load more failed:', error);
                setHasMore(false);
            } finally {
                setLoading(false);
            }
        };

        const handleScroll = (e: React.UIEvent<HTMLUListElement>) => {
            const { scrollTop, scrollHeight, clientHeight } = e.currentTarget;
            if (scrollHeight - scrollTop <= clientHeight * 1.5) {
                loadMoreItems();
            }
        };

        const handleItemClick = (item: AutocompleteItem | string) => {
            setSelectedItem(item);
            setInputValue(typeof item === 'object' ? (item[displayValue] as string) : item);
            setIsOpen(false);
            onChange(item);
        };

        const handleCreateNew = () => {
            // Create a new item object with id: 0 to signal creation
            const newItem: AutocompleteItem = {
                id: 0,
                [displayValue]: inputValue,
            };
            setSelectedItem(newItem);
            setIsOpen(false);
            onChange(newItem);
        };

        const handleClear = () => {
            setSelectedItem(null);
            setInputValue('');
            setItems([]);
            setIsOpen(false);
            onChange(null);
            onClear?.();
        };

        const handleInputFocus = () => {
            if (inputValue.length >= 2) {
                setIsOpen(true);
            }
        };

        const handleInputBlur = () => {
            // Delay closing to allow item clicks
            setTimeout(() => setIsOpen(false), 150);
        };

        useImperativeHandle(ref, () => ({
            clear: () => {
                handleClear();
            },
        }));

        return (
            <div className={`relative ${className}`}>
                {label && <Label className='block text-sm font-medium text-gray-700 mb-2'>{label}</Label>}
                <div className='relative'>
                    <input
                        ref={inputRef}
                        type='text'
                        value={inputValue}
                        onChange={handleInputChange}
                        onFocus={handleInputFocus}
                        onBlur={handleInputBlur}
                        placeholder={placeholder}
                        className={`max-h-[40px] h-10 w-full rounded-md border-input shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 focus:border-indigo-500 pr-10 text-sm ${error ? 'border-red-300 focus:border-red-500 focus:ring-red-500' : ''} ${inputClassName}`}
                    />

                    {selectedItem && (
                        <button
                            type='button'
                            onClick={handleClear}
                            className='absolute inset-y-0 right-0 flex items-center pr-3'>
                            <XMarkIcon className='h-4 w-4 text-gray-400 hover:text-gray-600' />
                        </button>
                    )}
                </div>

                {error && <p className='mt-1 text-sm text-red-600'>{error}</p>}
                {showErrorSpace && <div className='h-[20px]'></div>}

                {isOpen && (
                    <ul
                        ref={dropdownRef}
                        onScroll={handleScroll}
                        className='absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none'>
                        {loading && items.length === 0 && (
                            <li className='py-2 pl-3 pr-9 text-sm text-gray-500'>Searching...</li>
                        )}

                        {items.map((item, index) => (
                            <li
                                key={`${typeof item === 'object' ? (item as AutocompleteItem).id || index : item}-${index}`}
                                onMouseDown={e => {
                                    e.preventDefault();
                                    handleItemClick(item);
                                }}
                                className='cursor-pointer select-none py-2 pl-3 pr-9 text-sm text-gray-900 hover:bg-indigo-600 hover:text-white'>
                                {typeof item === 'object' ? ((item as AutocompleteItem)[displayValue] as string) : item}
                            </li>
                        ))}

                        {loading && items.length > 0 && (
                            <li className='py-2 pl-3 pr-9 text-sm text-gray-500'>Loading more...</li>
                        )}

                        {hasMore && !loading && items.length > 0 && (
                            <li className='py-2 pl-3 pr-9 text-sm text-gray-400'>Scroll for more results</li>
                        )}

                        {!loading && !hasMore && items.length === 0 && currentSearch.length >= 2 && (
                            <>
                                {allowCreate ? (
                                    <li
                                        onMouseDown={e => {
                                            e.preventDefault();
                                            handleCreateNew();
                                        }}
                                        className='cursor-pointer select-none py-2 pl-3 pr-9 text-sm font-medium text-indigo-600 hover:bg-indigo-600 hover:text-white'>
                                        Crea nuovo: &quot;{inputValue}&quot;
                                    </li>
                                ) : (
                                    <li className='py-2 pl-3 pr-9 text-sm text-gray-500'>No results found</li>
                                )}
                            </>
                        )}
                    </ul>
                )}
            </div>
        );
    },
);

Autocomplete.displayName = 'Autocomplete';

export default Autocomplete;
