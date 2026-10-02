import { useState, useEffect, useRef } from 'react';
import axios from 'axios';
import { set } from 'lodash';

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

type FilterValue = string | number | boolean | null | undefined;
type Filters = Record<string, FilterValue>;

interface UseRecordExportReturn {
    progress: ExportProgress;
    isExporting: boolean;
    startExport: (filters: Filters, isWholesaleExport?: boolean, isUppercase?: boolean) => Promise<void>;
    cancelExport: () => Promise<void>;
    downloadExport: () => void;
    resetExport: () => void;
}

export function useRecordExport(): UseRecordExportReturn {
    const [progress, setProgress] = useState<ExportProgress>({ status: 'idle' });
    const [exportId, setExportId] = useState<string | null>(null);
    const [isWholesale, setIsWholesale] = useState<boolean>(false);
    const [isExportUppercase, setIsExportUppercase] = useState<boolean>(false);
    const intervalRef = useRef<NodeJS.Timeout | null>(null);

    const isExporting = ['initiated', 'processing', 'combining'].includes(progress.status);

    const startExport = async (filters: Filters, isWholesaleExport: boolean = false, isUppercase: boolean = false) => {
        try {
            setIsWholesale(isWholesaleExport);
            setIsExportUppercase(isUppercase);

            // Clean filters
            const cleanedFilters = Object.fromEntries(
                Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== ''),
            );

            // Build query params
            const queryParams = new URLSearchParams();
            Object.entries(cleanedFilters).forEach(([key, value]) => {
                queryParams.append(`filter[${key}]`, String(value));
            });

            // Add wholesale parameter
            if (isWholesaleExport) {
                queryParams.append('wholesale', 'true');
            }

            // Add uppercase parameter
            if (isUppercase) {
                queryParams.append('uppercase', 'true');
            }

            // Start export
            const response = await axios.get(`${route('record.export')}?${queryParams.toString()}`);

            if (response.data.success) {
                const newExportId = response.data.export_id;
                setExportId(newExportId);
                setProgress({
                    status: 'initiated',
                    message: response.data.message,
                    export_id: newExportId,
                });

                // Start polling for progress
                startProgressPolling(newExportId);
            }
        } catch (error) {
            console.error('Export failed:', error);
            setProgress({
                status: 'failed',
                message: `Failed to start export`,
            });
        }
    };

    const startProgressPolling = (exportId: string) => {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
        }

        intervalRef.current = setInterval(async () => {
            try {
                const response = await axios.get(route('record.export.status', { exportId }));

                if (response.data.success) {
                    const newProgress = response.data.progress;
                    setProgress(newProgress);

                    // Stop polling if export is complete or failed
                    if (['completed', 'failed', 'error'].includes(newProgress.status)) {
                        if (intervalRef.current) {
                            clearInterval(intervalRef.current);
                            intervalRef.current = null;
                        }
                    }
                }
            } catch (error) {
                console.error('Failed to fetch export progress:', error);
                setProgress(prev => ({
                    ...prev,
                    status: 'error',
                    message: 'Failed to fetch export progress',
                }));

                if (intervalRef.current) {
                    clearInterval(intervalRef.current);
                    intervalRef.current = null;
                }
            }
        }, 1500); // Poll every 1.5 seconds
    };

    const cancelExport = async () => {
        if (!exportId || !isExporting) return;

        try {
            await axios.post(route('record.export.cancel', { exportId }));

            const cancelMessage = isWholesale
                ? "Esportazione ingrosso cancellata dall'utente"
                : "Esportazione cancellata dall'utente";

            setProgress(prev => ({
                ...prev,
                status: 'cancelled',
                message: cancelMessage,
            }));

            if (intervalRef.current) {
                clearInterval(intervalRef.current);
                intervalRef.current = null;
            }
        } catch (error) {
            console.error('Failed to cancel export:', error);
            setProgress(prev => ({
                ...prev,
                status: 'error',
                message: `Failed to cancel export`,
            }));
        }
    };

    const downloadExport = () => {
        if (exportId && progress.status === 'completed') {
            window.open(route('record.export.download', { exportId }), '_blank');
        }
    };

    const resetExport = () => {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
            intervalRef.current = null;
        }
        setProgress({ status: 'idle' });
        setExportId(null);
        setIsWholesale(false);
        setIsExportUppercase(false);
    };

    useEffect(() => {
        return () => {
            if (intervalRef.current) {
                clearInterval(intervalRef.current);
            }
        };
    }, []);

    return {
        progress,
        isExporting,
        startExport,
        cancelExport,
        downloadExport,
        resetExport,
    };
}
