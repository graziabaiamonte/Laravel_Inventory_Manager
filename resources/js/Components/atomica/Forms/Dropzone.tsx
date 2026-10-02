import { ChangeEvent, DragEvent, useState } from 'react';

export default function Dropzone({
    onChange,
    label,
    error = '',
    id = 'dropzone-file',
}: {
    onChange: (file: any) => void;
    label: string;
    error?: string;
    id?: string;
}) {
    const [isDragging, setIsDragging] = useState(false);

    const handleDragEnter = (e: DragEvent<HTMLLabelElement>) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = () => {
        setIsDragging(false);
    };

    const handleDrop = (e: DragEvent<HTMLLabelElement>) => {
        e.preventDefault();
        setIsDragging(false);

        const file = e.dataTransfer.files[0];
        if (file) {
            onChange(file);
        }
    };

    const handleFileInputChange = (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            onChange(file);
        }
    };

    return (
        <div
            className={`flex items-center justify-center w-full ${error ? 'border-red-500' : 'border-slate-400'} h-full max-h-full rounded-lg border-[2.5px] border-dashed transition-all`}>
            <label
                htmlFor={id}
                className={`${isDragging && 'bg-gray-200'} group flex flex-col items-center justify-center w-full h-64  rounded-lg cursor-pointer`}
                onDragEnter={handleDragEnter}
                onDragOver={e => {
                    e.preventDefault();
                }}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}>
                <div className='flex flex-col items-center justify-center pt-5 pb-6'>
                    <svg
                        aria-hidden='true'
                        className='w-10 group-hover:text-primary transition-all h-10 mb-3 text-gray-400'
                        fill='none'
                        stroke='currentColor'
                        viewBox='0 0 24 24'
                        xmlns='http://www.w3.org/2000/svg'>
                        <path
                            strokeLinecap='round'
                            strokeLinejoin='round'
                            strokeWidth={2}
                            d='M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12'
                        />
                    </svg>
                    <p className='mb-2 text-lg tracking-wider text-gray-500 '>
                        {error ? (
                            <span className='text-red-500'>{error}</span>
                        ) : (
                            <span className='font-semibold'>{label || 'Clicca o trascina il file.'}</span>
                        )}
                    </p>
                </div>
                <input onChange={handleFileInputChange} id={id} type='file' className='hidden' />
            </label>
        </div>
    );
}
