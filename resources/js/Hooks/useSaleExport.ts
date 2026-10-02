import { useState, useEffect, useRef } from 'react';
import axios from 'axios';

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

interface UseSaleExportReturn {
    progress: ExportProgress;
    isExporting: boolean;
    startExport: (filters: Filters) => Promise<void>;
    cancelExport: () => Promise<void>;
    downloadExport: () => void;
    resetExport: () => void;
}

export function useSaleExport(): UseSaleExportReturn {
    const [progress, setProgress] = useState<ExportProgress>({ status: 'idle' });
    const [exportId, setExportId] = useState<string | null>(null);
    const intervalRef = useRef<NodeJS.Timeout | null>(null);

    const isExporting = ['initiated', 'processing', 'combining'].includes(progress.status);

    const startExport = async (filters: Filters) => {
        try {
            // Clean filters
            const cleanedFilters = Object.fromEntries(
                Object.entries(filters).filter(([, value]) => value !== null && value !== undefined && value !== ''),
            );

            // Build query params
            const queryParams = new URLSearchParams();
            Object.entries(cleanedFilters).forEach(([key, value]) => {
                // Handle date range filter specially
                if (key === 'date' && typeof value === 'object' && value !== null) {
                    const dateRange = value as { startDate: Date | string; endDate: Date | string };
                    const startDate =
                        dateRange.startDate instanceof Date
                            ? dateRange.startDate.toISOString().split('T')[0]
                            : String(dateRange.startDate).split('T')[0];
                    const endDate =
                        dateRange.endDate instanceof Date
                            ? dateRange.endDate.toISOString().split('T')[0]
                            : String(dateRange.endDate).split('T')[0];
                    queryParams.append(`filter[${key}][startDate]`, startDate);
                    queryParams.append(`filter[${key}][endDate]`, endDate);
                } else {
                    queryParams.append(`filter[${key}]`, String(value));
                }
            });

            // Start export
            const response = await axios.get(`${route('sale.export')}?${queryParams.toString()}`);

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
                const response = await axios.get(route('sale.export.status', { exportId }));

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
        }, 1500); // Poll every 1.5 seconds for responsive updates
    };

    const cancelExport = async () => {
        if (!exportId || !isExporting) return;

        try {
            await axios.post(route('sale.export.cancel', { exportId }));

            setProgress(prev => ({
                ...prev,
                status: 'cancelled',
                message: "Esportazione cancellata dall'utente",
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
            window.open(route('sale.export.download', { exportId }), '_blank');
        }
    };

    const resetExport = () => {
        if (intervalRef.current) {
            clearInterval(intervalRef.current);
            intervalRef.current = null;
        }
        setProgress({ status: 'idle' });
        setExportId(null);
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
