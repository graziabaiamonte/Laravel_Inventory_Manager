import React, { forwardRef, useEffect, useImperativeHandle, useRef, InputHTMLAttributes } from 'react';
import InputError from '@/Components/InputError';
import { twMerge } from 'tailwind-merge';
import { Input as ShadInput } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
export default forwardRef(function Input(
    {
        type = 'text',
        className = '',
        isFocused = false,
        label = '',
        error = '',
        children,
        required = false,
        autoComplete = 'off',
        ...props
    }: InputHTMLAttributes<HTMLInputElement> & {
        isFocused?: boolean;
        label?: string;
        error?: string;
        children?: React.ReactNode;
        required?: boolean;
    },
    ref,
) {
    const localRef = useRef<HTMLInputElement>(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, []);

    return (
        <div className=''>
            {label && (
                <Label className='block text-sm font-medium text-gray-900'>
                    {label} {required && '*'}
                </Label>
            )}
            <div className='mt-2 flex relative rounded-md shadow-sm ring-1 ring-inset ring-gray-300 focus-within:ring-2 focus-within:ring-inset focus-within:ring-indigo-600'>
                <ShadInput
                    {...props}
                    type={type}
                    autoComplete={autoComplete}
                    className={twMerge('', className)}
                    ref={localRef}
                />
                {children && children}
            </div>
            <div className='h-[20px]'>
                <InputError message={error} />
            </div>
        </div>
    );
});
