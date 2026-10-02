import Modal from '@/Components/atomica/Utils/Modal';

export interface ZeroPriceRecord {
    identifier?: string | null;
    artist?: string | null;
    title?: string | null;
    url?: string | null;
}

/**
 * Asks the user to confirm before putting records on sale on Discogs with a retail price of 0.
 * For mass actions the affected records are listed; for a single record the list is left empty.
 */
export default function DiscogsZeroPriceModal({
    show,
    records = [],
    onConfirm,
    onClose,
    confirmText = 'Conferma e metti in vendita',
    message,
}: {
    show: boolean;
    records?: ZeroPriceRecord[];
    onConfirm: () => void;
    onClose: () => void;
    confirmText?: string;
    /** Overrides the default warning, for flows that need to explain how the sale is decided */
    message?: React.ReactNode;
}) {
    return (
        <Modal
            type='primary'
            show={show}
            onClose={onClose}
            onConfirm={onConfirm}
            maxWidth='2xl'
            confirmText={confirmText}
            cancelText='Annulla'>
            <div className='w-full'>
                <h3 className='text-lg font-semibold text-gray-900 mb-3'>Prezzo al dettaglio a 0</h3>

                <div className='p-3 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 rounded-r-md text-sm'>
                    {message ??
                        (records.length > 0 ? (
                            <>
                                <strong>Attenzione:</strong> {records.length}{' '}
                                {records.length === 1 ? 'record verrà messo' : 'record verranno messi'} in vendita su
                                Discogs con prezzo al dettaglio a 0.
                            </>
                        ) : (
                            <>
                                <strong>Attenzione:</strong> il record verrà messo in vendita su Discogs con prezzo al
                                dettaglio a 0.
                            </>
                        ))}
                </div>

                {records.length > 0 && (
                    <div className='mt-3 border rounded-md max-h-64 overflow-y-auto'>
                        <table className='w-full text-sm'>
                            <thead className='bg-gray-100 sticky top-0'>
                                <tr>
                                    <th className='text-left px-2 py-1'>Barcode / Cat. #</th>
                                    <th className='text-left px-2 py-1'>Artista</th>
                                    <th className='text-left px-2 py-1'>Titolo</th>
                                </tr>
                            </thead>
                            <tbody>
                                {records.map((record, index) => (
                                    <tr key={index} className='border-t'>
                                        <td className='px-2 py-1'>
                                            {record.url ? (
                                                <a
                                                    href={record.url}
                                                    target='_blank'
                                                    rel='noreferrer'
                                                    className='text-blue-600 underline'>
                                                    {record.identifier || '—'}
                                                </a>
                                            ) : (
                                                record.identifier || '—'
                                            )}
                                        </td>
                                        <td className='px-2 py-1'>{record.artist || '—'}</td>
                                        <td className='px-2 py-1'>{record.title || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </Modal>
    );
}
