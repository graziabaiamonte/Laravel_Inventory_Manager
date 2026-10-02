import { usePage } from '@inertiajs/react';
import { PageProps, EnumShape } from '@/types';
import Combo from './Combo';

type StatusComboProps = {
    value: number;
    onChange: (value: number) => void;
    error?: string;
    label?: string;
};

export default function StatusCombo({ value, onChange, error, label = 'Stato' }: StatusComboProps) {
    const { activeStates } = usePage<PageProps>().props;

    return (
        <Combo
            items={activeStates}
            error={error}
            selected={activeStates.find((state: EnumShape) => Number(state.value) === value)}
            label={label}
            displayValue='description'
            onChange={(status: EnumShape) => onChange(Number(status.value))}
        />
    );
}
