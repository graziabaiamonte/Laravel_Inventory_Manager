import Authenticated from '@/Layouts/AuthenticatedLayout';
import { PageProps, WholesaleOut, WholesaleOutLabelDiscount, Customer, WholesaleOutRecord, Area } from '@/types';
import { useForm, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useCallback, useEffect, useState } from 'react';
import Form from '@/Components/atomica/Forms/Form';
import Combo from '@/Components/atomica/Utils/Combo';
import Input from '@/Components/atomica/Forms/Input';
import TextAreaInput from '@/Components/atomica/Forms/TextAreaInput';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import StatusCombo from '@/Components/atomica/Utils/StatusCombo';
import Helpers from '@/Components/atomica/Utils/Helpers';
import AttachRecordsTable from './Components/AttachRecordsTable';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';
import Modal from '@/Components/atomica/Utils/Modal';

interface ActivationPreview {
    total_lines: number;
    total_requested_units: number;
    total_allocatable_units: number;
    total_backorder_units: number;
    lines_fully_allocatable: number;
    lines_partial: number;
    lines_missing: number;
    shortages: Array<{
        record_info: string;
        area_name: string;
        requested: number;
        available: number;
        shortage: number;
    }>;
}

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

import InputLabel from '@/Components/InputLabel';
import { Button } from '@/Components/ui/button';
import { Download } from 'lucide-react';

interface FormData {
    customer_id: string | number | null;
    area_id: string | number | null;
    doc_num: string;
    description: string;
    status: number;
    file: File | null;
    records: WholesaleOutRecord[];
    label_discounts?: WholesaleOutLabelDiscount[];
    _method?: 'PUT';
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    [key: string]: any;
}

interface Props extends PageProps {
    wholesaleOut: WholesaleOut;
    locations: unknown[];
    customers: Customer[];
    areas: unknown[];
    areas_warehouses: unknown[];
    importedRecords?: WholesaleOutRecord[];
    importWarnings?: string[];
    notFoundRecords?: NotFoundRecord[];
    defaultAreaId?: number;
    types_status: Array<any>;
}

export default function Edit(props: Props) {
    const {
        wholesaleOut,
        customers,
        areas_warehouses,
        importedRecords,
        importWarnings,
        notFoundRecords,
        defaultAreaId,
        types_status,
    } = props;

    // Use the server status (wholesaleOut.status) for UI warning logic
    const isWholesaleOutActive = wholesaleOut.status === 1;

    const { data, setData, errors } = useForm<FormData>({
        customer_id: wholesaleOut.customer_id || null,
        area_id: wholesaleOut.area_id || null,
        doc_num: wholesaleOut.doc_num || '',
        description: wholesaleOut.description || '',
        status: wholesaleOut.status || 0,
        file: null,
        records: wholesaleOut.records || [],
        label_discounts: wholesaleOut.label_discounts || [],
        _method: 'PUT',
    });

    const [activeWholesalein, setActiveWholesalein] = useState<boolean>(false);
    const [activationPreview, setActivationPreview] = useState<ActivationPreview | null>(null);
    const [isLoadingPreview, setIsLoadingPreview] = useState<boolean>(false);
    const [previewError, setPreviewError] = useState<string | null>(null);

    const requestActivationPreview = useCallback(async () => {
        setIsLoadingPreview(true);
        setPreviewError(null);
        setActivationPreview(null);

        try {
            const payload = {
                area_id: data.area_id,
                records: (data.records || []).map(r => ({
                    record_id: r.record_id,
                    quantity: r.quantity,
                    area_quantities: (r.area_quantities || []).filter(aq => aq.quantity && aq.quantity > 0),
                })),
            };

            const { data: preview } = await axios.post<ActivationPreview>(
                route('wholesale-out.activation-preview'),
                payload,
            );
            setActivationPreview(preview);
        } catch (err) {
            console.error('Activation preview error:', err);
            setPreviewError('Impossibile calcolare l’anteprima delle giacenze. Puoi procedere comunque.');
        } finally {
            setIsLoadingPreview(false);
        }
    }, [data.area_id, data.records]);

    const confirmActivation = () => {
        setActivationPreview(null);
        setPreviewError(null);
        setActiveWholesalein(true);
        setData(prev => ({ ...prev, status: 1 }));
    };

    const cancelActivation = () => {
        setActivationPreview(null);
        setPreviewError(null);
    };

    // Handle imported records from file upload (similar to Create component)
    useEffect(() => {
        // Only replace records if importedRecords exists and has content
        // This should only happen immediately after a file upload redirect
        if (importedRecords && importedRecords.length > 0) {
            // Replace existing records with imported ones (same as Create behavior)
            setData((prev: FormData) => ({
                ...prev,
                records: importedRecords,
                file: null, // Clear the file input after processing
            }));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [importedRecords]); // Intentionally excluding data.records and setData to avoid infinite loops

    const handleBulkDiscountUpdate = useCallback(
        (labelDiscounts: WholesaleOutLabelDiscount[]) => {
            setData('label_discounts', labelDiscounts);
        },
        [setData],
    );

    const inputChange = Helpers.inputChange(setData);

    const [isSubmitting, setIsSubmitting] = useState(false);

    const submit = () => {
        // Filter out area assignments with quantity=0 before submission
        // These are created by reconciliation to preserve user intent during backorders,
        // but shouldn't be sent back to the server in new requests
        const cleanedData = {
            ...data,
            _method: 'PUT',
            records: data.records.map(record => ({
                ...record,
                area_quantities: (record.area_quantities || []).filter(aq => aq.quantity && aq.quantity > 0),
            })),
        };

        setIsSubmitting(true);

        // Use router.post directly with cleaned data to bypass form state
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        router.post(route('wholesale-out.update', wholesaleOut.id), cleanedData as any, {
            onError: () => {
                setIsSubmitting(false);
            },
            onSuccess: () => {
                setIsSubmitting(false);
                // router.visit(route('wholesale-out.index'));
            },
            onFinish: () => {
                setIsSubmitting(false);
            },
            preserveState: (page: { props: { errors: Record<string, string> } }) =>
                Object.keys(page.props.errors).length > 0,
            preserveScroll: true,
        });
    };

    const handleRecordsUpdate = useCallback(
        (updatedRecords: WholesaleOutRecord[]) => {
            setData('records', updatedRecords);
        },
        [setData],
    );

    const handleFileSelect = useCallback(
        (file: File) => {
            setData((prev: FormData) => ({
                ...prev,
                file: file,
                records: [], // Clear records when file is selected to trigger file upload validation mode
            }));
        },
        [setData],
    );

    // Use unified hook for unsaved changes detection and warnings
    useUnsavedChanges({
        formData: data,
        initialData: wholesaleOut,
        editableFields: ['customer_id', 'area_id', 'doc_num', 'description', 'status'],
        recordsConfig: {
            field: 'records',
            editableFields: ['record_id', 'quantity', 'unit_price', 'discount', 'vat', 'total_price', 'area_id'],
            numericFields: ['unit_price', 'vat', 'total_price'],
        },
    });

    return (
        <Authenticated title={`Modifica Vendita Ingrosso: ${data.doc_num}`}>
            <Form submit={submit} noBtn>
                {/* Warning banner for active WholesaleOuts */}
                {isWholesaleOutActive && (
                    <div className='bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6'>
                        <div className='flex items-center'>
                            <div className='flex-shrink-0'>
                                <svg className='h-5 w-5 text-yellow-400' viewBox='0 0 20 20' fill='currentColor'>
                                    <path
                                        fillRule='evenodd'
                                        d='M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z'
                                        clipRule='evenodd'
                                    />
                                </svg>
                            </div>
                            <div className='ml-3'>
                                <h3 className='text-sm font-medium text-yellow-800'>
                                    Attenzione: Stai modificando uno Scarico Attivo
                                </h3>
                                <div className='mt-2 text-sm text-yellow-700'>
                                    <p>Questo scarico è già attivo (elaborato). Le modifiche comporteranno:</p>
                                    <ul className='list-disc pl-5 mt-1'>
                                        <li>Riconciliazione automatica delle giacenze</li>
                                        <li>Aggiornamento dei backorder esistenti</li>
                                        <li>Possibili avvisi per stock insufficienti</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
                <div className='mx-auto rounded-lg mt-5 space-y-4'>
                    <div className='grid gap-4 lg:grid-cols-2 grid-cols-1'>
                        <Combo
                            items={customers}
                            selected={customers.find(c => c.id === data.customer_id)}
                            error={errors.customer_id}
                            label='Cliente'
                            displayValue='name'
                            onChange={customer => {
                                setData('customer_id', customer?.id || null);
                            }}
                        />
                        <Combo
                            items={areas_warehouses ?? []}
                            selected={(areas_warehouses as Area[]).find(a => a.id === data.area_id)}
                            error={errors.area_id}
                            label='Area di Scarico'
                            displayValue='name'
                            onChange={area => {
                                setData('area_id', area?.id || null);
                            }}
                        />
                        <Input
                            id='doc_num'
                            error={errors.doc_num}
                            value={data.doc_num}
                            onChange={inputChange}
                            label='Numero Documento'
                            // doc_num is always editable
                        />

                        {/* Only show status combo if WholesaleOut is not yet active (status !== 1) */}
                        {!isWholesaleOutActive && (
                            <div className='hidden'>
                                <StatusCombo
                                    value={data.status}
                                    error={errors.status}
                                    onChange={value => setData(prev => ({ ...prev, status: value }))}
                                />
                            </div>
                        )}
                        {/* Show read-only status indicator when active */}
                        {isWholesaleOutActive && (
                            <div className='space-y-1'>
                                <label className='block text-sm font-medium text-gray-700'>Stato</label>
                                <div
                                    className='px-3 py-2 bg-green-50 border border-green-200 rounded-md text-green-800 font-medium'
                                    style={{ marginTop: '11px' }}>
                                    Attivo
                                </div>
                            </div>
                        )}
                        <TextAreaInput
                            id='description'
                            error={errors.description}
                            value={data.description}
                            onChange={inputChange}
                            label='Descrizione'
                            className='lg:col-span-2'
                            // description is always editable
                        />
                        {wholesaleOut.backorders && wholesaleOut.backorders.length > 0 && (
                            <div>
                                <InputLabel className='mb-3'>{'Backorder'}</InputLabel>
                                <Button
                                    onClick={e => {
                                        e.preventDefault();
                                        router.get(
                                            route('backorder.index', { filter: { wholesale_out_id: wholesaleOut.id } }),
                                        );
                                    }}>
                                    {'Guarda Backorders'}
                                </Button>
                            </div>
                        )}
                    </div>

                    <AttachRecordsTable
                        description='Record Venduti'
                        dataSource={data.records}
                        onRecordsUpdate={handleRecordsUpdate}
                        onFileSelect={handleFileSelect}
                        onBulkDiscountUpdate={handleBulkDiscountUpdate}
                        existingLabelDiscounts={data.label_discounts}
                        errors={errors}
                        isEditing={true}
                        isEditingDisabled={false} // Allow editing for all WholesaleOuts
                        hideFileUpload={isWholesaleOutActive} // Hide file upload for active WholesaleOuts
                        importWarnings={importWarnings}
                        areas={(areas_warehouses as Area[]) ?? []}
                        defaultAreaId={defaultAreaId}
                        notFoundRecords={notFoundRecords}
                        types_status={types_status}
                    />
                </div>
                <div className='md:flex justify-between mt-5'>
                    <PrimaryButton
                        type='button'
                        className='bg-blue-500 hover:bg-blue-600 text-white mb-3 md:mb-0'
                        onClick={() => {
                            window.open(route('wholesale-out.export', wholesaleOut.id), '_blank');
                        }}>
                        <Download className='mr-2 w-4 h-4' /> {'Esporta Excel'}
                    </PrimaryButton>

                    <div className='flex'>
                        <SecondaryButton className='me-3' onClick={() => router.get(route('wholesale-out.index'))}>
                            Annulla
                        </SecondaryButton>

                        {(data.status !== 1 || activeWholesalein === true) && (
                            <div>
                                <PrimaryButton
                                    type='button'
                                    className={`me-5 ${activeWholesalein ? 'bg-green-500 hover:bg-green-600 focus:bg-green-500' : 'bg-black-500 hover:bg-black-600 '}`}
                                    onClick={() => {
                                        if (activeWholesalein) return;
                                        requestActivationPreview();
                                    }}
                                    disabled={isSubmitting || isLoadingPreview}>
                                    {activeWholesalein
                                        ? 'Attivo'
                                        : isLoadingPreview
                                          ? 'Controllo giacenze…'
                                          : 'Processa lo Scarico'}
                                </PrimaryButton>
                            </div>
                        )}

                        {/* Always show submit button, but with different text for active WholesaleOuts */}
                        <PrimaryButton type='submit' disabled={isSubmitting}>
                            {isSubmitting
                                ? 'Salvataggio...'
                                : isWholesaleOutActive
                                  ? 'Aggiorna Scarico Attivo'
                                  : data.file
                                    ? 'Carica file'
                                    : 'Aggiorna Scarico'}
                        </PrimaryButton>
                    </div>
                </div>
            </Form>
            {activeWholesalein && (
                <div className='mt-4 p-3 bg-yellow-100 border-l-4 border-yellow-400 text-yellow-700 rounded-r-lg'>
                    <p className='text-sm font-medium'>Per confermare bisogna aggiornare lo scarico</p>
                </div>
            )}

            <Modal
                type='primary'
                show={activationPreview !== null || previewError !== null}
                onClose={cancelActivation}
                onConfirm={confirmActivation}
                maxWidth='2xl'
                confirmText={
                    activationPreview && activationPreview.total_backorder_units > 0
                        ? 'Processa comunque'
                        : 'Processa lo Scarico'
                }
                cancelText='Annulla'>
                <div className='w-full'>
                    <h3 className='text-lg font-semibold text-gray-900 mb-3'>Anteprima Attivazione</h3>

                    {previewError && (
                        <div className='mb-4 p-3 bg-red-50 border-l-4 border-red-400 text-red-700 rounded-r-md text-sm'>
                            {previewError}
                        </div>
                    )}

                    {activationPreview && (
                        <>
                            <div className='grid grid-cols-2 gap-3 text-sm mb-4'>
                                <div className='p-3 bg-gray-50 rounded'>
                                    <div className='text-gray-500'>Righe totali</div>
                                    <div className='text-lg font-semibold'>{activationPreview.total_lines}</div>
                                </div>
                                <div className='p-3 bg-gray-50 rounded'>
                                    <div className='text-gray-500'>Unità richieste</div>
                                    <div className='text-lg font-semibold'>
                                        {activationPreview.total_requested_units}
                                    </div>
                                </div>
                                <div className='p-3 bg-green-50 rounded'>
                                    <div className='text-green-700'>Allocabili subito</div>
                                    <div className='text-lg font-semibold text-green-800'>
                                        {activationPreview.total_allocatable_units} unità (
                                        {activationPreview.lines_fully_allocatable} righe complete)
                                    </div>
                                </div>
                                <div
                                    className={`p-3 rounded ${activationPreview.total_backorder_units > 0 ? 'bg-yellow-50' : 'bg-gray-50'}`}>
                                    <div
                                        className={
                                            activationPreview.total_backorder_units > 0
                                                ? 'text-yellow-800'
                                                : 'text-gray-500'
                                        }>
                                        Andranno in backorder
                                    </div>
                                    <div
                                        className={`text-lg font-semibold ${activationPreview.total_backorder_units > 0 ? 'text-yellow-900' : ''}`}>
                                        {activationPreview.total_backorder_units} unità (
                                        {activationPreview.lines_partial + activationPreview.lines_missing} righe)
                                    </div>
                                </div>
                            </div>

                            {activationPreview.total_backorder_units > 0 && (
                                <div className='mb-3 p-3 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 rounded-r-md text-sm'>
                                    <strong>Attenzione:</strong> attivando ora,{' '}
                                    {activationPreview.total_backorder_units} unità verranno registrate come backorder e
                                    non decrementeranno lo stock. Verifica che lo stock nell’area selezionata sia stato
                                    caricato/consolidato.
                                </div>
                            )}

                            {activationPreview.shortages.length > 0 && (
                                <div className='mt-2 border rounded-md max-h-64 overflow-y-auto'>
                                    <table className='w-full text-sm'>
                                        <thead className='bg-gray-100 sticky top-0'>
                                            <tr>
                                                <th className='text-left px-2 py-1'>Record</th>
                                                <th className='text-left px-2 py-1'>Area</th>
                                                <th className='text-right px-2 py-1'>Richiesto</th>
                                                <th className='text-right px-2 py-1'>Disp.</th>
                                                <th className='text-right px-2 py-1'>Mancante</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {activationPreview.shortages.map((s, i) => (
                                                <tr key={i} className='border-t'>
                                                    <td className='px-2 py-1'>{s.record_info}</td>
                                                    <td className='px-2 py-1'>{s.area_name}</td>
                                                    <td className='px-2 py-1 text-right'>{s.requested}</td>
                                                    <td className='px-2 py-1 text-right'>{s.available}</td>
                                                    <td className='px-2 py-1 text-right font-semibold text-yellow-800'>
                                                        {s.shortage}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </Modal>
        </Authenticated>
    );
}
