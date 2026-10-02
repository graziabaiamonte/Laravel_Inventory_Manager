import Authenticated from '@/Layouts/AuthenticatedLayout';
import {
    PageProps,
    WholesaleOut,
    WholesaleOutLabelDiscount,
    Customer,
    BackOrderRecord,
    Area,
    Backorder,
} from '@/types';
import { useForm, router } from '@inertiajs/react';
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
import InputLabel from '@/Components/InputLabel';
import { Button } from '@/Components/ui/button';
import { Download } from 'lucide-react';

interface FormData {
    status: number;
    records: BackOrderRecord[];
    _method?: 'PUT';
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    [key: string]: any;
}

interface Props extends PageProps {
    backOrder: Backorder;
    areas: unknown[];
    importedRecords?: BackOrderRecord[];
    importWarnings?: string[];
}

export default function Edit({ backOrder, areas, importedRecords, importWarnings }: Props) {
    const { data, setData, post, errors, processing } = useForm<FormData>({
        status: backOrder.status || 0,
        records: backOrder.records || [],
        _method: 'PUT',
    });

    const [activeWholesalein, setActiveWholesalein] = useState<boolean>(false);
    const [isDeactivating, setIsDeactivating] = useState<boolean>(false);

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

    const inputChange = Helpers.inputChange(setData);

    const submit = () => {
        post(route('backorder.update', backOrder.id), {
            onSuccess: () => {
                // router.visit(route('wholesale-out.index'));
            },
            preserveState: page => Object.keys(page.props.errors).length > 0,
            preserveScroll: true,
        });
    };

    const handleRecordsUpdate = useCallback(
        (updatedRecords: BackOrderRecord[]) => {
            setData('records', updatedRecords);
        },
        [setData],
    );

    const handleDeactivateBackorder = (e: any) => {
        e.preventDefault();
        if (confirm('Sei sicuro di voler disattivare questo backorder? Lo stock verrà ripristinato.')) {
            setIsDeactivating(true);
            router.post(
                route('backorder.deactivate', backOrder.id),
                {},
                {
                    onSuccess: () => {
                        setIsDeactivating(false);
                        // Optionally redirect or reload
                        router.visit(route('backorder.edit', backOrder.id));
                    },
                    onError: errors => {
                        setIsDeactivating(false);
                        console.error('Error deactivating backorder:', errors);
                    },
                },
            );
        }
    };

    return (
        <Authenticated title={`Backorders cliente: ${backOrder.customer_name}`}>
            <Form submit={submit} noBtn>
                <div className='mx-auto rounded-lg mt-5 space-y-4'>
                    <div className='grid gap-4 lg:grid-cols-2 grid-cols-1'>
                        <div className='hidden'>
                            <StatusCombo
                                value={data.status}
                                error={errors.status}
                                onChange={value => setData(prev => ({ ...prev, status: value }))}
                            />
                        </div>

                        <div>
                            <InputLabel className='mb-3'>{'Scarico'}</InputLabel>
                            <Button
                                onClick={e => {
                                    e.preventDefault();
                                    router.get(route('wholesale-out.edit', backOrder.whole_sale_out_id));
                                }}>
                                {'Guarda Scarico'}
                            </Button>
                        </div>
                    </div>

                    <AttachRecordsTable
                        description='Backorders'
                        dataSource={data.records}
                        onRecordsUpdate={handleRecordsUpdate}
                        errors={errors}
                        isEditing={true}
                        importWarnings={importWarnings}
                        areas={areas as Area[]}
                        isEditingDisabled={backOrder.status === 1 ? true : false}
                    />
                </div>
                <div className='md:flex justify-between mt-5'>
                    <PrimaryButton
                        type='button'
                        className='bg-blue-500 hover:bg-blue-600 text-white mb-3 md:mb-0'
                        onClick={() => {
                            window.open(route('backorder.export', backOrder.id), '_blank');
                        }}>
                        <Download className='mr-2 w-4 h-4' /> {'Esporta Excel'}
                    </PrimaryButton>

                    {!backOrder.status ? (
                        <div className='flex'>
                            <SecondaryButton className='me-3' onClick={() => router.get(route('backorder.index'))}>
                                Annulla
                            </SecondaryButton>

                            {(data.status !== 1 || activeWholesalein === true) && (
                                <div>
                                    <PrimaryButton
                                        type='button'
                                        className={`me-5 ${activeWholesalein ? 'bg-green-500 hover:bg-green-600 focus:bg-green-500' : 'bg-black-500 hover:bg-black-600 '}`}
                                        onClick={() => {
                                            setActiveWholesalein(true);
                                            setData(prev => ({ ...prev, status: 1 }));
                                        }}
                                        disabled={data.processing}>
                                        {activeWholesalein ? 'Attivo' : 'Processa il Backorder'}
                                    </PrimaryButton>
                                </div>
                            )}

                            <PrimaryButton type='submit' disabled={processing}>
                                {processing ? 'Salvataggio...' : data.file ? 'Carica file' : 'Salva Modifiche'}
                            </PrimaryButton>
                        </div>
                    ) : (
                        <div className='flex'>
                            <PrimaryButton
                                className='bg-red-500 hover:bg-red-600 text-white mb-3 md:mb-0'
                                disabled={isDeactivating}
                                onClick={handleDeactivateBackorder}>
                                {isDeactivating ? 'Disattivazione...' : 'Disattiva Backorder'}
                            </PrimaryButton>
                        </div>
                    )}
                </div>
            </Form>
            {activeWholesalein && (
                <div className='mt-4 p-3 bg-yellow-100 border-l-4 border-yellow-400 text-yellow-700 rounded-r-lg'>
                    <p className='text-sm font-medium'>Per confermare bisogna salvare il backorder</p>
                </div>
            )}
        </Authenticated>
    );
}
