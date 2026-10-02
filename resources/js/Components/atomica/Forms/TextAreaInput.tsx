import { TextareaHTMLAttributes } from 'react';
import { Textarea as ShadTextarea } from '@/Components/ui/textarea';
import InputError from '@/Components/InputError';
import { Label } from '@/Components/ui/label';

interface TextAreaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
    error?: string;
    label?: string;
}

export default function TextAreaInput({ error, label, className = '', value, ...props }: TextAreaProps) {
    return (
        <div>
            {label && <Label className='block text-sm font-medium text-gray-900'>{label}</Label>}
            <div className='mt-2'>
                <ShadTextarea
                    className={`min-h-[80px] shadow-sm ${className}`}
                    value={value || ''} // Convert null to empty string
                    {...props}
                />
            </div>
            <div className='h-[20px]'>
                <InputError message={error} />
            </div>
        </div>
    );
}
