import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useEffect } from 'react';
import { useForm, router } from '@inertiajs/react';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { Artist } from '@/types';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

export default function Edit({ artist }: { artist: Artist }) {
    const form = useForm(artist);
    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('artist', artist.id);

    const { hasUnsavedChanges, setHasUnsavedChanges } = useUnsavedChanges({
        formData: form.data,
        initialData: artist,
    });

    // Reset unsaved changes on successful form submission
    useEffect(() => {
        if (form.wasSuccessful) {
            setHasUnsavedChanges(false);
        }
    }, [form.wasSuccessful, setHasUnsavedChanges]);

    return (
        <Authenticated title='Modifica Artista'>
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
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('artist.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Aggiorna'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
