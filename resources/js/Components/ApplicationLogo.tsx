import { HTMLAttributes } from 'react';

export default function ApplicationLogo({ className = '', ...props }: HTMLAttributes<HTMLDivElement>) {
    return (
        <div
            {...props}
            className={`flex items-center justify-center whitespace-nowrap border border-dashed border-gray-400 px-2 text-xs font-semibold uppercase tracking-wide text-gray-500 ${className}`}>
            logo cliente
        </div>
    );
}
