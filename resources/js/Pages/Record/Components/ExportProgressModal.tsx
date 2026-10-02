import React from 'react';
import Modal from '@/Components/atomica/Utils/Modal';
import { Button } from '@/Components/ui/button';
import { CheckCircle, XCircle, Download, Loader2 } from 'lucide-react';

interface ExportProgress {
    export_id?: string;
    status: 'idle' | 'initiated' | 'processing' | 'combining' | 'completed' | 'failed' | 'error' | 'cancelled';
    message?: string;
    chunk?: number;
    total_chunks?: number;
    total_records?: number;
    records_processed?: number;
    started_at?: string;
    updated_at?: string;
}

interface ExportProgressModalProps {
    isOpen: boolean;
    progress: ExportProgress;
    onClose: () => void;
    onDownload: () => void;
    onCancel: () => void;
    onReset: () => void;
}

export default function ExportProgressModal({
    isOpen,
    progress,
    onClose,
    onDownload,
    onCancel,
    onReset,
}: ExportProgressModalProps) {
    const getProgressPercentage = () => {
        // If completed, always show 100%
        if (progress.status === 'completed') {
            return 100;
        }

        if (progress.status === 'combining') {
            // For combining phase, use chunks processed instead of records
            if (progress.chunk && progress.total_chunks) {
                return Math.round((progress.chunk / progress.total_chunks) * 100);
            }
            return 0;
        }

        // For processing phase, use records processed
        if (progress.records_processed && progress.total_records) {
            return Math.round((progress.records_processed / progress.total_records) * 100);
        }
        if (progress.chunk && progress.total_chunks) {
            return Math.round((progress.chunk / progress.total_chunks) * 100);
        }
        return 0;
    };

    const getStatusIcon = () => {
        switch (progress.status) {
            case 'completed':
                return <CheckCircle className='h-6 w-6 text-green-500' />;
            case 'cancelled':
                return <XCircle className='h-6 w-6 text-orange-500' />;
            case 'failed':
            case 'error':
                return <XCircle className='h-6 w-6 text-red-500' />;
            case 'initiated':
            case 'processing':
            case 'combining':
                return <Loader2 className='h-6 w-6 text-blue-500 animate-spin' />;
            default:
                return null;
        }
    };

    const getStatusText = () => {
        switch (progress.status) {
            case 'initiated':
                return 'Export iniziato...';
            case 'processing':
                return `Elaborazione in corso... (${progress.chunk || 0}/${progress.total_chunks || 0})`;
            case 'combining':
                return `Creazione file Excel... (${progress.chunk || 0}/${progress.total_chunks || 0})`;
            case 'completed':
                return 'Export completato!';
            case 'cancelled':
                return 'Export annullato';
            case 'failed':
            case 'error':
                return 'Export fallito';
            default:
                return '';
        }
    };

    const handleClose = () => {
        // Only allow closing when export is in a final state
        if (['completed', 'failed', 'error', 'cancelled'].includes(progress.status)) {
            onReset();
            onClose();
        }
        // Do nothing if export is still in progress (initiated, processing, combining)
    };

    return (
        <Modal show={isOpen} onClose={handleClose} maxWidth='md' type='primary' buttons={false}>
            <div className='p-6'>
                <div className='flex items-center space-x-3 mb-4'>
                    {getStatusIcon()}
                    <h3 className='text-lg font-semibold text-gray-900'>Esporta Dischi</h3>
                </div>

                <div className='space-y-4'>
                    <div>
                        <p className='text-sm text-gray-600 mb-2'>{getStatusText()}</p>

                        {progress.message && <p className='text-sm text-gray-500'>{progress.message}</p>}

                        {progress.total_records && progress.total_records > 0 && (
                            <div className='text-sm space-y-1'>
                                {progress.status === 'combining' && (
                                    <p className='text-gray-700 font-medium'>Creazione file Excel finale...</p>
                                )}
                                {progress.status === 'completed' && (
                                    <p className='text-gray-700 font-medium'>
                                        ✅ Tutti i {progress.total_records.toLocaleString()} records sono stati
                                        esportati con successo!
                                    </p>
                                )}
                            </div>
                        )}
                    </div>

                    {(progress.status === 'processing' ||
                        progress.status === 'combining' ||
                        progress.status === 'completed') &&
                        progress.total_chunks &&
                        progress.total_chunks > 0 && (
                            <div className='space-y-3'>
                                {/* Main progress bar */}
                                <div className='space-y-2'>
                                    <div className='flex justify-between text-sm font-medium text-gray-700'>
                                        <span>
                                            {progress.status === 'completed'
                                                ? 'Export Completato'
                                                : progress.status === 'combining'
                                                  ? 'Progresso Creazione'
                                                  : 'Progresso Esportazione'}
                                        </span>
                                        <span>{getProgressPercentage()}%</span>
                                    </div>
                                    <div className='w-full bg-gray-200 rounded-full h-3'>
                                        <div
                                            className='bg-blue-600 h-3 rounded-full transition-all duration-500 ease-out'
                                            style={{ width: `${getProgressPercentage()}%` }}
                                        />
                                    </div>
                                </div>

                                {/* Chunk info */}
                                {progress.status !== 'completed' && (
                                    <div className='flex justify-between text-xs text-gray-500'>
                                        {progress.status === 'processing' && (
                                            <>
                                                <span>
                                                    Chunk {progress.chunk || 0} di {progress.total_chunks || 0}
                                                </span>
                                                {progress.records_processed && progress.total_records && (
                                                    <span>
                                                        {(
                                                            (progress.records_processed / progress.total_records) *
                                                            100
                                                        ).toFixed(1)}
                                                        % completato
                                                    </span>
                                                )}
                                            </>
                                        )}
                                        {progress.status === 'combining' && (
                                            <>
                                                <span>
                                                    Creando file Excel {progress.chunk || 0} di{' '}
                                                    {progress.total_chunks || 0}
                                                </span>
                                                <span>
                                                    {progress.chunk && progress.total_chunks
                                                        ? `${((progress.chunk / progress.total_chunks) * 100).toFixed(
                                                              1,
                                                          )}% combinato`
                                                        : 'Preparazione file Excel...'}
                                                </span>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                    <div className='flex justify-end space-x-3 pt-4'>
                        {progress.status === 'completed' && (
                            <Button onClick={onDownload} className='bg-green-600 hover:bg-green-700'>
                                <Download className='h-4 w-4 mr-2' />
                                Scarica File
                            </Button>
                        )}

                        {['initiated', 'processing', 'combining'].includes(progress.status) && (
                            <Button variant='destructive' onClick={onCancel} className='bg-red-600 hover:bg-red-700'>
                                Annulla Export
                            </Button>
                        )}

                        <Button
                            variant='outline'
                            onClick={handleClose}
                            disabled={['initiated', 'processing', 'combining'].includes(progress.status)}>
                            {['initiated', 'processing', 'combining'].includes(progress.status)
                                ? 'Elaborazione...'
                                : 'Chiudi'}
                        </Button>
                    </div>
                </div>
            </div>
        </Modal>
    );
}
