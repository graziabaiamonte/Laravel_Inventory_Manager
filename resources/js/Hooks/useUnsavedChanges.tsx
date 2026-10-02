/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState, useEffect, useRef } from 'react';

/**
 * Unified hook for detecting unsaved changes in forms.
 *
 * Supports TWO modes:
 * 1. LEGACY MODE (backwards compatible): Uses JSON.stringify comparison
 *    - Triggered when editableFields is NOT provided
 *    - Works for simple forms but has type coercion issues
 *
 * 2. EXPLICIT MODE (recommended): Compares only specified editable fields
 *    - Triggered when editableFields IS provided
 *    - More reliable, handles type coercion properly
 *    - Required for forms with nested records (WholesaleOut, Sale, etc.)
 *
 * Also handles browser/navigation warnings automatically.
 */

interface UseUnsavedChangesOptions {
    formData: any;
    initialData?: any;
    enabled?: boolean;

    // NEW: Explicit field tracking (recommended)
    editableFields?: string[]; // Simple fields to track (customer_id, name, etc.)
    recordsConfig?: {
        // For complex forms with nested records
        field: string; // Name of the records array field (e.g., 'records')
        editableFields: string[]; // Which fields in each record to track
        numericFields?: string[]; // Fields that need numeric comparison (unit_price, vat, etc.)
    };

    // Customization
    warningMessage?: string;
}

/**
 * Main hook export
 */
export default function useUnsavedChanges(options: UseUnsavedChangesOptions) {
    const {
        formData,
        initialData,
        enabled = true,
        editableFields,
        recordsConfig,
        warningMessage = 'Hai modifiche non salvate. Vuoi davvero uscire senza salvare?',
    } = options;

    const [isDirty, setIsDirty] = useState(false);
    const [isInitialLoad, setIsInitialLoad] = useState(true);
    const initialDataRef = useRef(initialData);
    const initialSnapshot = useRef<any>(null);

    // Initialize snapshot for explicit mode
    useEffect(() => {
        if (editableFields || recordsConfig) {
            // EXPLICIT MODE: Create snapshot of editable fields only
            initialSnapshot.current = createSnapshot(initialData, editableFields, recordsConfig);
        } else {
            // LEGACY MODE: Store full initial data
            initialDataRef.current = initialData;
        }
    }, [initialData, editableFields, recordsConfig]);

    // Mark initial load complete after first render
    useEffect(() => {
        setIsInitialLoad(false);
    }, []);

    // Change detection logic
    useEffect(() => {
        if (!enabled || isInitialLoad) return;

        // Reset dirty flag on successful save
        if ((formData as any).wasSuccessful) {
            setIsDirty(false);
            return;
        }

        let hasChanges = false;

        if (editableFields || recordsConfig) {
            // EXPLICIT MODE: Compare only specified fields
            hasChanges = detectChangesExplicit(formData, initialSnapshot.current, editableFields, recordsConfig);
        } else {
            // LEGACY MODE: Use JSON.stringify comparison
            hasChanges = detectChangesLegacy(formData, initialDataRef.current);
        }

        setIsDirty(hasChanges);
    }, [formData, enabled, isInitialLoad, editableFields, recordsConfig]);

    // Browser/navigation warning handlers
    useEffect(() => {
        if (!enabled || !isDirty) return;

        // Browser's native warning (refresh, close tab, etc.)
        const handleBeforeUnload = (e: BeforeUnloadEvent) => {
            e.preventDefault();
            e.returnValue = ''; // Modern browsers show their own message
        };

        // Inertia navigation warning (clicking links within app)
        const handleInertiaBefore = (event: any) => {
            // Don't block form submissions
            const method = event.detail.visit.method?.toLowerCase();
            if (method === 'post' || method === 'put' || method === 'patch') {
                return;
            }

            // Show custom confirmation dialog
            if (!confirm(warningMessage)) {
                event.preventDefault();
            }
        };

        window.addEventListener('beforeunload', handleBeforeUnload);
        document.addEventListener('inertia:before', handleInertiaBefore);

        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
            document.removeEventListener('inertia:before', handleInertiaBefore);
        };
    }, [isDirty, enabled, warningMessage]);

    return {
        isDirty,
        setIsDirty,
        // Legacy compatibility
        hasUnsavedChanges: isDirty,
        setHasUnsavedChanges: setIsDirty,
    };
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Create snapshot of editable fields only (for explicit mode)
 */
function createSnapshot(
    data: any,
    editableFields?: string[],
    recordsConfig?: UseUnsavedChangesOptions['recordsConfig'],
) {
    if (!data) return null;

    const snapshot: any = {};

    // Capture simple editable fields
    if (editableFields) {
        editableFields.forEach(field => {
            snapshot[field] = data[field];
        });
    }

    // Capture records array if configured
    if (recordsConfig && data[recordsConfig.field]) {
        const records = data[recordsConfig.field];
        snapshot.records_count = records.length;
        snapshot.records_snapshot = records.map((record: any) => {
            const recordSnapshot: any = {};

            recordsConfig.editableFields.forEach(field => {
                recordSnapshot[field] = record[field];
            });

            // Special handling for area_quantities (extract area_id)
            if (record.area_quantities && Array.isArray(record.area_quantities)) {
                recordSnapshot.area_id = record.area_quantities[0]?.area_id || null;
            }

            return recordSnapshot;
        });
    }

    return snapshot;
}

/**
 * EXPLICIT MODE: Compare only specified editable fields
 */
function detectChangesExplicit(
    formData: any,
    initialSnapshot: any,
    editableFields?: string[],
    recordsConfig?: UseUnsavedChangesOptions['recordsConfig'],
): boolean {
    if (!initialSnapshot) return false;

    // Check simple fields
    if (editableFields) {
        const simpleFieldsChanged = editableFields.some(field => {
            const currentValue = formData[field];
            const initialValue = initialSnapshot[field];

            // Normalize empty strings and nulls for comparison (forms often convert null to '')
            const normalizedCurrent = currentValue === '' ? null : currentValue;
            const normalizedInitial = initialValue === '' ? null : initialValue;

            // Handle null/undefined
            if (normalizedCurrent == null && normalizedInitial == null) return false;
            if (normalizedCurrent == null || normalizedInitial == null) return true;

            // String comparison (trim whitespace)
            if (typeof normalizedCurrent === 'string' && typeof normalizedInitial === 'string') {
                return normalizedCurrent.trim() !== normalizedInitial.trim();
            }

            // Default comparison
            return normalizedCurrent !== normalizedInitial;
        });

        if (simpleFieldsChanged) return true;
    }

    // Check records array
    if (recordsConfig && formData[recordsConfig.field]) {
        const currentRecords = formData[recordsConfig.field];

        // Check if records count changed
        if (currentRecords.length !== initialSnapshot.records_count) {
            return true;
        }

        // Check if any record's editable fields changed
        const recordsChanged = currentRecords.some((record: any, index: number) => {
            const initialRecord = initialSnapshot.records_snapshot[index];
            if (!initialRecord) {
                return true; // New record added
            }

            // Helper for numeric comparison (handles string vs number)
            const numericChanged = (current: string | number, initial: string | number) => {
                const currentNum =
                    typeof current === 'string' ? parseFloat(current.replace(/,/g, '')) : Number(current);
                const initialNum =
                    typeof initial === 'string' ? parseFloat(initial.replace(/,/g, '')) : Number(initial);
                return currentNum !== initialNum;
            };

            // Compare each editable field
            const changed = recordsConfig.editableFields.some(field => {
                const currentValue = record[field];
                const initialValue = initialRecord[field];

                // Check if this field needs numeric comparison
                const isNumericField = recordsConfig.numericFields?.includes(field);

                if (isNumericField) {
                    const numChanged = numericChanged(currentValue, initialValue);
                    return numChanged;
                }

                // Special handling for boolean fields (checkboxes that can be 0/1 or true/false)
                if (typeof currentValue === 'boolean' || typeof initialValue === 'boolean') {
                    const normalizedCurrent = Boolean(currentValue);
                    const normalizedInitial = Boolean(initialValue);
                    const boolChanged = normalizedCurrent !== normalizedInitial;
                    return boolChanged;
                }

                // Special handling for object fields (artist, label, format with id/name)
                if (
                    currentValue &&
                    typeof currentValue === 'object' &&
                    initialValue &&
                    typeof initialValue === 'object'
                ) {
                    // Compare by id if both have id property
                    if ('id' in currentValue && 'id' in initialValue) {
                        const idChanged = currentValue.id !== initialValue.id;
                        return idChanged;
                    }
                }

                // Special handling for area_quantities array (multi-area support in WholesaleIn)
                if (field === 'area_quantities') {
                    const currentAreas = currentValue || [];
                    const initialAreas = initialValue || [];

                    // Compare array length first
                    if (currentAreas.length !== initialAreas.length) {
                        return true;
                    }

                    // Compare each area's quantity and area_id
                    const areasChanged = currentAreas.some((currentArea: any, areaIndex: number) => {
                        const initialArea = initialAreas[areaIndex];
                        if (!initialArea) return true;

                        const areaIdChanged = currentArea.area_id !== initialArea.area_id;
                        const quantityChanged = Number(currentArea.quantity) !== Number(initialArea.quantity);

                        return areaIdChanged || quantityChanged;
                    });

                    return areasChanged;
                }

                // Special handling for area_id (for single-area WholesaleOut)
                if (field === 'area_id' && record.area_quantities) {
                    const currentAreaId = record.area_quantities[0]?.area_id || null;
                    return currentAreaId !== initialValue;
                }

                // Default comparison
                return currentValue !== initialValue;
            });

            return changed;
        });

        if (recordsChanged) return true;
    }

    return false;
}

/**
 * LEGACY MODE: Use JSON.stringify comparison (backwards compatible)
 */
function detectChangesLegacy(formData: any, initialData: any): boolean {
    if (!initialData) {
        // For new records, check if any field has been filled
        return Object.entries(formData).some(([key, value]) => {
            if (key === '_method') return false; // Skip Laravel method field
            if (typeof value === 'string') return value.trim().length > 0;
            if (typeof value === 'number') return value > 0;
            if (Array.isArray(value)) return value.length > 0;
            if (value && typeof value === 'object') return Object.keys(value).length > 0;
            return false;
        });
    }

    // For existing records, compare with initial data
    return Object.entries(formData).some(([key, value]) => {
        if (key === '_method') return false; // Skip Laravel method field

        const initialValue = initialData[key];

        // Handle different types of values
        if (typeof value === 'string' && typeof initialValue === 'string') {
            return value.trim() !== initialValue.trim();
        }

        if (typeof value === 'number' && typeof initialValue === 'number') {
            return value !== initialValue;
        }

        if (Array.isArray(value) && Array.isArray(initialValue)) {
            return JSON.stringify(value) !== JSON.stringify(initialValue);
        }

        if (value && typeof value === 'object' && initialValue && typeof initialValue === 'object') {
            return JSON.stringify(value) !== JSON.stringify(initialValue);
        }

        return value !== initialValue;
    });
}
