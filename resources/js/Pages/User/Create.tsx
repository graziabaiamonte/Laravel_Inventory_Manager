import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useEffect } from 'react';
import Input from '@/Components/atomica/Forms/Input';
import Form from '@/Components/atomica/Forms/Form';
import { useForm, router } from '@inertiajs/react';
import Combo from '@/Components/atomica/Utils/Combo';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

export default function Create({ roles, locations }: { roles: Array<any>; locations: Array<any> }) {
    const form = useForm({
        name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        status: 0,
        role: '',
        default_location_id: null,
        locations: [],
    });
    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('user');

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
        <Authenticated title='Crea Utente'>
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
                            value={form.data.last_name}
                            onChange={inputChange}
                            label='Cognome'
                            id='last_name'
                        />
                        <Input
                            error={form.errors.email}
                            value={form.data.email}
                            onChange={inputChange}
                            label='Email'
                            type='email'
                            id='email'
                        />
                        <Combo
                            items={roles}
                            error={form.errors.role}
                            label={'Ruolo'}
                            displayValue={'description'}
                            onChange={role => {
                                form.setData(prev => ({ ...prev, role: role.value }));
                            }}
                        />
                        <Input
                            error={form.errors.password}
                            value={form.data.password}
                            onChange={inputChange}
                            label='Password'
                            type='Password'
                            id='password'
                        />
                        <Input
                            error={form.errors.password_confirmation}
                            value={form.data.password_confirmation}
                            onChange={inputChange}
                            label='Conferma password'
                            type='password'
                            id='password_confirmation'
                        />
                        <Combo
                            items={locations}
                            error={form.errors.default_location_id}
                            label={'Location predefinita'}
                            displayValue={'name'}
                            onClear={() => {
                                form.setData(prev => ({
                                    ...prev,
                                    default_location_id: null,
                                }));
                            }}
                            selected={
                                form.data.default_location_id
                                    ? locations.find(s => s.id === form.data.default_location_id)
                                    : null
                            }
                            onChange={location => {
                                form.setData(prev => ({
                                    ...prev,
                                    default_location_id: location?.id || null,
                                }));
                            }}
                        />
                        <Combo
                            items={locations}
                            error={form.errors.locations}
                            multiple={true}
                            label={'Locations associate'}
                            selected={form.data.locations}
                            displayValue={'name'}
                            onChipsDelete={e => {
                                const filteredStores = form.data.locations.filter(
                                    (location: any) => location.id !== e.id,
                                );
                                form.setData(prev => ({ ...prev, locations: filteredStores }));
                            }}
                            onChange={(location: any) => {
                                if (!form.data.locations.find((item: any) => item.id === location.id)) {
                                    form.setData((prev: any) => ({
                                        ...prev,
                                        locations: [...prev.locations, location],
                                    }));
                                }
                            }}
                        />
                        <StatusCombo
                            value={form.data.status}
                            error={form.errors.status}
                            onChange={value => form.setData(prev => ({ ...prev, status: value }))}
                        />
                    </div>
                </div>
                <div className='flex justify-end'>
                    <SecondaryButton className='me-5 text-capitalize' onClick={() => router.visit(route('user.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Crea'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
