import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useEffect } from 'react';
import Input from '@/Components/atomica/Forms/Input';
import Form from '@/Components/atomica/Forms/Form';
import { useForm, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

export default function Create() {
    const form = useForm({
        name: '',
        status: 0,
    });
    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('format');

    const { hasUnsavedChanges, setHasUnsavedChanges } = useUnsavedChanges({
        formData: form.data,
    });

    // Reset unsaved changes on successful form submission
    useEffect(() => {
        if (form.wasSuccessful) {
            setHasUnsavedChanges(false);
        }
    }, [form.wasSuccessful, setHasUnsavedChanges]);

    return (
        <Authenticated title='Crea Formato'>
            <Form submit={submit} noBtn={true}>
                <div className='mx-auto rounded-lg mt-5'>
                    <div className='grid gap-3 lg:grid-cols-2 grid-cols-1'>
                        <Input
                            error={form.errors.name}
                            value={form.data.name}
                            onChange={inputChange}
                            label='Nome'
                            id='name'
                        />
                        <StatusCombo
                            value={form.data.status}
                            error={form.errors.status}
                            onChange={value => form.setData(prev => ({ ...prev, status: value }))}
                        />
                    </div>
                </div>
                <div className='flex justify-end'>
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('format.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Crea'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
