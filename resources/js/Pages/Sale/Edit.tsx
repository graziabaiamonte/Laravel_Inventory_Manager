import { Sale, SaleRecord, Location } from '@/types';
import { useForm, router } from '@inertiajs/react';
import Authenticated from '@/Layouts/AuthenticatedLayout';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import Combo from '@/Components/atomica/Utils/Combo';
import DateInput from '@/Components/atomica/Forms/DateInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import CurrencyInput from '@/Components/atomica/Forms/CurrencyInput';
import TextAreaInput from '@/Components/atomica/Forms/TextAreaInput';
import SaleRecordTable from './Components/SaleRecordTable';
import { useCallback, useEffect } from 'react';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

export default function Edit({
    sale,
    locations,
    types,
    sale_records,
    types_status,
}: {
    sale: Sale;
    locations: Array<Location>;
    types: Array<any>;
    sale_records: Array<SaleRecord>;
    types_status: Array<any>;
}) {
    const form = useForm({
        ...sale,
        records: sale_records ?? [],
        remote_customer_id: sale.remote_customer_id ?? '',
        remote_customer_name: sale.remote_customer_name ?? '',
        description: sale.description ?? '',
    });

    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('sale', sale.id);

    const handleRecordsUpdate = useCallback(
        (updatedRecords: SaleRecord[]) => {
            const totalAmount = updatedRecords.reduce((sum, record) => {
                const price = record.total_price;
                const numericPrice = typeof price === 'number' ? price : parseFloat(String(price || 0));
                return sum + (isNaN(numericPrice) ? 0 : numericPrice);
            }, 0);

            form.setData(prev => ({
                ...prev,
                amount: totalAmount,
            }));

            form.setData('records', updatedRecords);
        },
        [form.setData],
    );

    const { hasUnsavedChanges, setHasUnsavedChanges } = useUnsavedChanges({
        formData: form.data,
        initialData: {
            ...sale,
            records: sale_records ?? [],
        },
        editableFields: ['location_id', 'type', 'remote_customer_id', 'remote_customer_name', 'description', 'date'],
        recordsConfig: {
            field: 'records',
            editableFields: ['record_id', 'quantity', 'price', 'discount', 'vat', 'total_price'],
            numericFields: ['price', 'vat', 'total_price'],
        },
    });

    // Reset unsaved changes on successful form submission
    useEffect(() => {
        if (form.wasSuccessful) {
            setHasUnsavedChanges(false);
        }
    }, [form.wasSuccessful, setHasUnsavedChanges]);

    return (
        <Authenticated title='Modifica Vendita'>
            <Form submit={submit} noBtn={true}>
                <div className='mx-auto rounded-lg mt-5'>
                    <div className='grid gap-3 lg:grid-cols-2 grid-cols-1'>
                        <Combo
                            items={locations}
                            error={form.errors.location_id}
                            label={'Location'}
                            displayValue={'name'}
                            selected={locations.find(l => l.id === form.data.location_id)}
                            onChange={location => {
                                form.setData(prev => ({
                                    ...prev,
                                    location_id: location?.id || null,
                                }));
                            }}
                        />
                        {String(form.data.type) === '1' && (
                            <>
                                <Input
                                    error={form.errors.remote_customer_id}
                                    value={form.data.remote_customer_id}
                                    onChange={inputChange}
                                    label='ID Cliente'
                                    id='remote_customer_id'
                                />
                                <Input
                                    error={form.errors.remote_customer_name}
                                    value={form.data.remote_customer_name}
                                    onChange={inputChange}
                                    label='Nome Cliente'
                                    id='remote_customer_name'
                                />
                            </>
                        )}
                        <Combo
                            items={types}
                            error={form.errors.type}
                            label={'Tipo'}
                            displayValue={'description'}
                            selected={types.find(t => t.value === form.data.type)}
                            onChange={type => {
                                form.setData(prev => ({
                                    ...prev,
                                    type: type?.value !== undefined ? type.value : null,
                                }));
                            }}
                        />
                        <CurrencyInput
                            value={form.data.amount}
                            error={form.errors.amount}
                            onChange={inputChange}
                            name='amount'
                            id='amount'
                            label='Importo'
                            disabled={true}
                        />
                        <DateInput
                            label='Data'
                            error={form.errors.date}
                            initialDate={sale.date}
                            onDateChange={date => {
                                form.setData(prev => ({
                                    ...prev,
                                    date: date,
                                }));
                            }}
                        />
                        <TextAreaInput
                            error={form.errors.description}
                            value={form.data.description}
                            onChange={inputChange}
                            label='Descrizione'
                            id='description'
                        />
                    </div>

                    <SaleRecordTable
                        sale_records={sale_records}
                        description='Dischi Caricati'
                        onRecordsUpdate={handleRecordsUpdate}
                        errors={form.errors}
                        isEditing={true}
                        selectedLocationId={form.data.location_id}
                        types_status={types_status}
                    />
                </div>

                <div className='flex justify-end mt-5'>
                    <SecondaryButton
                        className='me-5'
                        onClick={() =>
                            router.visit(
                                route('sale.index', {
                                    filter: { date: { startDate: new Date(), endDate: new Date() } },
                                }),
                            )
                        }>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Aggiorna'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
