import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useForm, router } from '@inertiajs/react';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import Combo from '@/Components/atomica/Utils/Combo';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import CurrencyInput from '@/Components/atomica/Forms/CurrencyInput';
import TextAreaInput from '@/Components/atomica/Forms/TextAreaInput';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import AtomicaTable from '@/Components/atomica/AtomicaTable';
import { PaginationData } from '@/Components/atomica/DataTable/Pagination';
import { useState, useCallback, useEffect } from 'react';
import AttachRecordsTable, { getInitialTableData } from '../Components/ImportRecordsTable';
import { Record, ImportRecordItem } from '@/types';
import ImportRecordsTable from '../Components/ImportRecordsTable';
import Modal from '@/Components/atomica/Utils/Modal';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';

interface FormData {
    records: ImportRecordItem[];
    [key: string]: any; // Add index signature for FormDataType constraint
}

interface Props {
    importedRecords?: Array<ImportRecordItem>;
    coverStatus: Array<any>;
    diskStatus: Array<any>;
    recordsToDelete?: Array<{
        record: any;
        actions: string[];
        identifier: string;
    }>;
    showDeleteAlert?: boolean;
}

export default function ImportRecord({
    importedRecords,
    coverStatus,
    diskStatus,
    recordsToDelete,
    showDeleteAlert,
}: Props) {
    const [tableData, setTableData] = useState<TableData>(() => {
        // If we have importedRecords, populate the table data
        if (importedRecords?.length) {
            return {
                ...getInitialTableData(),
                data: importedRecords,
            };
        }
        return getInitialTableData();
    });

    // Nuovo state per la modal
    const [showDeleteModal, setShowDeleteModal] = useState(false);
    const [deleteModalContent, setDeleteModalContent] = useState('');

    const form = useForm<FormData>({
        records: [],
    });

    const submit = Helpers.submitForm(form)('records-import');

    const handleRecordsUpdate = useCallback((updatedRecords: ImportRecordItem[]) => {
        //console.log('Updating records in parent:', updatedRecords);
        form.setData('records', updatedRecords);
    }, []); // Keep empty deps array

    // Add file handler
    const handleFileSelect = (file: File) => {
        form.setData('file', file);
    };

    // Modifica l'useEffect per usare la modal invece di window.confirm
    useEffect(() => {
        if (showDeleteAlert && recordsToDelete?.length) {
            const actionNames = {
                delete: 'eliminazione definitiva',
                soft_delete: 'eliminazione soft',
                d_delete: 'rimozione da Discogs',
            };

            const deleteDetails = recordsToDelete
                .map(item => {
                    const actions = item.actions
                        .map(action => actionNames[action as keyof typeof actionNames])
                        .join(', ');
                    return `• ${item.identifier}: ${actions}`;
                })
                .join('\n');

            setDeleteModalContent(`I seguenti record esistenti verranno modificati/eliminati:\n\n${deleteDetails}`);
            setShowDeleteModal(true);
        }
    }, [showDeleteAlert, recordsToDelete]);

    // Handler per confermare l'eliminazione
    const handleConfirmDelete = () => {
        if (recordsToDelete) {
            form.setData({
                ...form.data,
                confirm_delete: true,
                records_to_delete: recordsToDelete.map(item => ({
                    record_id: item.record.id,
                    actions: item.actions,
                })),
            });
        }
        setShowDeleteModal(false);
    };

    // Handler per annullare l'eliminazione
    const handleCancelDelete = () => {
        setShowDeleteModal(false);
        router.visit(route('records-import.create'));
    };

    useUnsavedChanges({
        formData: form.data,
        initialData: {
            records: [],
        },
        recordsConfig: {
            field: 'records',
            editableFields: [
                'soft_delete',
                'delete',
                'd_delete',
                'record_id',
                'cat_number',
                'barcode',
                'artist',
                'title',
                'release_id',
                'format',
                'label',
                'purchase_price',
                'wholesale_price',
                'retail_price',
                'condition_disk',
                'condition_cover',
                'stocks_tmp',
                'comments',
                'description',
            ],
            numericFields: ['purchase_price', 'wholesale_price', 'retail_price', 'record_id', 'release_id'],
        },
    });

    return (
        <Authenticated title='Nuova importazione Record'>
            <Form submit={submit} noBtn={true}>
                <div className='mx-auto rounded-lg mt-5'>
                    <ImportRecordsTable
                        data={tableData}
                        description='Dischi'
                        onRecordsUpdate={handleRecordsUpdate}
                        onFileSelect={handleFileSelect}
                        importedRecords={importedRecords}
                        errors={form.errors}
                        diskStatus={diskStatus}
                        coverStatus={coverStatus}
                    />
                </div>
                <div className='flex justify-end mt-5'>
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('record.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Carica'}</PrimaryButton>
                </div>
            </Form>

            <Modal
                show={showDeleteModal}
                type='danger'
                onClose={handleCancelDelete}
                onConfirm={handleConfirmDelete}
                cancelText='Annulla'
                confirmText='Continua'
                maxWidth='lg'>
                <div className='text-center'>
                    <h3 className='text-lg font-medium text-gray-900 mb-4'>ATTENZIONE! Eliminazione Record</h3>
                    <div className='text-left bg-yellow-50 border border-yellow-200 rounded-md p-4 mb-4'>
                        <pre className='whitespace-pre-wrap text-sm text-gray-700 font-mono'>{deleteModalContent}</pre>
                    </div>
                    <p className='text-sm text-gray-600'>Vuoi continuare con l'operazione?</p>
                </div>
            </Modal>
        </Authenticated>
    );
}
