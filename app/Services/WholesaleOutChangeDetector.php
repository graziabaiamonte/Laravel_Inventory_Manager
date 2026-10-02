<?php

namespace App\Services;

use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use App\Traits\LogsToChannel;

class WholesaleOutChangeDetector
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'wholesale_out';
    }

    /**
     * Detect what changed between the original WholesaleOut and the new data
     *
     * @param  WholesaleOut  $originalWholesaleOut  The original WholesaleOut (may be corrupted by auto-refresh)
     * @param  array  $newData  The new data from the request
     * @param  array  $originalRecordData  Snapshot of original record values to prevent auto-refresh corruption
     */
    public function detectChanges(WholesaleOut $originalWholesaleOut, array $newData, array $originalRecordData = []): array
    {
        // CRITICAL: Do NOT reload relationships as it corrupts the original state
        // The original state should be loaded properly before calling this method
        // $originalWholesaleOut->load('records.wholesaleOutRecordsArea');

        return [
            'records_added' => $this->findAddedRecords($originalWholesaleOut, $newData),
            'records_modified' => $this->findModifiedRecords($originalWholesaleOut, $newData, $originalRecordData),
            'records_deleted' => $this->findDeletedRecords($originalWholesaleOut, $newData),
            'status_changed' => $this->hasStatusChanged($originalWholesaleOut, $newData),
            'customer_changed' => $this->hasCustomerChanged($originalWholesaleOut, $newData),
            'area_changed' => $this->hasAreaChanged($originalWholesaleOut, $newData),
            'basic_fields_changed' => $this->hasBasicFieldsChanged($originalWholesaleOut, $newData),
        ];
    }

    /**
     * Find records that were added in the new data
     */
    private function findAddedRecords(WholesaleOut $originalWholesaleOut, array $newData): array
    {
        $newRecords = $newData['records'] ?? [];
        $addedRecords = [];

        foreach ($newRecords as $newRecord) {
            // If the record has no ID or ID is not in original records, it's added
            if (! isset($newRecord['id']) || ! $originalWholesaleOut->records->contains('id', $newRecord['id'])) {
                $addedRecords[] = $newRecord;

                $this->logInfo('Record identified as added', [
                    'record_id' => $newRecord['record_id'] ?? 'unknown',
                    'has_id' => isset($newRecord['id']),
                    'wholesale_out_record_id' => $newRecord['id'] ?? 'none',
                    'quantity' => $newRecord['quantity'] ?? 'unknown',
                ]);
            }
        }

        $this->logInfo('Added records detection completed', [
            'total_added' => count($addedRecords),
        ]);

        return $addedRecords;
    }

    /**
     * Find records that were modified
     */
    private function findModifiedRecords(WholesaleOut $originalWholesaleOut, array $newData, array $originalRecordData): array
    {
        $newRecords = $newData['records'] ?? [];
        $modifiedRecords = [];

        foreach ($newRecords as $newRecord) {
            if (! isset($newRecord['id'])) {
                continue; // Skip new records
            }

            // Use snapshot data if available, otherwise fall back to model (for backward compatibility)
            if (isset($originalRecordData[$newRecord['id']])) {
                $originalData = $originalRecordData[$newRecord['id']];
                $changes = $this->detectRecordChangesFromData($originalData, $newRecord);

                if (! empty($changes)) {
                    // Get the model for reconciliation processing
                    $originalRecord = $originalWholesaleOut->records->firstWhere('id', $newRecord['id']);

                    $modifiedRecords[] = [
                        'original' => $originalRecord,
                        'new_data' => $newRecord,
                        'changes' => $changes,
                    ];
                }
            } else {
                // Fallback to old method if snapshot not available
                $originalRecord = $originalWholesaleOut->records->firstWhere('id', $newRecord['id']);
                if (! $originalRecord) {
                    continue; // Skip if original not found
                }

                $changes = $this->detectRecordChanges($originalRecord, $newRecord);

                if (! empty($changes)) {
                    $modifiedRecords[] = [
                        'original' => $originalRecord,
                        'new_data' => $newRecord,
                        'changes' => $changes,
                    ];
                }
            }
        }

        return $modifiedRecords;
    }

    /**
     * Find records that were deleted (present in original but not in new data)
     */
    private function findDeletedRecords(WholesaleOut $originalWholesaleOut, array $newData): array
    {
        $newRecords = collect($newData['records'] ?? []);

        // Only look for IDs that exist (filter out null/empty IDs from new records)
        $newRecordIds = $newRecords->pluck('id')->filter()->toArray();

        $deletedRecords = [];

        $this->logInfo('Detecting deleted records', [
            'original_records_count' => $originalWholesaleOut->records->count(),
            'new_records_count' => count($newData['records'] ?? []),
            'new_record_ids' => $newRecordIds,
        ]);

        // Check each original record to see if it's still in the new data
        foreach ($originalWholesaleOut->records as $originalRecord) {
            $stillExists = false;

            foreach ($newRecords as $newRecord) {
                // Match by ID if the new record has an ID
                if (isset($newRecord['id']) && $newRecord['id'] == $originalRecord->id) {
                    $stillExists = true;
                    break;
                }
            }

            // If original record is not found in new data, it's deleted
            if (! $stillExists) {
                $deletedRecords[] = $originalRecord;

                $this->logInfo('Record identified as deleted', [
                    'wholesale_out_record_id' => $originalRecord->id,
                    'record_id' => $originalRecord->record_id,
                    'quantity' => $originalRecord->quantity,
                ]);
            }
        }

        $this->logInfo('Deleted records detection completed', [
            'total_deleted' => count($deletedRecords),
        ]);

        return $deletedRecords;
    }

    /**
     * Detect specific changes in a record
     */
    private function detectRecordChanges(WholesaleOutRecord $originalRecord, array $newRecord): array
    {
        $changes = [];

        // Get the truly original values from when the model was first loaded
        // This prevents issues with stale model data after database updates
        $originalQuantity = $originalRecord->getOriginal('quantity') ?? $originalRecord->quantity;
        $newQuantity = $newRecord['quantity'];

        // DEBUG: Check all sources of original quantity
        $this->logInfo('Detecting changes for record (fallback method)', [
            'record_id' => $originalRecord->id,
            'getOriginal_quantity' => $originalRecord->getOriginal('quantity'),
            'model_quantity' => $originalRecord->quantity,
            'final_original_quantity' => $originalQuantity,
            'new_quantity' => $newQuantity,
            'quantities_equal' => $originalQuantity == $newQuantity,
            'using_fallback_method' => true,
        ]);

        // Check quantity change using original values
        if ($originalQuantity != $newQuantity) {
            $changes['quantity'] = [
                'old' => $originalQuantity,
                'new' => $newQuantity,
            ];
            $this->logInfo('Quantity change detected', [
                'record_id' => $originalRecord->id,
                'old' => $originalQuantity,
                'new' => $newQuantity,
            ]);
        }

        // Check unit price change using original values
        $originalUnitPriceAmount = $originalRecord->getOriginal('unit_price') ?? $originalRecord->unit_price->getAmount();
        $originalUnitPrice = is_object($originalUnitPriceAmount)
            ? $originalUnitPriceAmount->getAmount() / 100
            : $originalUnitPriceAmount / 100; // Convert from cents
        $newUnitPrice = (float) $newRecord['unit_price'];
        if (abs($originalUnitPrice - $newUnitPrice) > 0.01) { // Allow for small floating point differences
            $changes['unit_price'] = [
                'old' => $originalUnitPrice,
                'new' => $newUnitPrice,
            ];
        }

        // Check discount change using original values
        $originalDiscount = $originalRecord->getOriginal('discount') ?? $originalRecord->discount ?? 0;
        $newDiscount = $newRecord['discount'] ?? 0;
        if ($originalDiscount != $newDiscount) {
            $changes['discount'] = [
                'old' => $originalDiscount,
                'new' => $newDiscount,
            ];
        }

        // Check area allocation changes
        $areaChanges = $this->detectAreaChanges($originalRecord, $newRecord);
        if (! empty($areaChanges)) {
            $changes['area_allocations'] = $areaChanges;
        }

        return $changes;
    }

    /**
     * Detect changes in area allocations for a record
     */
    private function detectAreaChanges(WholesaleOutRecord $originalRecord, array $newRecord): array
    {
        $originalAreas = $originalRecord->wholesaleOutRecordsArea->mapWithKeys(function ($area) {
            return [$area->area_id => $area->quantity];
        })->toArray();

        $newAreas = [];

        // Support both 'areas' and 'area_quantities' format for compatibility
        $areaData = $newRecord['areas'] ?? $newRecord['area_quantities'] ?? [];

        if (is_array($areaData)) {
            foreach ($areaData as $areaQty) {
                $newAreas[$areaQty['area_id']] = $areaQty['quantity'];
            }
        }

        $changes = [];

        // Find added/modified areas
        foreach ($newAreas as $areaId => $quantity) {
            if (! isset($originalAreas[$areaId])) {
                $changes['added'][$areaId] = $quantity;
            } elseif ($originalAreas[$areaId] != $quantity) {
                $changes['modified'][$areaId] = [
                    'old' => $originalAreas[$areaId],
                    'new' => $quantity,
                ];
            }
        }

        // Find removed areas
        foreach ($originalAreas as $areaId => $quantity) {
            if (! isset($newAreas[$areaId])) {
                $changes['removed'][$areaId] = $quantity;
            }
        }

        return $changes;
    }

    /**
     * Detect specific changes in a record using plain array data
     */
    private function detectRecordChangesFromData(array $originalRecordData, array $newRecord): array
    {
        $changes = [];

        // Get values from plain arrays
        $originalQuantity = $originalRecordData['quantity'];
        $newQuantity = $newRecord['quantity'];

        // Debug logging
        $this->logInfo('Detecting changes for record using plain data', [
            'record_id' => $originalRecordData['id'],
            'original_quantity' => $originalQuantity,
            'new_quantity' => $newQuantity,
            'quantities_equal' => $originalQuantity == $newQuantity,
            'using_plain_data' => true,
        ]);

        // Check quantity change
        if ($originalQuantity != $newQuantity) {
            $changes['quantity'] = [
                'old' => $originalQuantity,
                'new' => $newQuantity,
            ];
            $this->logInfo('Quantity change detected from plain data', [
                'record_id' => $originalRecordData['id'],
                'old' => $originalQuantity,
                'new' => $newQuantity,
            ]);
        }

        // Check unit price change
        $originalUnitPrice = is_object($originalRecordData['unit_price'])
            ? $originalRecordData['unit_price']->getAmount() / 100
            : $originalRecordData['unit_price'] / 100; // Convert from cents
        $newUnitPrice = (float) $newRecord['unit_price'];
        if (abs($originalUnitPrice - $newUnitPrice) > 0.01) { // Allow for small floating point differences
            $changes['unit_price'] = [
                'old' => $originalUnitPrice,
                'new' => $newUnitPrice,
            ];
        }

        // Check discount change
        $originalDiscount = $originalRecordData['discount'] ?? 0;
        $newDiscount = $newRecord['discount'] ?? 0;
        if ($originalDiscount != $newDiscount) {
            $changes['discount'] = [
                'old' => $originalDiscount,
                'new' => $newDiscount,
            ];
        }

        // Check area allocation changes
        $areaChanges = $this->detectAreaChangesFromData($originalRecordData, $newRecord);
        if (! empty($areaChanges)) {
            $changes['area_allocations'] = $areaChanges;
        }

        return $changes;
    }

    /**
     * Detect changes in area allocations using plain array data
     */
    private function detectAreaChangesFromData(array $originalRecordData, array $newRecord): array
    {
        $originalAreas = [];
        foreach ($originalRecordData['area_allocations'] as $allocation) {
            $originalAreas[$allocation['area_id']] = $allocation['quantity'];
        }

        $newAreas = [];
        $areaData = $newRecord['areas'] ?? $newRecord['area_quantities'] ?? [];
        if (is_array($areaData)) {
            foreach ($areaData as $areaQty) {
                $newAreas[$areaQty['area_id']] = $areaQty['quantity'];
            }
        }

        $changes = [];

        // Find added/modified areas
        foreach ($newAreas as $areaId => $quantity) {
            if (! isset($originalAreas[$areaId])) {
                $changes['added'][$areaId] = $quantity;
            } elseif ($originalAreas[$areaId] != $quantity) {
                $changes['modified'][$areaId] = [
                    'old' => $originalAreas[$areaId],
                    'new' => $quantity,
                ];
            }
        }

        // Find removed areas
        foreach ($originalAreas as $areaId => $quantity) {
            if (! isset($newAreas[$areaId])) {
                $changes['removed'][$areaId] = $quantity;
            }
        }

        return $changes;
    }

    /**
     * Check if status changed
     */
    private function hasStatusChanged(WholesaleOut $originalWholesaleOut, array $newData): bool
    {
        return $originalWholesaleOut->status !== ($newData['status'] ?? $originalWholesaleOut->status);
    }

    /**
     * Check if customer changed
     */
    private function hasCustomerChanged(WholesaleOut $originalWholesaleOut, array $newData): bool
    {
        return $originalWholesaleOut->customer_id !== ($newData['customer_id'] ?? $originalWholesaleOut->customer_id);
    }

    /**
     * Check if area changed
     */
    private function hasAreaChanged(WholesaleOut $originalWholesaleOut, array $newData): bool
    {
        return $originalWholesaleOut->area_id !== ($newData['area_id'] ?? $originalWholesaleOut->area_id);
    }

    /**
     * Check if basic fields changed (doc_num, description)
     */
    private function hasBasicFieldsChanged(WholesaleOut $originalWholesaleOut, array $newData): bool
    {
        return $originalWholesaleOut->doc_num !== ($newData['doc_num'] ?? $originalWholesaleOut->doc_num) ||
               $originalWholesaleOut->description !== ($newData['description'] ?? $originalWholesaleOut->description);
    }
}
