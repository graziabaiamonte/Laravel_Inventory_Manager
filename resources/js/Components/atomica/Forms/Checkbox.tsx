import { Checkbox as Cb } from '@/Components/ui/checkbox';
import InputLabel from '@/Components/InputLabel';

export default function Checkbox({
    className = '',
    label = '',
    onCheckedChange,
    ...props
}: {
    className?: string;
    label?: string;
    onCheckedChange: (e: boolean) => void;
    checked?: boolean;
}) {
    return (
        <div className='flex gap-2'>
            <Cb
                {...props}
                onCheckedChange={onCheckedChange}
                className={'rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-primary ' + className}
            />
            <InputLabel
                htmlFor='terms1'
                className='text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70'>
                {label}
            </InputLabel>
        </div>
    );
}
