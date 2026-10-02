import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useEffect } from 'react';
import { useForm, router } from '@inertiajs/react';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { Location } from '@/types';
import Combo from '@/Components/atomica/Utils/Combo';
import Checkbox from '@/Components/atomica/Forms/Checkbox';
import { Label } from '@/Components/ui/label';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

export default function Edit({ location, types, areas }: { location: Location; types: Array<any>; areas: Array<any> }) {
    const form = useForm(location);
    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('location', location.id);

    const { hasUnsavedChanges, setHasUnsavedChanges } = useUnsavedChanges({
        formData: form.data,
        initialData: location,
    });

    // Reset unsaved changes on successful form submission
    useEffect(() => {
        if (form.wasSuccessful) {
            setHasUnsavedChanges(false);
        }
    }, [form.wasSuccessful, setHasUnsavedChanges]);

    return (
        <Authenticated title='Modifica Location'>
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
                        <Combo
                            items={types}
                            error={form.errors.type}
                            label={'Tipologia'}
                            displayValue={'description'}
                            selected={types.find(t => t.value === form.data.type)}
                            onChange={type => {
                                if (type) {
                                    form.setData(prev => ({
                                        ...prev,
                                        type: type.value,
                                    }));
                                }
                                if (type != 'warehouse') {
                                    form.setData(prev => ({
                                        ...prev,
                                        default_wholesaleout_location: false,
                                    }));
                                }
                            }}
                        />
                        <StatusCombo
                            value={form.data.status}
                            error={form.errors.status}
                            onChange={value => form.setData(prev => ({ ...prev, status: value }))}
                        />
                        {form.data.type === 'warehouse' ? (
                            <div>
                                <Label className='block text-sm font-medium leading-6 text-gray-900 mb-2'>
                                    {'Location Predefinita'}
                                </Label>
                                <Checkbox
                                    checked={form.data.default_wholesaleout_location}
                                    onCheckedChange={checked =>
                                        form.setData(prev => ({ ...prev, default_wholesaleout_location: checked }))
                                    }
                                    label='Sì'
                                />
                            </div>
                        ) : (
                            <div></div>
                        )}
                        <Combo
                            items={areas}
                            error={form.errors.default_area_id}
                            label={'Area predefinita'}
                            displayValue={'name'}
                            onClear={() => {
                                form.setData(prev => ({
                                    ...prev,
                                    default_area_id: null,
                                }));
                            }}
                            selected={
                                form.data.default_area_id ? areas.find(l => l.id === form.data.default_area_id) : null
                            }
                            onChange={area => {
                                form.setData(prev => ({
                                    ...prev,
                                    default_area_id: area?.id || null,
                                }));
                            }}
                        />
                        <Combo
                            items={areas}
                            error={form.errors.areas}
                            multiple={true}
                            label={'Aree'}
                            selected={form.data.areas || []}
                            displayValue={'name'}
                            onChipsDelete={e => {
                                const filtered = form.data.areas?.filter((loc: any) => loc.id !== e.id);
                                form.setData(prev => ({ ...prev, areas: filtered }));
                            }}
                            onChange={(e: any) => {
                                if (!form.data.areas?.find((item: any) => item.id === e.id))
                                    form.setData((prev: any) => ({
                                        ...prev,
                                        areas: [...prev.areas, e],
                                    }));
                            }}
                        />
                    </div>
                </div>
                <div className='flex justify-end'>
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('location.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Aggiorna'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
