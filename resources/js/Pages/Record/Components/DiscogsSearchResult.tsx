import React from 'react';
import { Button } from '@/Components/ui/button';
import { ExternalLink } from 'lucide-react';

interface DiscogsResultProps {
    result: {
        id?: string | number;
        title?: string;
        cover_image?: string;
        catno?: string;
        barcode?: string;
        country?: string;
        label?: string;
        uri?: string;
    };
    onSelect?: (result: any) => void;
}

export default function DiscogsSearchResult({ result, onSelect }: DiscogsResultProps) {
    return (
        <div
            className='border rounded-lg p-4 hover:bg-gray-50 cursor-pointer transition-colors'
            onClick={() => onSelect?.(result)}>
            <div className='flex gap-4'>
                {result.cover_image && (
                    <img src={result.cover_image} alt={result.title} className='w-16 h-16 object-cover rounded' />
                )}
                <div className='flex-1 relative'>
                    {result.uri && (
                        <Button
                            variant='ghost'
                            size='sm'
                            className='absolute -top-2 -right-2 h-6 w-6 p-0 bg-none hover:bg-primary hover:text-white rounded-[2px]'
                            onClick={e => {
                                e.stopPropagation();
                                window.open(result.uri, '_blank');
                            }}
                            title='Apri su Discogs'>
                            <ExternalLink className='h-4 w-4' />
                        </Button>
                    )}
                    {result.catno && <p className='text-[10px] text-gray-600 uppercase pe-6'>{result.catno}</p>}
                    <h4 className='font-medium text-gray-900 leading-none mb-1'>{result.title}</h4>
                    {result.label && <p className='text-xs text-gray-600'>{result.label}</p>}
                    {result.country && <p className='text-xs text-gray-600'>{result.country}</p>}
                </div>
            </div>
        </div>
    );
}
