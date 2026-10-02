import * as React from 'react';

interface ProgressProps {
    value: number;
    className?: string;
}

export function Progress({ value, className = '' }: ProgressProps) {
    const percentage = Math.min(Math.max(value, 0), 100);

    return (
        <div className={`w-full bg-gray-200 rounded-full h-2.5 ${className}`}>
            <div
                className='bg-blue-600 h-2.5 rounded-full transition-all duration-300 ease-in-out'
                style={{ width: `${percentage}%` }}
            />
        </div>
    );
}
