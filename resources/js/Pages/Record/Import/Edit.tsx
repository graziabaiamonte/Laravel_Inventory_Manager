import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useForm, router } from '@inertiajs/react';
import Form from '@/Components/atomica/Forms/Form';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { RecordImport, ImportRecordItem } from '@/types';
import Combo from '@/Components/atomica/Utils/Combo';
import ImportRecordsTable, { getInitialTableData } from '../Components/ImportRecordsTable';
import { TableData, TableHeaderType } from '@/Components/atomica/AtomicaTable';
import { useState, useCallback, useEffect } from 'react';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';
import DiscogsZeroPriceModal, { ZeroPriceRecord } from '@/Components/records/DiscogsZeroPriceModal';
import { isZeroPrice } from '@/lib/utils';

export default function Edit({
    record_import,
    draft_status,
    records,
    coverStatus,
    diskStatus,
}: {
    record_import: RecordImport;
    draft_status: Array<any>;
    records: Array<any>;
    coverStatus: Array<any>;
    diskStatus: Array<any>;
}) {
    //const form = useForm(record_import);

    const form = useForm({
        ...record_import,
        records: records ?? [],
    });

    const [activeRecordImport, setActiveRecordImport] = useState<boolean>(false);
    const [zeroPriceRecords, setZeroPriceRecords] = useState<ZeroPriceRecord[]>([]);

    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('records-import', record_import.id);

    const [tableData, setTableData] = useState<TableData>(() => {
        // If we have importedRecords, populate the table data
        if (records?.length) {
            return {
                ...getInitialTableData(),
                data: records,
            };
        }
        return getInitialTableData();
    });

    const handleRecordsUpdate = useCallback(
        (updatedRecords: ImportRecordItem[]) => {
            //console.log('Updating records in parent (Edit):', updatedRecords);
            form.setData('records', updatedRecords);
        },
        [form.setData],
    );

    // Don't allow file uploads in edit mode - records are already imported
    const handleFileSelect = useCallback(() => {
        // Do nothing - file upload not allowed in edit mode
    }, []);

    const activate = () => {
        setActiveRecordImport(true);
        form.setData(prev => ({ ...prev, draft: Boolean(0) }));
    };

    // Completing the import publishes on Discogs every record flagged for sale: the ones with a
    // retail price of 0 have to be confirmed first
    const handleActivation = () => {
        const affected: ZeroPriceRecord[] = (form.data.records || [])
            .filter(
                (record: ImportRecordItem) =>
                    Number(record.for_sale_on_discogs) === 1 &&
                    isZeroPrice(record.retail_price) &&
                    !record.d_delete &&
                    !record.delete &&
                    !record.soft_delete,
            )
            .map((record: ImportRecordItem) => ({
                identifier: record.barcode || record.cat_number,
                artist: record.artist?.name || record.artist_name,
                title: record.title,
                url: record.record_id ? route('record.edit', record.record_id) : null,
            }));

        if (affected.length > 0) {
            setZeroPriceRecords(affected);
            return;
        }

        activate();
    };

    useUnsavedChanges({
        formData: form.data,
        initialData: {
            ...record_import,
            records: records ?? [],
        },
        editableFields: ['draft'],
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
        <Authenticated title={'Pubblica Importazione Record'}>
            <Form submit={submit} noBtn={true}>
                <div className='mx-auto rounded-lg my-5'>
                    <div className='grid gap-3 lg:grid-cols-2 grid-cols-1'>
                        {!activeRecordImport && (
                            <div className='hidden'>
                                <Combo
                                    items={draft_status}
                                    error={form.errors.draft}
                                    label={'Stato record importati'}
                                    displayValue={'description'}
                                    selected={draft_status.find(ds => ds.value === form.data.draft) || draft_status[0]}
                                    onChange={ds => {
                                        form.setData(prev => ({ ...prev, draft: ds.value }));
                                    }}
                                />
                            </div>
                        )}
                    </div>
                </div>

                {records?.length > 0 && (
                    <ImportRecordsTable
                        data={tableData}
                        description='Dischi Importati'
                        importedRecords={records}
                        onRecordsUpdate={handleRecordsUpdate}
                        onFileSelect={handleFileSelect}
                        errors={form.errors}
                        isEditing={!record_import.draft} // Enable editing when in draft mode (isEditing=false means editable)
                        diskStatus={diskStatus}
                        coverStatus={coverStatus}
                        importId={record_import.id} // Pass the import ID
                    />
                )}

                {!record_import.draft && (
                    <div
                        className='px-3 py-2 bg-green-50 border border-green-200 rounded-md text-green-800 font-medium'
                        style={{ marginTop: '11px' }}>
                        Record Importati con successo il: {record_import.updated_at}
                    </div>
                )}

                <div className='flex justify-end mt-5'>
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('records-import.index'))}>
                        {'Annulla'}
                    </SecondaryButton>

                    {(form.data.draft == true || activeRecordImport === true) && (
                        <div>
                            <PrimaryButton
                                type='button'
                                className={`me-5 ${!form.data.draft ? 'bg-green-500 hover:bg-green-600 focus:bg-green-500' : 'bg-black-500 hover:bg-black-600 '}`}
                                onClick={handleActivation}>
                                {activeRecordImport ? "Record pronti per l'import" : "Completa l'import dei record"}
                            </PrimaryButton>
                        </div>
                    )}

                    <PrimaryButton type={'submit'} disabled={!record_import.draft}>
                        {'Aggiorna'}
                    </PrimaryButton>
                </div>
            </Form>
            {activeRecordImport && (
                <div className='mt-4 p-3 bg-yellow-100 border-l-4 border-yellow-400 text-yellow-700 rounded-r-lg'>
                    <p className='text-sm font-medium'>Per confermare bisogna aggiornare l&apos;importazione</p>
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
                confirmText='Conferma e continua'
                // The import decides the Discogs sale on its own: every record with a Release ID is
                // put on sale, even one currently set to "Non in vendita". Spelled out here so the
                // list does not look wrong for those records.
                message={
                    <>
                        <strong>Attenzione:</strong> l&apos;importazione mette in vendita su Discogs tutti i record con
                        un Release ID, anche quelli che ora risultano <em>Non in vendita</em>.{' '}
                        {zeroPriceRecords.length === 1
                            ? 'Questo record risulterà in vendita'
                            : `Questi ${zeroPriceRecords.length} record risulteranno in vendita`}{' '}
                        con prezzo al dettaglio a 0.
                    </>
                }
            />
        </Authenticated>
    );
}
