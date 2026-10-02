import { InputHTMLAttributes } from 'react';

export default function Checkbox({ className = '', ...props }: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            type='checkbox'
            className={'rounded border-gray-300 text-black shadow-sm focus:ring-black ' + className}
        />
    );
}
