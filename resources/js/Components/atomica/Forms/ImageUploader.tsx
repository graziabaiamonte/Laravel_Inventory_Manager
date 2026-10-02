import Dropzone from '@/Components/atomica/Forms/Dropzone';
import { XMarkIcon } from '@heroicons/react/20/solid';
import { useState } from 'react';

export default function ImageUploader({
    image,
    onDelete,
    onChange,
}: {
    image?: string;
    onDelete: () => void;
    onChange: (item: File) => void;
}) {
    const [previewSource, setPreviewSource] = useState<string | undefined>(undefined);

    async function setImage(image: File) {
        const file = image;
        const reader = new FileReader();
        reader.onload = (e: ProgressEvent<FileReader>) => {
            const { result } = e.target as FileReader;
            setPreviewSource(result as string);
        };
        reader.readAsDataURL(file);
    }

    return (
        <div className='mt-3 mb-6'>
            {!image && !previewSource ? (
                <Dropzone
                    label='Immagine di copertina'
                    onChange={item => {
                        setImage(item).then(() => onChange(item));
                    }}
                />
            ) : (
                <div className='relative flex'>
                    <img
                        src={`${previewSource || image}`}
                        className='rounded-lg h-[200px] object-contain'
                        alt={'article image not loading'}
                    />
                    <div
                        onClick={() => {
                            setPreviewSource(undefined);
                            onDelete();
                        }}
                        className='cursor-pointer'>
                        <XMarkIcon className='h-5 w-5' />
                    </div>
                </div>
            )}
        </div>
    );
}
