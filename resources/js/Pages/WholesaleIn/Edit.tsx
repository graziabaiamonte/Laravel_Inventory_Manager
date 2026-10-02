import { WholesaleIn, WholesaleInRecord } from '@/types';
import { useCallback, useEffect, useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import Helpers from '@/Components/atomica/Utils/Helpers';
import Authenticated from '@/Layouts/AuthenticatedLayout';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import Combo from '@/Components/atomica/Utils/Combo';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import TextAreaInput from '@/Components/atomica/Forms/TextAreaInput';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';
import AttachRecordsTable from './Components/AttachRecordsTable';
import { Download, Printer } from 'lucide-react';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';
import Modal from '@/Components/atomica/Utils/Modal';
import DiscogsZeroPriceModal, { ZeroPriceRecord } from '@/Components/records/DiscogsZeroPriceModal';
import { isZeroPrice } from '@/lib/utils';

interface FormData {
    id: number;
    supplier_id: number | string | null;
    area_id: number | string | null;
    doc_num: string;
    description: string;
    status: number;
    file: File | null;
    supplier?: { id: number; name: string };
    area?: { id: number; name: string };
    records: WholesaleInRecord[];
    [key: string]: any;
}

interface Props {
    wholesaleIn: WholesaleIn;
    suppliers: Array<any>;
    areas: Array<any>;
    areas_warehouses?: Array<any>;
    importedRecords?: Array<WholesaleInRecord>;
    types_status?: Array<any>;
}

export default function Edit({
    wholesaleIn,
    suppliers,
    areas,
    areas_warehouses,
    importedRecords,
    types_status,
}: Props) {
    const form = useForm<FormData>({
        ...wholesaleIn,
        records: wholesaleIn.records ?? [],
        description: wholesaleIn.description ?? '',
        file: null,
        supplier_id: wholesaleIn.supplier?.id ?? null,
        area_id: wholesaleIn.area?.id ?? null,
        _method: 'PATCH',
    });

    const [activeWholesalein, setActiveWholesalein] = useState<boolean>(false);
    const [showDeactivateModal, setShowDeactivateModal] = useState<boolean>(false);
    const [zeroPriceRecords, setZeroPriceRecords] = useState<ZeroPriceRecord[]>([]);

    // Determine if fields should be disabled (when WholesaleIn is active)
    const isActive = wholesaleIn.status === 1;

    // Memoize the records to pass to AttachRecordsTable to prevent infinite loops
    const recordsToDisplay = importedRecords || form.data.records;

    // Handle imported records from file upload (similar to Create component)
    useEffect(() => {
        // Only replace records if importedRecords exists and has content
        // This should only happen immediately after a file upload redirect
        if (importedRecords && importedRecords.length > 0) {
            // Replace existing records with imported ones (same as Create behavior)
            form.setData((prev: any) => ({
                ...prev,
                records: importedRecords,
                file: null, // Clear the file input after processing
            }));
        }
    }, [importedRecords]);
    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('wholesale-in', wholesaleIn.id);

    const handleRecordsUpdate = useCallback(
        (updatedRecords: WholesaleInRecord[]) => {
            //console.log('Updating records in parent (Edit):', updatedRecords);
            form.setData('records', updatedRecords);
        },
        [form.setData],
    );

    const activate = () => {
        setActiveWholesalein(true);
        form.setData(prev => ({ ...prev, status: 1 }));
    };

    // Activating the Carico publishes on Discogs every record flagged for sale: the ones with a
    // retail price of 0 have to be confirmed first
    const handleActivation = () => {
        const affected: ZeroPriceRecord[] = (form.data.records || [])
            .filter(record => {
                const forSale = Boolean(record.parent_record?.for_sale_on_discogs || record.for_sale_on_discogs);

                return forSale && isZeroPrice(record.retail_price);
            })
            .map(record => ({
                identifier: record.barcode || record.cat_number || record.parent_record?.rr_uid,
                artist: record.parent_record?.artist?.name || record.artist,
                title: record.title || record.parent_record?.title,
                url: record.record_id ? route('record.edit', record.record_id) : null,
            }));

        if (affected.length > 0) {
            setZeroPriceRecords(affected);
            return;
        }

        activate();
    };

    const handleFileSelect = (file: File) => {
        //console.log('File selected in edit mode:', file.name);
        form.setData((prev: any) => ({
            ...prev,
            file: file,
            records: [], // Clear records when file is selected to trigger file upload validation mode
        }));
    };

    const { hasUnsavedChanges, setHasUnsavedChanges } = useUnsavedChanges({
        formData: form.data,
        initialData: wholesaleIn,
        editableFields: ['supplier_id', 'area_id', 'doc_num', 'description', 'status'],
        recordsConfig: {
            field: 'records',
            editableFields: [
                'record_id',
                'quantity',
                'unit_price',
                'total_price',
                'discount',
                'vat',
                'area_quantities',
            ],
            numericFields: ['unit_price', 'total_price', 'vat'],
        },
    });

    // Reset unsaved changes on successful form submission
    useEffect(() => {
        if (form.wasSuccessful) {
            setHasUnsavedChanges(false);
        }
    }, [form.wasSuccessful, setHasUnsavedChanges]);

    return (
        <Authenticated title={`Modifica Carico: ${wholesaleIn.doc_num}`}>
            {isActive && (
                <div className='flex justify-end gap-1'>
                    <SecondaryButton
                        className='-translate-y-full mt-1'
                        onClick={() => {
                            window.open(route('wholesale-in.barcodes', wholesaleIn.id), '_blank');
                        }}>
                        <Printer className='h-4 w-4' />
                        <span className='hidden sm:block ms-2'>Stampa Barcode</span>
                    </SecondaryButton>
                </div>
            )}
            <Form submit={submit} noBtn={true}>
                <div className='mx-auto rounded-lg mt-5 space-y-5'>
                    <div className='grid gap-3 lg:grid-cols-2 grid-cols-1'>
                        <Combo
                            items={suppliers}
                            error={form.errors.supplier_id}
                            label={'Fornitore'}
                            displayValue={'name'}
                            selected={form.data.supplier}
                            disabled={isActive}
                            onChange={supplier => {
                                form.setData((prev: any) => ({
                                    ...prev,
                                    supplier_id: supplier?.id ?? null,
                                    supplier: supplier,
                                }));
                            }}
                        />
                        <Combo
                            items={areas_warehouses ?? []}
                            error={form.errors.area_id}
                            label={'Area di carico'}
                            displayValue={'name'}
                            selected={form.data.area}
                            disabled={isActive}
                            onChange={area => {
                                form.setData((prev: any) => ({
                                    ...prev,
                                    area_id: area?.id ?? null,
                                    area: area,
                                }));
                            }}
                        />
                        <Input
                            error={form.errors.doc_num}
                            value={String(form.data.doc_num)}
                            onChange={inputChange}
                            label='Numero Documento'
                            id='doc_num'
                            disabled={isActive}
                        />
                        <div className='hidden'>
                            <StatusCombo
                                value={form.data.status}
                                error={form.errors.status}
                                onChange={value => form.setData(prev => ({ ...prev, status: value }))}
                            />
                        </div>

                        <TextAreaInput
                            error={form.errors.description}
                            value={String(form.data.description)}
                            onChange={inputChange}
                            label='Descrizione'
                            id='description'
                            className='lg:col-span-2'
                            disabled={isActive}
                        />
                    </div>

                    <AttachRecordsTable
                        description='Dischi Caricati'
                        importedRecords={recordsToDisplay}
                        onRecordsUpdate={handleRecordsUpdate}
                        onFileSelect={handleFileSelect}
                        errors={form.errors}
                        isEditing={true}
                        disabled={isActive}
                        areas={areas_warehouses ?? []}
                        types_status={types_status}
                    />
                </div>

                <div className='md:flex justify-between mt-5'>
                    <PrimaryButton
                        type='button'
                        className='bg-blue-500 hover:bg-blue-600 text-white mb-3 md:mb-0'
                        onClick={() => {
                            window.open(route('wholesale-in.export', wholesaleIn.id), '_blank');
                        }}>
                        <Download className='mr-2 w-4 h-4' /> {'Esporta Excel'}
                    </PrimaryButton>

                    <div className='flex'>
                        <SecondaryButton className='me-5' onClick={() => router.visit(route('wholesale-in.index'))}>
                            {'Annulla'}
                        </SecondaryButton>

                        {/* Show appropriate buttons based on current and desired status */}
                        {wholesaleIn.status === 1 ? (
                            // WholesaleIn is currently active - show deactivate button
                            <PrimaryButton
                                type='button'
                                className='me-5 bg-red-500 hover:bg-red-600 focus:bg-red-500'
                                onClick={() => setShowDeactivateModal(true)}
                                disabled={form.processing}>
                                {'Disattiva Carico'}
                            </PrimaryButton>
                        ) : (
                            // WholesaleIn is inactive - show activate button and update button
                            <>
                                <PrimaryButton
                                    type='button'
                                    className={`me-5 ${activeWholesalein ? 'bg-green-500 hover:bg-green-600 focus:bg-green-500' : 'bg-black-500 hover:bg-black-600 '}`}
                                    onClick={handleActivation}
                                    disabled={form.processing}>
                                    {activeWholesalein ? 'Attivo' : 'Processa il Carico'}
                                </PrimaryButton>

                                <PrimaryButton type={'submit'} disabled={form.processing}>
                                    {form.processing ? 'Aggiornamento...' : 'Aggiorna Carico'}
                                </PrimaryButton>
                            </>
                        )}
                    </div>
                </div>
            </Form>
            {activeWholesalein && (
                <div className='mt-4 p-3 bg-yellow-100 border-l-4 border-yellow-400 text-yellow-700 rounded-r-lg'>
                    <p className='text-sm font-medium'>Per confermare bisogna aggiornare il carico</p>
                </div>
            )}

            <DiscogsZeroPriceModal
                show={zeroPriceRecords.length > 0}
                records={zeroPriceRecords}
                onClose={() => setZeroPriceRecords([])}
                onConfirm={() => {
                    setZeroPriceRecords([]);
                    activate();
                }}
                confirmText='Conferma e processa'
            />

            <Modal
                type='danger'
                onClose={() => setShowDeactivateModal(false)}
                onConfirm={() => {
                    // Use router.patch directly with the exact data we want to send
                    // This bypasses the form state and sends the request immediately
                    const deactivationData = { ...form.data, status: 0 };
                    router.patch(route('wholesale-in.update', wholesaleIn.id), deactivationData as never, {
                        preserveScroll: true,
                        onStart: () => form.setData('status', 0), // Update form state for UI
                        onSuccess: () => setShowDeactivateModal(false),
                    });
                }}
                show={showDeactivateModal}
                confirmText='Disattiva'
                cancelText='Annulla'>
                <div className='p-6'>
                    <h3 className='text-lg font-medium text-gray-900'>Conferma Disattivazione</h3>
                    <p className='mt-2 text-sm text-gray-600'>
                        La disattivazione comporterà la rimozione delle quantità da ogni stock. <br />
                        Adattare le giacienze se necessario, altrimenti la disattivazione verrà bloccata per non mandare
                        gli stock in negativo.
                    </p>
                </div>
            </Modal>
        </Authenticated>
    );
}
