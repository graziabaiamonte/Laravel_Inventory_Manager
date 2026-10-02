import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useEffect } from 'react';
import { useForm, router } from '@inertiajs/react';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { Customer } from '@/types';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

export default function Edit({ customer }: { customer: Customer }) {
    const form = useForm(customer);
    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('customer', customer.id);

    const { hasUnsavedChanges, setHasUnsavedChanges } = useUnsavedChanges({
        formData: form.data,
        initialData: customer,
    });

    // Reset unsaved changes on successful form submission
    useEffect(() => {
        if (form.wasSuccessful) {
            setHasUnsavedChanges(false);
        }
    }, [form.wasSuccessful, setHasUnsavedChanges]);

    return (
        <Authenticated title='Modifica Cliente'>
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
                        <Input
                            error={form.errors.last_name}
                            value={form.data.last_name || ''}
                            onChange={inputChange}
                            label='Cognome'
                            id='last_name'
                        />
                        <Input
                            error={form.errors.address}
                            value={form.data.address}
                            onChange={inputChange}
                            label='Indirizzo'
                            id='address'
                        />
                        <Input
                            error={form.errors.phone}
                            value={form.data.phone || ''}
                            onChange={inputChange}
                            label='Telefono'
                            id='phone'
                        />
                        <Input
                            error={form.errors.email}
                            value={form.data.email}
                            onChange={inputChange}
                            label='Email'
                            id='email'
                            type='email'
                        />
                        <StatusCombo
                            value={form.data.status}
                            error={form.errors.status}
                            onChange={value => form.setData(prev => ({ ...prev, status: value }))}
                        />
                    </div>
                </div>
                <div className='flex justify-end'>
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('customer.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Aggiorna'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
