import { PropsWithChildren } from 'react';
import InputLabel from '@/Components/InputLabel';

export default function FormSection({
    children,
    title,
    className = '',
}: PropsWithChildren<{ title?: string; className?: string }>) {
    return (
        <div className={className}>
            {title && <InputLabel className='mb-6 text-lg font-bold'>{title}</InputLabel>}
            <div className='grid gap-4 lg:grid-cols-2 grid-cols-1 py-3 border-b border-b-slate-200 mb-4'>
                {children}
            </div>
        </div>
    );
}
