import Authenticated from '@/Layouts/AuthenticatedLayout';
import { PageProps, WholesaleOutRecord, WholesaleOutLabelDiscount, Customer, Area } from '@/types';
import { useForm, router } from '@inertiajs/react';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import Combo from '@/Components/atomica/Utils/Combo';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import TextAreaInput from '@/Components/atomica/Forms/TextAreaInput';
import { useCallback, useEffect } from 'react';
import AttachRecordsTable from './Components/AttachRecordsTable';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

interface NotFoundRecord {
    row_number: number;
    cat_number: string;
    barcode: string;
    artist: string;
    title: string;
    format: string;
    label: string;
    quantity: number;
    price: string;
}

interface FormData {
    customer_id: string | number | null;
    area_id: string | number | null;
    doc_num: string;
    description: string;
    status: number;
    file: File | null; // For XLSX import
    pdf_file: File | null; // For PDF document
    records: WholesaleOutRecord[];
    label_discounts?: WholesaleOutLabelDiscount[];
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    [key: string]: any; // Add index signature
}

interface Props extends PageProps {
    importedRecords?: WholesaleOutRecord[];
    locations: unknown[];
    customers: Customer[];
    areas: unknown[];
    areas_warehouses: unknown[];
    importWarnings?: string[];
    notFoundRecords?: NotFoundRecord[];
    defaultAreaId?: number;
    defaultArea?: any;
    types_status: Array<any>;
}

export default function Create(props: Props) {
    const {
        importedRecords,
        customers,
        areas_warehouses,
        importWarnings,
        notFoundRecords,
        defaultAreaId,
        defaultArea,
        types_status,
    } = props;

    // Prioritize user's default warehouse area over system default
    const initialAreaId = defaultArea?.id || defaultAreaId;

    const form = useForm<FormData>({
        customer_id: null,
        area_id: initialAreaId as number | null,
        doc_num: '',
        description: '',
        status: 0, // Default to inactive
        file: null,
        pdf_file: null,
        records: [],
        label_discounts: [],
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

    const handleBulkDiscountUpdate = useCallback(
        (labelDiscounts: WholesaleOutLabelDiscount[]) => {
            form.setData('label_discounts', labelDiscounts);
        },
        [form],
    );

    const inputChange = Helpers.inputChange(form.setData);

    const submit = () => {
        form.post(route('wholesale-out.store'), {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const handleRecordsUpdate = useCallback(
        (updatedRecords: WholesaleOutRecord[]) => {
            form.setData('records', updatedRecords);
        },
        [form],
    );

    const handleFileSelect = useCallback(
        (selectedFile: File) => {
            form.setData('file', selectedFile);
        },
        [form],
    );

    // Use unified hook for unsaved changes detection and warnings
    // For create forms, we track if user has entered any data
    useUnsavedChanges({
        formData: form.data,
        initialData: {
            customer_id: null,
            area_id: initialAreaId as number | null,
            doc_num: '',
            description: '',
            status: 0,
            records: [],
            label_discounts: [],
        },
        editableFields: ['customer_id', 'area_id', 'doc_num', 'description'],
        recordsConfig: {
            field: 'records',
            editableFields: ['record_id', 'quantity', 'unit_price', 'discount', 'vat', 'total_price', 'area_id'],
            numericFields: ['unit_price', 'vat', 'total_price'],
        },
    });

    return (
        <Authenticated title='Nuova Vendita Ingrosso'>
            <Form submit={submit} noBtn>
                <div className='mx-auto rounded-lg mt-5 space-y-4'>
                    <div className='grid gap-4 lg:grid-cols-2 grid-cols-1'>
                        <Combo
                            items={customers}
                            error={form.errors.customer_id}
                            label='Cliente'
                            displayValue='name'
                            onChange={customer => form.setData('customer_id', customer?.id || null)}
                        />
                        <Combo
                            items={areas_warehouses ?? []}
                            error={form.errors.area_id}
                            label='Area di Scarico'
                            selected={(areas_warehouses as Area[]).find(a => a.id == form.data.area_id)}
                            displayValue='name'
                            onChange={area => form.setData('area_id', area?.id || null)}
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
                            className='lg:col-span-2'
                        />
                    </div>

                    <AttachRecordsTable
                        description='Record da Vendere'
                        dataSource={form.data.records}
                        onRecordsUpdate={handleRecordsUpdate} // Use the callback
                        onFileSelect={handleFileSelect} // Use the callback
                        onBulkDiscountUpdate={handleBulkDiscountUpdate} // Use the callback
                        errors={form.errors}
                        importWarnings={importWarnings}
                        areas={(areas_warehouses as Area[]) ?? []}
                        defaultAreaId={defaultAreaId}
                        notFoundRecords={notFoundRecords}
                        types_status={types_status}
                    />
                </div>
                <div className='flex justify-end mt-6'>
                    <SecondaryButton className='me-3' onClick={() => router.get(route('wholesale-out.index'))}>
                        Annulla
                    </SecondaryButton>
                    <PrimaryButton
                        type='submit'
                        disabled={form.processing || (form.data.records.length === 0 && !form.data.file)}>
                        {form.processing ? 'Salvataggio...' : form.data.file ? 'Carica file' : 'Salva Vendita'}
                    </PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
