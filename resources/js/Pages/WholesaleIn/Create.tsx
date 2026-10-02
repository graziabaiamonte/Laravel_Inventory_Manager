import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useForm, router } from '@inertiajs/react';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import Combo from '@/Components/atomica/Utils/Combo';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import TextAreaInput from '@/Components/atomica/Forms/TextAreaInput';
import { useState, useCallback, useEffect } from 'react';
import AttachRecordsTable from './Components/AttachRecordsTable';
import { WholesaleInRecord } from '@/types';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

interface FormData {
    supplier_id: string;
    area_id: string;
    doc_num: string;
    description: string;
    status: number;
    file: File | null;
    records: WholesaleInRecord[];
    [key: string]: any; // Add index signature for FormDataType constraint
}

interface Props {
    importedRecords?: Array<WholesaleInRecord>;
    suppliers: Array<any>;
    areas: Array<any>;
    areas_warehouses?: Array<any>;
    defaultArea?: any;
    types_status?: Array<any>;
}

export default function Create({
    importedRecords,
    suppliers,
    areas,
    areas_warehouses,
    defaultArea,
    types_status,
}: Props) {
    const form = useForm<FormData>({
        supplier_id: '',
        area_id: defaultArea?.id || '',
        doc_num: '',
        description: '',
        status: 0,
        file: null,
        records: [], // Initialize records as empty array initially
    });

    useEffect(() => {
        if (importedRecords && importedRecords.length > 0) {
            if (form.data.records.length === 0) {
                form.setData(prev => ({
                    ...prev,
                    records: importedRecords,
                    file: null, // Clear the file when records are imported successfully
                }));
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [importedRecords]); // Only watch importedRecords to avoid infinite loops

    const inputChange = Helpers.inputChange(form.setData);
    const submitHelper = Helpers.submitForm(form)('wholesale-in');

    const submit = (e: React.FormEvent) => {
        submitHelper(e);
    };

    const handleRecordsUpdate = useCallback(
        (updatedRecords: WholesaleInRecord[]) => {
            form.setData('records', updatedRecords);
        },
        [form],
    );

    const handleFileSelect = (file: File) => {
        form.setData('file', file);
    };

    const { hasUnsavedChanges, setHasUnsavedChanges } = useUnsavedChanges({
        formData: form.data,
        initialData: {
            supplier_id: '',
            area_id: defaultArea?.id || '',
            doc_num: '',
            description: '',
            status: 0,
            file: null,
            records: [],
        },
        editableFields: ['supplier_id', 'area_id', 'doc_num', 'description'],
        recordsConfig: {
            field: 'records',
            editableFields: ['record_id', 'quantity', 'unit_price', 'total_price'],
            numericFields: ['unit_price', 'total_price'],
        },
    });

    // Reset unsaved changes on successful form submission
    useEffect(() => {
        if (form.wasSuccessful) {
            setHasUnsavedChanges(false);
        }
    }, [form.wasSuccessful, setHasUnsavedChanges]);

    return (
        <Authenticated title='Nuovo carico'>
            <Form submit={submit} noBtn={true}>
                <div className='mx-auto rounded-lg mt-5'>
                    <div className='grid gap-3 lg:grid-cols-2 grid-cols-1'>
                        <Combo
                            items={suppliers}
                            error={form.errors.supplier_id}
                            label={'Fornitore'}
                            displayValue={'name'}
                            onChange={supplier => {
                                form.setData(prev => ({
                                    ...prev,
                                    supplier_id: supplier?.id || null,
                                }));
                            }}
                        />
                        <Combo
                            items={areas_warehouses ?? []}
                            error={form.errors.area_id}
                            label={'Area di carico'}
                            displayValue={'name'}
                            selected={areas_warehouses?.find(a => a.id === form.data.area_id) || null}
                            onChange={area => {
                                form.setData(prev => ({
                                    ...prev,
                                    area_id: area?.id || null,
                                }));
                            }}
                        />
                        <Input
                            error={form.errors.doc_num}
                            value={form.data.doc_num}
                            onChange={inputChange}
                            label='Numero Documento'
                            id='doc_num'
                        />
                        <TextAreaInput
                            error={form.errors.description}
                            value={form.data.description}
                            onChange={inputChange}
                            label='Descrizione'
                            id='description'
                        />
                    </div>
                    <AttachRecordsTable
                        description='Dischi'
                        onRecordsUpdate={handleRecordsUpdate}
                        onFileSelect={handleFileSelect}
                        importedRecords={importedRecords}
                        errors={form.errors}
                        areas={areas_warehouses ?? []}
                        types_status={types_status}
                    />
                </div>
                <div className='flex justify-end mt-5'>
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('wholesale-in.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Carica'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
