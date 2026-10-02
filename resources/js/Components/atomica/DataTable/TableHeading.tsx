import React from 'react';
import { Button } from '@/Components/ui/button';

export default function TableHeading({
    action,
    description,
    title,
    actionText,
}: {
    title?: string;
    description?: string | number;
    action?: () => void;
    actionText?: string;
}) {
    return (
        <div className='sm:flex sm:items-center'>
            <div className='sm:flex-auto'>
                {title && <h1 className='text-base font-semibold leading-6 text-gray-900'>{title}</h1>}
                {description && <p className='mt-2 text-sm text-gray-700'>{description}</p>}
            </div>
            <div className='mt-4 sm:ml-16 sm:mt-0 sm:flex-none'>
                {action && (
                    <Button type='button' onClick={action}>
                        {actionText ?? 'Crea Nuovo record'}
                    </Button>
                )}
            </div>
        </div>
    );
}
