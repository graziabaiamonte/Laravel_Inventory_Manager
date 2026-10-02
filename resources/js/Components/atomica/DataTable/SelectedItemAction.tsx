import PrimaryButton from '@/Components/PrimaryButton';
import DangerButton from '@/Components/DangerButton';
import React from 'react';
import { TableActions } from '@/Components/atomica/AtomicaTable';

export default function SelectedItemAction({ action, selected }: { action: TableActions; selected: Array<any> }) {
    return !action?.danger ? (
        <PrimaryButton onClick={action.action}>{`${action.label} ${selected.length}`}</PrimaryButton>
    ) : (
        <DangerButton onClick={action.action}>{`${action.label} ${selected.length}`}</DangerButton>
    );
}
