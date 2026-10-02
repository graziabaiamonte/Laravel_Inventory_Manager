/*
  This example requires some changes to your config:

  ```
  // tailwind.config.js
  module.exports = {
    // ...
    plugins: [
      // ...
      require('@tailwindcss/forms'),
    ],
  }
  ```
*/
import React, { useState } from 'react';
import { CheckIcon, ChevronUpDownIcon, XMarkIcon } from '@heroicons/react/20/solid';
import { Combobox } from '@headlessui/react';
import InputError from '@/Components/InputError';
import { Button } from '@/Components/ui/button';
import { Trash } from 'lucide-react';

function classNames(...classes: Array<string>) {
    return classes.filter(Boolean).join(' ');
}

export default function Combo({
    items,
    label,
    selected,
    onChange,
    displayValue,
    error = '',
    onInputChange,
    multiple = false,
    async = false,
    onChipsDelete,
    by = null,
    onClear,
    disabled = false,
    placeholder,
}: {
    items: Array<any>;
    label?: string;
    selected?: any;
    onChange: (item: any) => any;
    displayValue?: string;
    error?: string;
    onInputChange?: (e: any) => void;
    multiple?: boolean;
    async?: boolean;
    onChipsDelete?: (item: any) => void;
    by?: string | null;
    onClear?: () => void;
    disabled?: boolean;
    placeholder?: string;
}) {
    const [query, setQuery] = useState('');

    const filteredItems =
        query === ''
            ? items
            : !async
              ? items.filter(item => {
                    return displayValue
                        ? item[displayValue].toLowerCase().includes(query.toLowerCase())
                        : String(item).includes(query.toLowerCase());
                })
              : items;

    function compareToSelection(selected: any, selectItem: any) {
        if (by) {
            return selected == selectItem[by];
        }
        return selected == selectItem;
    }

    return (
        <Combobox
            by={compareToSelection}
            as='div'
            value={selected}
            {...(multiple as unknown as object)}
            onChange={disabled ? () => {} : onChange}
            disabled={disabled}>
            <Combobox.Label
                className={classNames(
                    'block text-sm font-medium cursor-pointer',
                    disabled ? 'text-gray-400' : 'text-gray-700',
                )}>
                {label && label}
            </Combobox.Label>
            <div className='relative mt-2'>
                <Combobox.Button as='div' className='relative w-full'>
                    {Boolean(selected) && onClear && !disabled && (
                        <div
                            onMouseDown={e => {
                                e.preventDefault();
                                e.stopPropagation();
                            }}
                            onPointerDown={e => {
                                e.preventDefault();
                                e.stopPropagation();
                            }}
                            onClick={e => {
                                e.preventDefault();
                                e.stopPropagation();
                                onClear();
                            }}
                            className='h-full absolute right-[40px] z-10 grid place-items-center cursor-pointer'>
                            <XMarkIcon className={'h-7 w-7 text-gray-400 '}></XMarkIcon>
                        </div>
                    )}
                    <Combobox.Input
                        disabled={disabled}
                        placeholder={placeholder ?? ''}
                        className={classNames(
                            'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 cursor-pointer',
                            disabled ? 'disabled:cursor-not-allowed disabled:opacity-50' : '',
                        )}
                        onChange={event => {
                            if (disabled) return;
                            if (!onInputChange) {
                                setQuery(event.target.value);
                                return;
                            }
                            const search = event.target.value;
                            const isEmpty = /^\s*$/.test(search);
                            if (isEmpty) {
                                return;
                            }
                            if (multiple) {
                                if (selected?.length) {
                                    const selectedString = selected?.map((item: any) => item.name).join(',');
                                    const cleanedSearch = search.replace(selectedString, '');
                                    if (!cleanedSearch || /^\s*$/.test(cleanedSearch)) {
                                        return;
                                    }
                                    onInputChange(cleanedSearch);
                                    return;
                                }
                            }
                            onInputChange(search);
                        }}
                        displayValue={(item: any) => {
                            if (!displayValue) return null;
                            if (multiple) {
                                return null;
                            }
                            if (by) {
                                const selected = items.find(listItem => listItem[by] === item);
                                return selected ? selected[displayValue] : null;
                            }
                            return item && displayValue ? item[displayValue] : item;
                        }}
                    />
                    <div
                        className={classNames(
                            'absolute inset-y-0 right-0 flex items-center px-2 pointer-events-none',
                            disabled ? 'cursor-not-allowed' : '',
                        )}>
                        <ChevronUpDownIcon
                            className={classNames('h-5 w-5', disabled ? 'text-gray-300' : 'text-gray-400')}
                            aria-hidden='true'
                        />
                    </div>
                </Combobox.Button>

                {Boolean(filteredItems.length > 0) && (
                    <Combobox.Options className='absolute z-10 mt-3 max-h-60 w-full overflow-auto bg-white py-1 text-base shadow-lg border bg-card ring-1 ring-black ring-opacity-5 focus:outline-none sm:text-sm'>
                        {filteredItems.map((item, key) => (
                            <Combobox.Option
                                key={item?.id || key}
                                value={item}
                                className={({ active }) =>
                                    classNames(
                                        'relative cursor-default select-none py-4 pl-8 pr-4',
                                        active ? 'bg-secondary' : 'text-gray-900',
                                    )
                                }>
                                {({ active, selected }) => (
                                    <>
                                        <span className={classNames('block', selected ? 'font-semibold' : '')}>
                                            {displayValue ? item[displayValue] : item}
                                        </span>

                                        {selected && (
                                            <span
                                                className={
                                                    'absolute inset-y-0 left-0 flex items-center pl-1.5 text-primary'
                                                }>
                                                <CheckIcon className='h-5 w-5' aria-hidden='true' />
                                            </span>
                                        )}
                                    </>
                                )}
                            </Combobox.Option>
                        ))}
                    </Combobox.Options>
                )}
            </div>
            {multiple && Boolean(selected.length) && (
                <div className='flex flex-wrap mt-1'>
                    {selected.map((item: any, key: number) => (
                        <div
                            key={item?.id || key}
                            className='mr-1 mt-3 items-center border border-black border-2 center flex relative inline-block select-none whitespace-nowrap rounded-lg   align-baseline font-sans text-sm capitalize leading-none'>
                            <p className='flex-1 px-2 h-full flex justify-center items-center py-1 g border-r-2 border-black'>
                                {displayValue && item[displayValue]}
                            </p>

                            <Button
                                type={'button'}
                                variant={'ghost'}
                                size={'icon'}
                                className={'h-[30px] text-gray-800 hover:text-red-500 hover:text-red-500 bg-white'}
                                onClick={() => onChipsDelete && onChipsDelete(item)}>
                                <Trash className='w-5 h-5' />
                            </Button>
                        </div>
                    ))}
                </div>
            )}
            <div className='h-[20px]'>
                <InputError message={error}></InputError>
            </div>
        </Combobox>
    );
}
