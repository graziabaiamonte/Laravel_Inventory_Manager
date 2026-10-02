<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use App\Traits\LogsToChannel;
use Illuminate\Support\Facades\DB;

class ActiveWholesaleOutReconciliationService
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'wholesale_out';
    }

    private WholesaleOutChangeDetector $changeDetector;

    public function __construct(WholesaleOutChangeDetector $changeDetector)
    {
        $this->changeDetector = $changeDetector;
    }

    /**
     * Reconcile changes to an active WholesaleOut
     * Only processes changes if the WholesaleOut is active (status = 1)
     *
     * @param  WholesaleOut  $wholesaleOut  The original WholesaleOut state (may be corrupted by Laravel auto-refresh)
     * @param  array  $newData  The new data from the request
     * @param  array  $originalRecordData  Snapshot of original record data to prevent auto-refresh corruption
     */
    public function reconcileChanges(WholesaleOut $wholesaleOut, array $newData, array $originalRecordData = []): array
    {
        // Only reconcile if WholesaleOut is active
        if ($wholesaleOut->status !== 1) {
            $this->logInfo('Skipping reconciliation - WholesaleOut is not active', [
                'wholesale_out_id' => $wholesaleOut->id,
                'status' => $wholesaleOut->status,
            ]);

            return [];
        }

        // Restore stock from activated backorders before deleting them
        // Activated backorders have physically decremented stock that needs to be restored
        // when the WholesaleOut is edited (they become stale/invalid)
        $activatedBackorders = $wholesaleOut->backorders()->where('status', 1)->get();

        foreach ($activatedBackorders as $activatedBackorder) {
            $this->restoreStockFromActivatedBackorder($activatedBackorder);
        }

        // Delete all activated backorders - they become stale/invalid when editing active WholesaleOut
        // Any previous backorder state no longer reflects the current WholesaleOut state after editing
        $deletedActivatedBackorders = $activatedBackorders->count();
        $wholesaleOut->backorders()->where('status', 1)->delete();

        if ($deletedActivatedBackorders > 0) {
            $this->logInfo('Restored stock and deleted stale activated backorders before reconciliation', [
                'wholesale_out_id' => $wholesaleOut->id,
                'deleted_count' => $deletedActivatedBackorders,
                'reason' => 'Activated backorders become invalid when WholesaleOut is edited',
            ]);
        }

        // Validate input data
        if (empty($newData)) {
            $this->logWarning('Empty newData provided to reconcileChanges', [
                'wholesale_out_id' => $wholesaleOut->id,
            ]);

            return ['Warning: No data provided for reconciliation'];
        }

        // Load required relationships
        if (! $wholesaleOut->relationLoaded('records')) {
            $wholesaleOut->load('records.wholesaleOutRecordsArea');
        }

        $this->logInfo('Starting WholesaleOut reconciliation', [
            'wholesale_out_id' => $wholesaleOut->id,
            'existing_records_count' => $wholesaleOut->records->count(),
            'new_records_count' => count($newData['records'] ?? []),
        ]);

        $warnings = [];

        // FULL RECOMPUTE ON EVERY SAVE
        // ------------------------------------------------------------------
        // Every save of an active WholesaleOut re-processes ALL record rows,
        // not just the ones that changed. This is required because the block
        // above restores stock from activated backorders on every save; if we
        // only re-processed changed records, an edit to a non-record field
        // (description, customer, ...) would leave that restored stock without
        // a matching re-allocation, INFLATING the stock.
        //
        // For every record we: RESTORE its previously decremented stock (using
        // the ORIGINAL in-memory allocations loaded by the controller before the
        // rows were overwritten), then RE-ALLOCATE the FULL requested quantity
        // from the target area. Restore and re-allocation balance out, so an
        // unrelated save is a no-op on stock; a real change is applied cleanly.

        $newRecords = $newData['records'] ?? [];
        $newRecordIds = collect($newRecords)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $deletionsCount = $wholesaleOut->records
            ->reject(fn ($record) => in_array((int) $record->id, $newRecordIds, true))
            ->count();
        $additionsCount = collect($newRecords)->filter(fn ($record) => empty($record['id']))->count();

        $this->logInfo('Starting full recompute of active WholesaleOut', [
            'wholesale_out_id' => $wholesaleOut->id,
            'records_reprocessed' => count($newRecords),
            'records_added' => $additionsCount,
            'records_deleted' => $deletionsCount,
        ]);

        DB::transaction(function () use ($wholesaleOut, $newRecords, $newRecordIds, &$warnings) {
            // Clear all pending backorders (status = 0) - they are recreated below as needed.
            $pendingBackorders = $wholesaleOut->backorders()->where('status', 0)->get();
            foreach ($pendingBackorders as $pendingBackorder) {
                foreach ($pendingBackorder->backorderRecords as $backorderRecord) {
                    $backorderRecord->backorderRecordsAreas()->delete();
                    $backorderRecord->delete();
                }
                $pendingBackorder->delete();
            }

            // DELETIONS: original records no longer present get their stock restored and removed.
            foreach ($wholesaleOut->records as $originalRecord) {
                if (! in_array((int) $originalRecord->id, $newRecordIds, true)) {
                    $deleteWarnings = $this->handleRecordDeletion($originalRecord);
                    $warnings = array_merge($warnings, $deleteWarnings);
                }
            }

            // ADD / MODIFY: re-process every current record from scratch.
            foreach ($newRecords as $newRecordData) {
                // Original allocations for this record (from the in-memory original state)
                $originalAreaAllocations = [];
                if (! empty($newRecordData['id'])) {
                    $originalRecord = $wholesaleOut->records->firstWhere('id', (int) $newRecordData['id']);
                    if ($originalRecord) {
                        $originalAreaAllocations = $originalRecord->wholesaleOutRecordsArea
                            ->map(fn ($area) => ['area_id' => $area->area_id, 'quantity' => (int) $area->quantity])
                            ->values()
                            ->all();
                    }
                }

                $dbRecord = ! empty($newRecordData['id'])
                    ? WholesaleOutRecord::find($newRecordData['id'])
                    : null;

                if (! $dbRecord && isset($newRecordData['record_id'])) {
                    // Newly added record (created by updateRecordsWithoutStockChanges)
                    $dbRecord = WholesaleOutRecord::where('wholesale_out_id', $wholesaleOut->id)
                        ->where('record_id', $newRecordData['record_id'])
                        ->latest()
                        ->first();
                }

                if (! $dbRecord) {
                    $warnings[] = 'Record non trovato per la riconciliazione';

                    continue;
                }

                $this->reprocessRecord($dbRecord, $newRecordData, $originalAreaAllocations, $wholesaleOut, $warnings);
            }
        });

        // Clean up empty backorders
        $this->cleanupEmptyBackorders($wholesaleOut);

        // CRITICAL: Refresh relationships before defensive validation
        // Records may have been deleted, and cached relationships will still reference them
        $wholesaleOut->load('records.wholesaleOutRecordsArea');

        // DEFENSIVE VALIDATION: Safety net to catch allocation bugs.
        // Under normal operation this should NOT make any corrections.
        $validationWarnings = $this->validateAndCorrectAllAreaAssignments($wholesaleOut);
        $warnings = array_merge($warnings, $validationWarnings);

        $this->logInfo('WholesaleOut reconciliation completed (full recompute)', [
            'wholesale_out_id' => $wholesaleOut->id,
            'records_reprocessed' => count($newRecords),
            'additions' => $additionsCount,
            'deletions' => $deletionsCount,
            'warnings' => count($warnings),
            'defensive_validations' => count($validationWarnings),
            'bugs_detected' => count($validationWarnings) > 0 ? 'YES ⚠️' : 'NO ✓',
        ]);

        return $warnings;
    }

    /**
     * Re-process a single record: restore its original stock allocation, then
     * re-allocate the FULL requested quantity from the target area. Any shortfall
     * becomes a pending backorder.
     *
     * Allocating the FULL requested quantity (not the area_quantities amount) is
     * essential: on every save the stock restored from activated backorders and
     * from the original allocation must be fully re-decremented, otherwise stock
     * inflates. Restore + full re-allocation always balance out.
     *
     * @param  array  $originalAreaAllocations  [['area_id' => int, 'quantity' => int], ...]
     */
    private function reprocessRecord(
        WholesaleOutRecord $dbRecord,
        array $newRecordData,
        array $originalAreaAllocations,
        WholesaleOut $wholesaleOut,
        array &$warnings
    ): void {
        // 1. RESTORE the stock originally decremented for this record (original areas).
        foreach ($originalAreaAllocations as $allocation) {
            if ($allocation['quantity'] > 0) {
                $stock = Stock::where('record_id', $dbRecord->record_id)
                    ->where('area_id', $allocation['area_id'])
                    ->first();
                if ($stock) {
                    $stock->increment('quantity', $allocation['quantity']);
                }
            }
        }

        // 2. Clear stale area allocations so we rebuild them from the fresh allocation.
        $dbRecord->wholesaleOutRecordsArea()->delete();
        $dbRecord->unsetRelation('wholesaleOutRecordsArea');

        // 3. Determine target area (new selection > original allocation > WO default).
        $targetAreaId = null;
        if (! empty($newRecordData['area_quantities'][0]['area_id'])) {
            $targetAreaId = (int) $newRecordData['area_quantities'][0]['area_id'];
        } elseif (! empty($originalAreaAllocations[0]['area_id'])) {
            $targetAreaId = (int) $originalAreaAllocations[0]['area_id'];
        } else {
            $targetAreaId = $wholesaleOut->area_id;
        }

        // 4. RE-ALLOCATE the FULL requested quantity from the target area.
        $requestedQuantity = (int) $dbRecord->quantity;
        $shippedQuantity = 0;

        if ($requestedQuantity > 0 && $targetAreaId) {
            $stock = Stock::where('record_id', $dbRecord->record_id)
                ->where('area_id', $targetAreaId)
                ->first();
            $available = $stock ? max(0, $stock->quantity) : 0;
            $shippedQuantity = min($requestedQuantity, $available);
            if ($shippedQuantity > 0) {
                $stock->decrement('quantity', $shippedQuantity);
            }
        }

        // Preserve the area association even when nothing could be allocated
        // (fully backordered record): keep a quantity-0 assignment so the record
        // still shows its intended area instead of losing it.
        if ($targetAreaId) {
            $dbRecord->wholesaleOutRecordsArea()->create([
                'area_id' => $targetAreaId,
                'quantity' => $shippedQuantity,
            ]);
        }

        $dbRecord->shipped_quantity = $shippedQuantity;
        $dbRecord->save();

        // 5. Backorder for any shortfall.
        $backorderQuantity = $requestedQuantity - $shippedQuantity;
        if ($backorderQuantity > 0) {
            $this->createBackorderForRecord($dbRecord, $backorderQuantity, $targetAreaId);
            $warnings[] = "Stock insufficiente per record {$dbRecord->record_id}: creato backorder per {$backorderQuantity} unità.";
        }

        $this->logInfo('Record reprocessed (full recompute)', [
            'wholesale_out_record_id' => $dbRecord->id,
            'record_id' => $dbRecord->record_id,
            'target_area_id' => $targetAreaId,
            'requested' => $requestedQuantity,
            'shipped' => $shippedQuantity,
            'backorder' => $backorderQuantity,
        ]);
    }

    /**
     * Handle deletion of a record - restore stock and cancel backorders
     */
    private function handleRecordDeletion(WholesaleOutRecord $record): array
    {
        $warnings = [];

        $this->logInfo('Handling record deletion for active WholesaleOut', [
            'record_id' => $record->id,
            'wholesale_out_record_id' => $record->id,
            'quantity' => $record->quantity,
        ]);

        // 1. Calculate how much stock was actually decremented (excluding backorders)
        $actuallyDecrementedQuantity = $this->calculateActuallyDecrementedQuantity($record);

        // 2. Restore stock for the actually decremented quantity
        if ($actuallyDecrementedQuantity > 0) {
            $restoreWarnings = $this->restoreStockForRecord($record, $actuallyDecrementedQuantity);
            $warnings = array_merge($warnings, $restoreWarnings);
        }

        // 3. Cancel all backorders for this record
        $cancelWarnings = $this->cancelAllBackordersForRecord($record);
        $warnings = array_merge($warnings, $cancelWarnings);

        // 4. Delete the record and its area assignments
        $record->wholesaleOutRecordsArea()->delete();
        $record->delete();

        $this->logInfo('Record and area assignments deleted', [
            'record_id' => $record->id,
        ]);

        return $warnings;
    }

    /**
     * Handle modification of a record - reconcile stock and backorders
     */
    private function handleRecordModification(array $modification): array
    {
        $warnings = [];
        $originalRecord = $modification['original'];
        $newData = $modification['new_data'];
        $changes = $modification['changes'];

        $this->logInfo('Handling record modification for active WholesaleOut', [
            'record_id' => $originalRecord->id,
            'changes' => array_keys($changes),
        ]);

        // Handle COMBINED quantity and area changes first (A2 scenario)
        // But only if the area IDs actually changed (not just quantities within same area)
        if (isset($changes['quantity']) && isset($changes['area_allocations'])) {
            $areaIdsChanged = isset($changes['area_allocations']['added']) || isset($changes['area_allocations']['removed']);

            if ($areaIdsChanged) {
                // True area change + quantity change (A2 scenario)
                $combinedWarnings = $this->handleCombinedQuantityAndAreaChanges(
                    $originalRecord,
                    $changes,
                    $newData
                );
                $warnings = array_merge($warnings, $combinedWarnings);
            } else {
                // Only quantity changed within same area - treat as simple quantity change
                $this->logInfo('Quantity change within same area (not area change)', [
                    'record_id' => $originalRecord->id,
                    'old_quantity' => $changes['quantity']['old'],
                    'new_quantity' => $changes['quantity']['new'],
                ]);

                $quantityWarnings = $this->handleQuantityChange(
                    $originalRecord,
                    $changes['quantity']['old'],
                    $changes['quantity']['new']
                );
                $warnings = array_merge($warnings, $quantityWarnings);
            }
        }
        // Handle quantity changes ONLY if no area changes (to avoid double processing)
        elseif (isset($changes['quantity'])) {
            $quantityWarnings = $this->handleQuantityChange(
                $originalRecord,
                $changes['quantity']['old'],
                $changes['quantity']['new']
            );
            $warnings = array_merge($warnings, $quantityWarnings);
        }
        // Handle area allocation changes ONLY if no quantity changes (to avoid double processing)
        elseif (isset($changes['area_allocations'])) {
            $areaWarnings = $this->handleAreaAllocationChanges(
                $originalRecord,
                $changes['area_allocations'],
                $newData
            );
            $warnings = array_merge($warnings, $areaWarnings);
        }

        // Price and discount changes don't affect stock/backorders
        // They're handled by normal WholesaleOut update logic

        return $warnings;
    }

    /**
     * Handle addition of a new record - normal stock allocation
     */
    private function handleRecordAddition(array $newRecordData, WholesaleOut $wholesaleOut): array
    {
        $warnings = [];

        $this->logInfo('Handling record addition for active WholesaleOut', [
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $newRecordData['record_id'] ?? 'unknown',
            'quantity' => $newRecordData['quantity'] ?? 'unknown',
        ]);

        // Find the newly created WholesaleOutRecord
        // First, refresh the wholesaleOut to get the latest data including new records
        $wholesaleOut->load('records.wholesaleOutRecordsArea');

        // Look for the record in the current WholesaleOut state
        $newRecord = $wholesaleOut->records()
            ->where('record_id', $newRecordData['record_id'])
            ->where('quantity', $newRecordData['quantity'])
            ->latest()
            ->first();

        if (! $newRecord) {
            // Try alternative lookup without quantity (in case quantity was updated)
            $newRecord = $wholesaleOut->records()
                ->where('record_id', $newRecordData['record_id'])
                ->latest()
                ->first();
        }

        if (! $newRecord) {
            // Final fallback: direct database query
            $newRecord = WholesaleOutRecord::where('wholesale_out_id', $wholesaleOut->id)
                ->where('record_id', $newRecordData['record_id'])
                ->latest()
                ->first();
        }

        if (! $newRecord) {
            $warnings[] = 'Could not find newly created record for stock allocation';
            $this->logError('Failed to find newly created WholesaleOutRecord', [
                'wholesale_out_id' => $wholesaleOut->id,
                'record_id' => $newRecordData['record_id'],
                'quantity' => $newRecordData['quantity'],
            ]);

            return $warnings;
        }

        // Allocate stock for the new record using intended area assignments
        $allocatedQuantity = $this->allocateStockForRecord($newRecord, $wholesaleOut, $newRecordData);

        // Update shipped_quantity to reflect what was actually allocated
        $newRecord->shipped_quantity = $allocatedQuantity;
        $newRecord->save();

        if ($allocatedQuantity < $newRecord->quantity) {
            $backorderQuantity = $newRecord->quantity - $allocatedQuantity;
            $this->createBackorderForRecord($newRecord, $backorderQuantity);
            $warnings[] = "Insufficient stock for new record. Created backorder for {$backorderQuantity} units.";
        }

        $this->logInfo('Stock allocation completed for new record', [
            'record_id' => $newRecord->id,
            'requested' => $newRecord->quantity,
            'allocated' => $allocatedQuantity,
            'shipped_quantity' => $allocatedQuantity,
            'backorder' => $newRecord->quantity - $allocatedQuantity,
        ]);

        return $warnings;
    }

    /**
     * Allocate stock for a new record - returns the quantity actually allocated
     */
    private function allocateStockForRecord(WholesaleOutRecord $record, WholesaleOut $wholesaleOut, ?array $newRecordData = null): int
    {
        $recordId = $record->record_id;
        $requestedQuantity = $record->quantity;

        if ($requestedQuantity <= 0) {
            $this->logWarning('Attempted to allocate zero or negative quantity', [
                'record_id' => $record->id,
                'requested_quantity' => $requestedQuantity,
            ]);

            return 0;
        }

        // Check if area assignments already exist (created by controller)
        $existingAssignments = $record->wholesaleOutRecordsArea;
        if ($existingAssignments->count() > 0) {
            $this->logInfo('Area assignments already exist - performing stock allocation for existing assignments', [
                'record_id' => $record->id,
                'existing_assignments_count' => $existingAssignments->count(),
                'total_assigned_quantity' => $existingAssignments->sum('quantity'),
            ]);

            // Allocate stock for existing area assignments
            $totalAllocated = 0;
            $unallocatedQuantity = 0;

            foreach ($existingAssignments as $assignment) {
                $stock = \App\Models\Stock::where('record_id', $record->record_id)
                    ->where('area_id', $assignment->area_id)
                    ->first();

                if ($stock && $stock->quantity >= $assignment->quantity) {
                    $stock->decrement('quantity', $assignment->quantity);
                    $totalAllocated += $assignment->quantity;

                    $this->logInfo('Stock allocated for existing assignment', [
                        'record_id' => $record->record_id,
                        'area_id' => $assignment->area_id,
                        'allocated' => $assignment->quantity,
                        'remaining_stock' => $stock->quantity,
                    ]);
                } else {
                    // Partial or zero allocation from assigned area
                    $availableInArea = $stock ? $stock->quantity : 0;
                    if ($availableInArea > 0) {
                        $stock->decrement('quantity', $availableInArea);
                        $totalAllocated += $availableInArea;
                        $unallocatedQuantity += ($assignment->quantity - $availableInArea);

                        // Update assignment to reflect actual partial allocation
                        $assignment->update(['quantity' => $availableInArea]);

                        $this->logInfo('Partial stock allocated from assigned area', [
                            'record_id' => $record->record_id,
                            'area_id' => $assignment->area_id,
                            'allocated' => $availableInArea,
                            'still_needed' => ($assignment->quantity - $availableInArea),
                        ]);
                    } else {
                        // No stock available - keep assignment at 0 to show intent
                        $unallocatedQuantity += $assignment->quantity;
                        $assignment->update(['quantity' => 0]);

                        $this->logInfo('No stock available in assigned area - assignment set to 0', [
                            'record_id' => $record->record_id,
                            'area_id' => $assignment->area_id,
                            'required' => $assignment->quantity,
                            'will_become_backorder' => true,
                        ]);
                    }
                }
            }

            // DO NOT allocate from other areas when user has specified area assignments
            // Unallocated quantity should become backorders, not arbitrary area assignments
            if ($unallocatedQuantity > 0) {
                $this->logInfo('Unallocated quantity will become backorders - no arbitrary area selection', [
                    'record_id' => $record->record_id,
                    'unallocated_quantity' => $unallocatedQuantity,
                    'message' => 'User-specified areas must be respected, shortfall becomes backorders',
                ]);
            }

            return $totalAllocated;
        }

        // Check for specific area assignments from UI data first
        $intendedAreas = null;
        if ($newRecordData && isset($newRecordData['area_quantities']) && ! empty($newRecordData['area_quantities'])) {
            $intendedAreas = $newRecordData['area_quantities'];
            $this->logInfo('Using intended area assignments from UI', [
                'record_id' => $record->id,
                'intended_areas' => $intendedAreas,
            ]);
        }

        // If we have specific area assignments, use them exclusively
        if ($intendedAreas) {
            return $this->allocateStockFromSpecificAreas($record, $intendedAreas);
        }

        // Fallback to original logic for records without specific assignments
        $areaId = $this->determineAreaForRecord($record, $wholesaleOut);

        // Find available stock for the determined area (could be assigned area or default area)
        $stock = \App\Models\Stock::where('record_id', $recordId)
            ->where('area_id', $areaId)
            ->first();

        $availableQuantity = 0;
        if ($stock && $stock->quantity > 0) {
            $availableQuantity = $stock->quantity;
        }

        // Always allocate what we can from the assigned/default area
        $allocatedQuantity = min($requestedQuantity, $availableQuantity);

        if ($allocatedQuantity > 0) {
            $stock->decrement('quantity', $allocatedQuantity);
            $this->logInfo('Stock allocated from area', [
                'record_id' => $recordId,
                'area_id' => $areaId,
                'allocated' => $allocatedQuantity,
                'remaining_stock' => $stock->quantity,
            ]);
        }

        // Update or create area assignment to reflect actual allocation (not requested quantity)
        // This corrects any incorrect area assignments created by the controller
        $existingAssignment = $record->wholesaleOutRecordsArea()->where('area_id', $areaId)->first();

        if ($allocatedQuantity > 0) {
            if ($existingAssignment) {
                // Update existing assignment to actual allocated quantity
                $existingAssignment->update(['quantity' => $allocatedQuantity]);
                $this->logInfo('Updated area assignment to actual allocated quantity', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                    'previous_quantity' => $existingAssignment->getOriginal('quantity'),
                    'actual_allocated' => $allocatedQuantity,
                ]);
            } else {
                // Create new area assignment with actual allocated quantity
                $record->wholesaleOutRecordsArea()->create([
                    'area_id' => $areaId,
                    'quantity' => $allocatedQuantity,
                ]);
            }
        } else {
            // No stock was allocated, remove any incorrect area assignment
            if ($existingAssignment) {
                $existingAssignment->delete();
                $this->logInfo('Removed area assignment - no stock allocated', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                ]);
            }
        }

        $this->logInfo('Area allocation completed', [
            'record_id' => $recordId,
            'target_area_id' => $areaId,
            'requested' => $requestedQuantity,
            'allocated' => $allocatedQuantity,
            'will_need_backorder' => ($requestedQuantity - $allocatedQuantity),
        ]);

        return $allocatedQuantity;
    }

    /**
     * Allocate stock from specific areas as defined in area_quantities
     */
    private function allocateStockFromSpecificAreas(WholesaleOutRecord $record, array $intendedAreas): int
    {
        $recordId = $record->record_id;
        $totalAllocated = 0;

        $this->logInfo('Allocating stock from specific areas', [
            'record_id' => $record->id,
            'intended_areas' => $intendedAreas,
        ]);

        foreach ($intendedAreas as $areaAllocation) {
            $areaId = $areaAllocation['area_id'];
            $requestedQuantity = $areaAllocation['quantity'];

            if ($requestedQuantity <= 0) {
                continue;
            }

            // Find available stock in this specific area
            $stock = \App\Models\Stock::where('record_id', $recordId)
                ->where('area_id', $areaId)
                ->first();

            if (! $stock) {
                // No stock record exists for this area - create assignment with 0 to preserve user intent
                \App\Models\WholesaleOutRecordsArea::create([
                    'wholesale_out_records_id' => $record->id,
                    'area_id' => $areaId,
                    'quantity' => 0,
                ]);

                $this->logWarning('No stock record in specified area - assignment created with 0', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                    'requested_quantity' => $requestedQuantity,
                    'will_become_backorder' => true,
                ]);

                continue;
            }

            $availableQuantity = max(0, $stock->quantity);
            $allocatedQuantity = min($requestedQuantity, $availableQuantity);

            if ($allocatedQuantity > 0) {
                $stock->decrement('quantity', $allocatedQuantity);

                // Update or create area allocation record with actual allocated quantity
                $existingAssignment = $record->wholesaleOutRecordsArea()->where('area_id', $areaId)->first();

                if ($existingAssignment) {
                    $existingAssignment->update(['quantity' => $allocatedQuantity]);
                } else {
                    \App\Models\WholesaleOutRecordsArea::create([
                        'wholesale_out_records_id' => $record->id,
                        'area_id' => $areaId,
                        'quantity' => $allocatedQuantity,
                    ]);
                }

                $totalAllocated += $allocatedQuantity;

                $this->logInfo('Stock allocated from specific area', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                    'requested' => $requestedQuantity,
                    'allocated' => $allocatedQuantity,
                    'remaining_stock' => $stock->quantity,
                ]);
            } else {
                // No stock allocated - create/update assignment with quantity=0 to preserve user intent
                // This shows the user wanted this area but stock wasn't available (will become backorder)
                $existingAssignment = $record->wholesaleOutRecordsArea()->where('area_id', $areaId)->first();
                if ($existingAssignment) {
                    $existingAssignment->update(['quantity' => 0]);
                } else {
                    \App\Models\WholesaleOutRecordsArea::create([
                        'wholesale_out_records_id' => $record->id,
                        'area_id' => $areaId,
                        'quantity' => 0,
                    ]);
                }

                $this->logWarning('No stock available in specified area - assignment set to 0', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                    'requested' => $requestedQuantity,
                    'available' => $availableQuantity,
                    'will_become_backorder' => true,
                ]);
            }
        }

        $this->logInfo('Specific area allocation completed', [
            'record_id' => $record->id,
            'total_allocated' => $totalAllocated,
            'requested_total' => $record->quantity,
        ]);

        return $totalAllocated;
    }

    /**
     * Create backorder for insufficient stock
     */
    private function createBackorderForRecord(WholesaleOutRecord $record, int $backorderQuantity, ?int $specificAreaId = null): void
    {
        if ($backorderQuantity <= 0) {
            return; // No backorder needed
        }

        $wholesaleOut = $record->wholesaleOut;

        // Use specific area ID if provided, otherwise determine from record
        $areaId = $specificAreaId ?? $this->determineAreaForRecord($record, $wholesaleOut);

        $this->logInfo('Creating backorder for record', [
            'record_id' => $record->id,
            'wholesale_out_id' => $wholesaleOut->id,
            'area_id' => $areaId,
            'backorder_quantity' => $backorderQuantity,
            'specific_area_provided' => $specificAreaId !== null,
        ]);

        // Reuse the existing logic from controller
        $this->createBackorderForMissingStock($wholesaleOut, $record, $areaId, $backorderQuantity);
    }

    /**
     * Create a backorder for missing stock (replicates controller logic)
     */
    private function createBackorderForMissingStock(\App\Models\WholesaleOut $wholesaleOut, \App\Models\WholesaleOutRecord $wholesaleOutRecord, int $areaId, int $missingQuantity): void
    {
        // Only create backorders for active wholesale outs
        if ($wholesaleOut->status !== 1) {
            return;
        }

        // Check if a backorder already exists for this wholesale out
        $backorder = $wholesaleOut->backorders()->where('status', 0)->first(); // 0 = pending

        if (! $backorder) {
            // Create new backorder
            $backorder = $wholesaleOut->backorders()->create([
                'status' => 0, // 0 = pending
            ]);
        }

        // Check if a backorder record already exists for this wholesale out record
        $backorderRecord = $backorder->backorderRecords()
            ->where('wholesale_out_record_id', $wholesaleOutRecord->id)
            ->first();

        if (! $backorderRecord) {
            // Create new backorder record
            $backorderRecord = $backorder->backorderRecords()->create([
                'wholesale_out_record_id' => $wholesaleOutRecord->id,
                'quantity' => $missingQuantity,
            ]);
        } else {
            // Update existing backorder record quantity
            $backorderRecord->increment('quantity', $missingQuantity);
        }

        // Check if area record already exists
        $backorderRecordsArea = $backorderRecord->backorderRecordsAreas()
            ->where('area_id', $areaId)
            ->first();

        if (! $backorderRecordsArea) {
            // Create new area record
            $backorderRecord->backorderRecordsAreas()->create([
                'area_id' => $areaId,
                'quantity' => $missingQuantity,
            ]);
        } else {
            // Update existing area record quantity
            $backorderRecordsArea->increment('quantity', $missingQuantity);
        }

        $this->logInfo('Backorder created for missing stock', [
            'wholesale_out_id' => $wholesaleOut->id,
            'record_id' => $wholesaleOutRecord->record_id,
            'area_id' => $areaId,
            'quantity' => $missingQuantity,
        ]);
    }

    /**
     * Clean up empty backorders that have no backorder records
     */
    private function cleanupEmptyBackorders(\App\Models\WholesaleOut $wholesaleOut): void
    {
        $emptyBackorders = $wholesaleOut->backorders()
            ->whereDoesntHave('backorderRecords')
            ->get();

        foreach ($emptyBackorders as $emptyBackorder) {
            $this->logInfo('Deleting empty backorder', [
                'backorder_id' => $emptyBackorder->id,
                'wholesale_out_id' => $wholesaleOut->id,
            ]);
            $emptyBackorder->delete();
        }
    }

    /**
     * Determine which area to use for stock allocation
     * Now properly handles multi-area scenarios
     */
    private function determineAreaForRecord(\App\Models\WholesaleOutRecord $record, \App\Models\WholesaleOut $wholesaleOut): int
    {
        // First priority: Check if record has specific area assignments
        $recordAreas = $record->wholesaleOutRecordsArea;
        if ($recordAreas->isNotEmpty()) {
            // For multi-area records, use the first area or largest allocation
            $largestAllocation = $recordAreas->sortByDesc('quantity')->first();

            return $largestAllocation->area_id;
        }

        // Second priority: Use WholesaleOut default area
        if ($wholesaleOut->area_id) {
            $this->logInfo('Using WholesaleOut default area (no stock available)', [
                'record_id' => $record->record_id,
                'default_area_id' => $wholesaleOut->area_id,
            ]);

            return $wholesaleOut->area_id;
        }

        // Fallback: area "In sospeso" (id 1), hard-coded on the client's request (should rarely happen)
        $this->logWarning('Using system fallback area for record allocation', [
            'record_id' => $record->id,
            'wholesale_out_id' => $wholesaleOut->id,
        ]);

        return 1;
    }

    /**
     * Handle quantity changes for a record
     */
    private function handleQuantityChange(WholesaleOutRecord $record, int $oldQuantity, int $newQuantity): array
    {
        $warnings = [];
        $quantityDifference = $newQuantity - $oldQuantity;

        $this->logInfo('Handling quantity change', [
            'record_id' => $record->id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'difference' => $quantityDifference,
        ]);

        if ($quantityDifference < 0) {
            // Quantity decreased - handle reallocation
            $decreaseWarnings = $this->handleQuantityDecrease($record, $oldQuantity, $newQuantity);
            $warnings = array_merge($warnings, $decreaseWarnings);
        } elseif ($quantityDifference > 0) {
            // Quantity increased - handle reallocation
            $increaseWarnings = $this->handleQuantityIncrease($record, $oldQuantity, $newQuantity);
            $warnings = array_merge($warnings, $increaseWarnings);
        }

        return $warnings;
    }

    /**
     * Handle quantity decrease - restore excess stock
     * SIMPLIFIED: Only handle the incremental decrease
     */
    private function handleQuantityDecrease(WholesaleOutRecord $record, int $oldQuantity, int $newQuantity): array
    {
        $warnings = [];
        $reductionAmount = $oldQuantity - $newQuantity;

        $this->logInfo('Handling quantity decrease', [
            'record_id' => $record->id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'reduction_amount' => $reductionAmount,
        ]);

        // Strategy: Cancel backorders first (LIFO), then restore stock if needed

        // Step 1: Get current backorder quantity
        $currentBackorders = $record->backorderRecords()->sum('quantity');

        if ($currentBackorders > 0) {
            // We have backorders - cancel them first using LIFO strategy
            $backorderReduction = min($reductionAmount, $currentBackorders);

            if ($backorderReduction > 0) {
                // Use LIFO cancellation (Last In, First Out)
                $cancelWarnings = $this->cancelBackordersLIFO($record, $backorderReduction);
                $warnings = array_merge($warnings, $cancelWarnings);
                $reductionAmount -= $backorderReduction;

                $this->logInfo('Cancelled backorders using LIFO for quantity decrease', [
                    'record_id' => $record->id,
                    'cancelled_backorders' => $backorderReduction,
                    'remaining_reduction' => $reductionAmount,
                ]);
            }
        }

        // Step 2: If we still need to reduce more, restore stock
        if ($reductionAmount > 0) {
            $areaId = $this->determineAreaForRecord($record, $record->wholesaleOut);
            $stock = Stock::where('record_id', $record->record_id)
                ->where('area_id', $areaId)
                ->first();

            if ($stock) {
                $stock->increment('quantity', $reductionAmount);

                $this->logInfo('Restored stock for quantity decrease', [
                    'record_id' => $record->record_id,
                    'area_id' => $areaId,
                    'restored_quantity' => $reductionAmount,
                    'new_stock_quantity' => $stock->quantity,
                ]);
            }
        }

        // Step 3: Update area assignment to reflect actual allocated quantity
        // Actual allocated = newQuantity - remaining backorders
        $record->refresh(); // Refresh to get updated backorder count
        $record->load('wholesaleOutRecordsArea'); // Force reload area assignments from database
        $remainingBackorders = $record->backorderRecords()->sum('quantity');
        $actualAllocated = $newQuantity - $remainingBackorders;

        $areaAssignment = $record->wholesaleOutRecordsArea()->first();
        if ($areaAssignment) {
            if ($actualAllocated > 0) {
                $areaAssignment->update(['quantity' => $actualAllocated]);
                $this->logInfo('Updated area assignment after quantity decrease', [
                    'record_id' => $record->id,
                    'new_quantity' => $newQuantity,
                    'remaining_backorders' => $remainingBackorders,
                    'actual_allocated' => $actualAllocated,
                ]);
            } else {
                $areaAssignment->delete();
                $this->logInfo('Removed area assignment - no stock allocated', [
                    'record_id' => $record->id,
                ]);
            }
        }

        // Step 4: Update shipped_quantity to reflect the new allocated amount
        // This is critical for defensive validation to work correctly
        $record->update(['shipped_quantity' => $actualAllocated]);
        $this->logInfo('Updated shipped_quantity after quantity decrease', [
            'record_id' => $record->id,
            'new_shipped_quantity' => $actualAllocated,
        ]);

        return $warnings;
    }

    /**
     * Handle quantity increase - restore stock then reallocate fresh
     * CONSISTENT with area change logic: restore first, then reallocate
     */
    private function handleQuantityIncrease(WholesaleOutRecord $record, int $oldQuantity, int $newQuantity): array
    {
        $warnings = [];

        $this->logInfo('Handling quantity increase', [
            'record_id' => $record->id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
        ]);

        // STEP 1: Restore stock from current allocations
        $restoreWarnings = $this->restoreStockFromCurrentAllocations($record);
        $warnings = array_merge($warnings, $restoreWarnings);

        // STEP 2: Cancel all existing backorders
        $cancelWarnings = $this->cancelAllBackordersForRecord($record);
        $warnings = array_merge($warnings, $cancelWarnings);

        // STEP 3: Capture the original area BEFORE deleting assignments
        // We need to reallocate from the SAME area where stock was originally allocated
        $originalAreaAssignment = $record->wholesaleOutRecordsArea()->first();
        $targetAreaId = $originalAreaAssignment ? $originalAreaAssignment->area_id : $record->wholesaleOut->area_id;

        $this->logInfo('Captured target area for reallocation', [
            'record_id' => $record->id,
            'target_area_id' => $targetAreaId,
            'from_assignment' => $originalAreaAssignment ? 'yes' : 'no (using WO default)',
        ]);

        // STEP 4: Clear existing area assignments
        $record->wholesaleOutRecordsArea()->delete();

        // STEP 5: Reallocate stock for the NEW quantity from the same area

        // Try to allocate from the target area
        $allocatedQuantity = 0;
        if ($targetAreaId) {
            $stock = \App\Models\Stock::where('record_id', $record->record_id)
                ->where('area_id', $targetAreaId)
                ->first();

            if ($stock && $stock->quantity >= $newQuantity) {
                // Full allocation
                $stock->decrement('quantity', $newQuantity);
                $allocatedQuantity = $newQuantity;

                $this->logInfo('Fully allocated new quantity', [
                    'record_id' => $record->record_id,
                    'area_id' => $targetAreaId,
                    'allocated' => $newQuantity,
                ]);
            } elseif ($stock && $stock->quantity > 0) {
                // Partial allocation
                $availableInArea = $stock->quantity;
                $stock->decrement('quantity', $availableInArea);
                $allocatedQuantity = $availableInArea;

                $this->logInfo('Partially allocated new quantity', [
                    'record_id' => $record->record_id,
                    'area_id' => $targetAreaId,
                    'allocated' => $availableInArea,
                    'requested' => $newQuantity,
                ]);
            }
        }

        // Create fresh area assignment if any stock was allocated
        if ($allocatedQuantity > 0) {
            $record->wholesaleOutRecordsArea()->create([
                'area_id' => $targetAreaId,
                'quantity' => $allocatedQuantity,
            ]);

            $this->logInfo('Created fresh area assignment', [
                'record_id' => $record->id,
                'area_id' => $targetAreaId,
                'quantity' => $allocatedQuantity,
            ]);
        }

        // Update shipped_quantity to reflect fresh allocation (NOT cumulative)
        $record->shipped_quantity = $allocatedQuantity;
        $record->save();

        // Create backorder for any unallocated quantity
        $backorderQuantity = $newQuantity - $allocatedQuantity;
        if ($backorderQuantity > 0) {
            $this->createBackorderForRecord($record, $backorderQuantity, $targetAreaId);
            if ($allocatedQuantity > 0) {
                $warnings[] = "Partial stock available. Allocated {$allocatedQuantity}, created backorder for {$backorderQuantity} units.";
            } else {
                $warnings[] = "No stock available. Created backorder for {$backorderQuantity} units.";
            }
        } else {
            $warnings[] = "Successfully allocated {$allocatedQuantity} units.";
        }

        $this->logInfo('Updated shipped_quantity after quantity increase (restore+reallocate)', [
            'record_id' => $record->id,
            'new_quantity' => $newQuantity,
            'shipped_quantity' => $allocatedQuantity,
            'backorder' => $backorderQuantity,
        ]);

        return $warnings;
    }

    /**
     * Cancel backorders using LIFO (Last In, First Out) strategy
     */
    private function cancelBackordersLIFO(WholesaleOutRecord $record, int $quantityToCancel): array
    {
        $warnings = [];
        $backorderRecords = $record->backorderRecords()
            ->orderBy('created_at', 'desc') // Newest first
            ->get();

        $remainingToCancel = $quantityToCancel;

        foreach ($backorderRecords as $backorderRecord) {
            if ($remainingToCancel <= 0) {
                break;
            }

            if ($backorderRecord->quantity <= $remainingToCancel) {
                // Cancel entire backorder record
                $remainingToCancel -= $backorderRecord->quantity;

                // Clean up related area records
                $backorderRecord->backorderRecordsAreas()->delete();
                $backorderRecord->delete();

                $this->logInfo('Cancelled entire backorder record', [
                    'backorder_record_id' => $backorderRecord->id,
                    'quantity' => $backorderRecord->quantity,
                ]);

                $warnings[] = "Cancelled backorder of {$backorderRecord->quantity} units due to quantity reduction";
            } else {
                // Partially cancel backorder record
                $oldQuantity = $backorderRecord->quantity;
                $newQuantity = $backorderRecord->quantity - $remainingToCancel;
                $backorderRecord->update(['quantity' => $newQuantity]);

                // Single-area architecture: Update the single area allocation to match new backorder quantity
                // In single-area system, there should be only one area allocation per backorder record
                $areaAllocation = $backorderRecord->backorderRecordsAreas()->first();

                if ($areaAllocation) {
                    if ($newQuantity > 0) {
                        $areaAllocation->update(['quantity' => $newQuantity]);
                    } else {
                        $areaAllocation->delete();
                    }

                    $this->logInfo('Updated single area allocation for partial backorder cancellation', [
                        'backorder_record_id' => $backorderRecord->id,
                        'area_id' => $areaAllocation->area_id,
                        'old_area_quantity' => $oldQuantity,
                        'new_area_quantity' => $newQuantity,
                    ]);
                }

                $this->logInfo('Partially cancelled backorder record', [
                    'backorder_record_id' => $backorderRecord->id,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $newQuantity,
                    'cancelled_quantity' => $remainingToCancel,
                ]);

                $warnings[] = "Reduced backorder from {$oldQuantity} to {$newQuantity} units";
                $remainingToCancel = 0;
            }
        }

        return $warnings;
    }

    /**
     * Restore stock for a record to its assigned area
     */
    private function restoreStockForRecord(WholesaleOutRecord $record, int $quantityToRestore): array
    {
        $warnings = [];

        if ($quantityToRestore <= 0) {
            $this->logInfo('No quantity to restore for record', [
                'record_id' => $record->id,
                'quantity_to_restore' => $quantityToRestore,
            ]);

            return $warnings;
        }

        // Restore stock to the exact area(s) that provided it, respecting user area choices
        // Use area allocations to determine where stock came from
        $areaAllocations = $record->wholesaleOutRecordsArea;

        if ($areaAllocations->isEmpty()) {
            // Fallback: restore to default area if no area assignments exist
            $areaId = $record->wholesaleOut->area_id;

            $stock = Stock::where('record_id', $record->record_id)
                ->where('area_id', $areaId)
                ->first();

            if ($stock) {
                $stock->increment('quantity', $quantityToRestore);
                $this->logInfo('Restored stock to default area (no area assignments)', [
                    'record_id' => $record->record_id,
                    'area_id' => $areaId,
                    'quantity_restored' => $quantityToRestore,
                ]);
            } else {
                $warnings[] = "Stock not found for record {$record->record_id} in default area {$areaId}";
            }

            return $warnings;
        }

        // Restore to the assigned area(s) - but only restore the actually decremented quantity
        // Since we only support single area assignments, there should be only one area
        $areaAllocation = $areaAllocations->first();

        $stock = Stock::where('record_id', $record->record_id)
            ->where('area_id', $areaAllocation->area_id)
            ->first();

        if ($stock) {
            $stock->increment('quantity', $quantityToRestore);

            $this->logInfo('Restored stock to assigned area', [
                'record_id' => $record->record_id,
                'area_id' => $areaAllocation->area_id,
                'quantity_restored' => $quantityToRestore,
                'area_allocation_quantity' => $areaAllocation->quantity,
                'note' => 'Restored actual decremented quantity, not area allocation quantity',
            ]);
        } else {
            $warnings[] = "Stock not found for record {$record->record_id} in assigned area {$areaAllocation->area_id}";
            $this->logWarning('Could not restore stock - stock record not found in assigned area', [
                'record_id' => $record->record_id,
                'area_id' => $areaAllocation->area_id,
                'quantity_to_restore' => $quantityToRestore,
            ]);
        }

        return $warnings;
    }

    /**
     * Restore stock from current area allocations (for quantity increase)
     * This uses the CURRENT wholesale_out_records_area data
     */
    private function restoreStockFromCurrentAllocations(WholesaleOutRecord $record): array
    {
        $warnings = [];

        $this->logInfo('Restoring stock from current allocations', [
            'record_id' => $record->id,
        ]);

        $areaAllocations = $record->wholesaleOutRecordsArea;
        $totalRestored = 0;

        foreach ($areaAllocations as $areaAllocation) {
            $quantityToRestore = $areaAllocation->quantity;

            if ($quantityToRestore <= 0) {
                continue;
            }

            $stock = Stock::where('record_id', $record->record_id)
                ->where('area_id', $areaAllocation->area_id)
                ->first();

            if ($stock) {
                $stock->increment('quantity', $quantityToRestore);
                $totalRestored += $quantityToRestore;

                $this->logInfo('Restored stock from current allocation', [
                    'record_id' => $record->record_id,
                    'area_id' => $areaAllocation->area_id,
                    'quantity_restored' => $quantityToRestore,
                ]);
            } else {
                $warnings[] = "Stock not found for record {$record->record_id} in area {$areaAllocation->area_id}";
                $this->logWarning('Could not restore stock - stock record not found', [
                    'record_id' => $record->record_id,
                    'area_id' => $areaAllocation->area_id,
                    'quantity_to_restore' => $quantityToRestore,
                ]);
            }
        }

        if ($totalRestored > 0) {
            $this->logInfo('Stock restoration completed', [
                'record_id' => $record->id,
                'total_restored' => $totalRestored,
            ]);
        }

        return $warnings;
    }

    /**
     * Restore stock based on area changes data (not current record state)
     */
    private function restoreStockFromAreaChanges(WholesaleOutRecord $record, array $areaChanges): array
    {
        $warnings = [];

        $this->logInfo('Restoring stock from area changes', [
            'record_id' => $record->id,
            'area_changes' => $areaChanges,
        ]);

        // Determine original areas from area changes
        $originalAreas = [];

        // From removed areas - these were fully allocated
        if (isset($areaChanges['removed'])) {
            foreach ($areaChanges['removed'] as $areaId => $quantity) {
                $originalAreas[$areaId] = $quantity;
            }
        }

        // From modified areas - use the old quantity
        if (isset($areaChanges['modified'])) {
            foreach ($areaChanges['modified'] as $areaId => $change) {
                $originalAreas[$areaId] = $change['old'];
            }
        }

        // Now restore stock to each original area
        $totalRestored = 0;
        foreach ($originalAreas as $areaId => $quantityToRestore) {
            if ($quantityToRestore <= 0) {
                continue;
            }

            $stock = Stock::where('record_id', $record->record_id)
                ->where('area_id', $areaId)
                ->first();

            if ($stock) {
                $stock->increment('quantity', $quantityToRestore);
                $totalRestored += $quantityToRestore;

                $this->logInfo('Restored stock from area changes', [
                    'record_id' => $record->record_id,
                    'area_id' => $areaId,
                    'quantity_restored' => $quantityToRestore,
                ]);
            } else {
                $warnings[] = "Stock not found for record {$record->record_id} in original area {$areaId}";
                $this->logWarning('Could not restore stock - stock record not found in original area', [
                    'record_id' => $record->record_id,
                    'area_id' => $areaId,
                    'quantity_to_restore' => $quantityToRestore,
                ]);
            }
        }

        if ($totalRestored > 0) {
            $this->logInfo('Total stock restoration completed', [
                'record_id' => $record->id,
                'total_restored' => $totalRestored,
                'original_areas_count' => count($originalAreas),
            ]);
        }

        return $warnings;
    }

    /**
     * Cancel all backorders for a record
     */
    private function cancelAllBackordersForRecord(WholesaleOutRecord $record): array
    {
        $warnings = [];
        $backorderRecords = $record->backorderRecords;
        $totalCancelled = $backorderRecords->sum('quantity');

        foreach ($backorderRecords as $backorderRecord) {
            // Delete area allocations first
            $backorderRecord->backorderRecordsAreas()->delete();
            $backorderRecord->delete();
        }

        if ($totalCancelled > 0) {
            $warnings[] = "Cancelled {$totalCancelled} backordered units from deleted record";

            $this->logInfo('Cancelled all backorders for deleted record', [
                'record_id' => $record->id,
                'total_cancelled' => $totalCancelled,
            ]);
        }

        return $warnings;
    }

    /**
     * Calculate actually decremented quantity (requested - backordered)
     */
    private function calculateActuallyDecrementedQuantity(WholesaleOutRecord $record): int
    {
        // Use area allocations to determine actual allocation (not requested quantity)
        $areaAllocations = $record->wholesaleOutRecordsArea()->get();
        $actuallyAllocated = $areaAllocations->sum('quantity');

        $this->logInfo('Calculated actually decremented quantity from area assignments', [
            'record_id' => $record->id,
            'actually_allocated' => $actuallyAllocated,
            'area_assignments_count' => $areaAllocations->count(),
        ]);

        return $actuallyAllocated;
    }

    /**
     * Handle area allocation changes
     */
    private function handleAreaAllocationChanges(WholesaleOutRecord $record, array $areaChanges, array $newData): array
    {
        $warnings = [];

        $this->logInfo('Handling area allocation changes for record', [
            'record_id' => $record->id,
            'changes' => $areaChanges,
        ]);

        // Area changes are handled by:
        // 1. Restoring stock to the old area(s)
        // 2. Re-allocating stock from the new area(s)
        // This approach maintains consistency with existing stock allocation logic

        // Get current quantity to reallocate
        $quantityToReallocate = $record->quantity;

        // 1. Restore stock for the old area allocations
        // Use the area changes data to determine the original allocations, not the current record state
        $restoreWarnings = $this->restoreStockFromAreaChanges($record, $areaChanges);
        $warnings = array_merge($warnings, $restoreWarnings);

        // 2. Cancel existing backorders for this record
        $cancelWarnings = $this->cancelAllBackordersForRecord($record);
        $warnings = array_merge($warnings, $cancelWarnings);

        // 3. Re-allocate stock using the new area configuration
        // Determine new area from the area changes data, not from current record state
        $newAreaId = $this->determineNewAreaFromChanges($areaChanges, $newData);

        $stock = Stock::where('record_id', $record->record_id)
            ->where('area_id', $newAreaId)
            ->first();

        // Clear existing area assignments - we'll create correct ones through allocation
        $record->wholesaleOutRecordsArea()->delete();

        // Reload from database to ensure fresh data for allocation
        $record->refresh();

        // Allocate stock using the new area configuration from newData
        // allocateStockForRecord will use allocateStockFromSpecificAreas internally if area_quantities provided
        $wholesaleOut = $record->wholesaleOut;
        $allocatedQuantity = $this->allocateStockForRecord($record, $wholesaleOut, $newData);

        // Update shipped_quantity to reflect what was actually allocated in the new area
        $record->shipped_quantity = $allocatedQuantity;
        $record->save();

        $backorderQuantity = $quantityToReallocate - $allocatedQuantity;

        if ($backorderQuantity > 0) {
            $this->createBackorderForRecord($record, $backorderQuantity, $newAreaId);
            $warnings[] = "Area change completed. Created backorder for {$backorderQuantity} units.";
        }

        if ($allocatedQuantity > 0) {
            $warnings[] = "Area change successful. Allocated {$allocatedQuantity} units.";
        }

        $this->logInfo('Area allocation change completed', [
            'record_id' => $record->id,
            'new_area_id' => $newAreaId,
            'allocated' => $allocatedQuantity,
            'shipped_quantity' => $allocatedQuantity,
            'backorder' => $backorderQuantity,
        ]);

        return $warnings;
    }

    /**
     * Determine the new area for allocation based on area changes
     */
    private function determineNewAreaFromChanges(array $areaChanges, array $newData): int
    {
        // Single-area architecture: Use the user-selected area or default area
        // NEVER arbitrarily select areas based on quantities

        // Priority 1: Use area from newData area_quantities (user selection)
        if (isset($newData['area_quantities']) && ! empty($newData['area_quantities'])) {
            // In single-area architecture, take the first (and only) area assignment
            $firstAreaQty = reset($newData['area_quantities']);
            if ($firstAreaQty && isset($firstAreaQty['area_id'])) {
                return (int) $firstAreaQty['area_id'];
            }
        }

        // Priority 2: Use area from area changes (existing assignment being modified)
        if (isset($areaChanges['added']) && ! empty($areaChanges['added'])) {
            // Take the first added area (single-area system)
            $firstAreaId = array_key_first($areaChanges['added']);

            return (int) $firstAreaId;
        }

        if (isset($areaChanges['modified']) && ! empty($areaChanges['modified'])) {
            // Take the first modified area (single-area system)
            $firstAreaId = array_key_first($areaChanges['modified']);

            return (int) $firstAreaId;
        }

        // Fallback: area "pending" (id 23), hard-coded on the client's request
        return 23;
    }

    /**
     * Defensive validation of area assignments after reconciliation
     *
     * This method serves as a SAFETY NET to catch bugs in operation handlers.
     * Under normal operation, this should NOT make any corrections - all area
     * assignments should already be correct after individual operation handlers.
     *
     * If this method corrects anything, it indicates a BUG in an operation handler
     * that should be investigated and fixed.
     *
     * Purpose: Detect edge cases and operation handler bugs through validation
     */
    private function validateAndCorrectAllAreaAssignments(WholesaleOut $wholesaleOut): array
    {
        $warnings = [];
        $correctionsCount = 0;

        $this->logInfo('Starting defensive area assignment validation', [
            'wholesale_out_id' => $wholesaleOut->id,
            'records_count' => $wholesaleOut->records->count(),
        ]);

        foreach ($wholesaleOut->records as $record) {
            $recordWarnings = $this->validateAreaAssignmentsForRecord($record);
            if (! empty($recordWarnings)) {
                $correctionsCount++;
            }
            $warnings = array_merge($warnings, $recordWarnings);
        }

        if ($correctionsCount > 0) {
            $this->logError('⚠️ DEFENSIVE VALIDATION MADE CORRECTIONS - INDICATES OPERATION HANDLER BUG', [
                'wholesale_out_id' => $wholesaleOut->id,
                'records_corrected' => $correctionsCount,
                'total_corrections' => count($warnings),
                'message' => 'This should not happen - investigate operation handlers',
            ]);
        } else {
            $this->logInfo('✓ Defensive validation passed - no corrections needed', [
                'wholesale_out_id' => $wholesaleOut->id,
                'records_validated' => $wholesaleOut->records->count(),
            ]);
        }

        return $warnings;
    }

    /**
     * Validate area assignments for a single record
     * Returns warnings ONLY if corrections were necessary (indicates bugs)
     */
    private function validateAreaAssignmentsForRecord(WholesaleOutRecord $record): array
    {
        $warnings = [];

        // CRITICAL: Refresh record to get latest quantity from database.
        // Controller updates record quantities before calling reconciliation, but this model
        // instance was loaded earlier and has cached $record->quantity value.
        // Without refresh(), we'd compare against stale quantity, causing incorrect corrections.
        $record->refresh();

        // Also reload relationships to get fresh backorder data
        // The reconciliation service may have created/deleted backorders for this record,
        // so we need fresh data from the database, not cached relationships
        $record->load('backorderRecords');

        $requestedQuantity = $record->quantity;
        $shippedQuantity = $record->shipped_quantity;

        // Expected allocated should match shipped_quantity, NOT (requested - backorder)
        // This is because:
        // 1. shipped_quantity tracks actual stock decremented
        // 2. Backorders may be deleted globally (activated BOs) without the record being modified
        // 3. The record may not have a backorder created yet if it wasn't modified
        $expectedAllocated = $shippedQuantity;

        // CRITICAL: Force fresh query to avoid cached relationships
        $areaAssignments = $record->wholesaleOutRecordsArea()->get();
        $actualAssignedQuantity = $areaAssignments->sum('quantity');
        $assignmentCount = $areaAssignments->count();

        // VALIDATION 1: Quantity mismatch (operation handler bug)
        // Area assignments should sum to shipped_quantity
        if ($actualAssignedQuantity !== $expectedAllocated) {
            $this->logError('🐛 BUG DETECTED: Area assignment quantity mismatch', [
                'record_id' => $record->id,
                'requested' => $requestedQuantity,
                'shipped_quantity' => $shippedQuantity,
                'expected_assigned' => $expectedAllocated,
                'expected_assigned' => $expectedAllocated,
                'actual_assigned' => $actualAssignedQuantity,
                'mismatch' => $actualAssignedQuantity - $expectedAllocated,
            ]);

            // Defensive correction
            if ($expectedAllocated > 0) {
                $primaryAssignment = $areaAssignments->first();
                if ($primaryAssignment) {
                    $oldQuantity = $primaryAssignment->quantity;
                    $primaryAssignment->update(['quantity' => $expectedAllocated]);
                    $warnings[] = "🐛 CORRECTED quantity mismatch for record {$record->id}: {$oldQuantity} → {$expectedAllocated}";
                } else {
                    // Missing assignment entirely
                    $defaultAreaId = $record->wholesaleOut->area_id;
                    $record->wholesaleOutRecordsArea()->create([
                        'area_id' => $defaultAreaId,
                        'quantity' => $expectedAllocated,
                    ]);
                    $warnings[] = "🐛 CREATED missing area assignment for record {$record->id}";
                }
            } elseif ($actualAssignedQuantity > 0) {
                // expectedAllocated = 0 but actualAssignedQuantity > 0
                // This is a real bug - should not have any non-zero assignments
                // Keep assignments with quantity=0 (they show intent when everything is backordered)
                foreach ($areaAssignments as $assignment) {
                    if ($assignment->quantity > 0) {
                        $assignment->delete();
                        $warnings[] = "🐛 REMOVED incorrect area assignment for record {$record->id} (area {$assignment->area_id}, qty {$assignment->quantity})";
                    }
                }
            }
        }

        // VALIDATION 2: Multiple assignments in single-area architecture (data integrity issue)
        if ($assignmentCount > 1) {
            $this->logError('🐛 BUG DETECTED: Multiple area assignments in single-area system', [
                'record_id' => $record->id,
                'assignment_count' => $assignmentCount,
                'areas' => $areaAssignments->pluck('area_id')->toArray(),
            ]);

            // Keep first, remove others
            $extraAssignments = $areaAssignments->skip(1);
            foreach ($extraAssignments as $extraAssignment) {
                $extraAssignment->delete();
                $warnings[] = "🐛 REMOVED extra area assignment for record {$record->id} (area {$extraAssignment->area_id})";
            }
        }

        // VALIDATION 3: Bypass scenario detection (no backorders but also no allocation)
        $assignedAreaId = $areaAssignments->first()?->area_id ?? $record->wholesaleOut->area_id;
        $availableStock = \App\Models\Stock::where('record_id', $record->record_id)
            ->where('area_id', $assignedAreaId)
            ->first()?->quantity ?? 0;

        // Area assignments without stock and without backorders are valid (the stock may have been
        // consumed, or the WholesaleOut created while stock was unavailable): they represent user
        // intent and are preserved

        // If we made corrections, log the final state for audit
        if (! empty($warnings)) {
            $this->logDetail('Post-correction state for record', [
                'record_id' => $record->id,
                'requested' => $requestedQuantity,
                'shipped_quantity' => $shippedQuantity,
                'assigned' => $record->wholesaleOutRecordsArea()->sum('quantity'),
                'corrections_made' => count($warnings),
            ]);
        }

        return $warnings;
    }

    /**
     * Handle combined quantity and area allocation changes (A2 scenario)
     * When both quantity and area change, we need special handling to avoid double processing
     */
    private function handleCombinedQuantityAndAreaChanges(WholesaleOutRecord $record, array $changes, array $newData): array
    {
        $warnings = [];

        $this->logInfo('Handling combined quantity and area changes', [
            'record_id' => $record->id,
            'quantity_change' => $changes['quantity'],
            'area_changes' => $changes['area_allocations'],
        ]);

        // For combined changes, we treat it as a complete reallocation:
        // 1. Restore stock from the original area(s) for the original quantity
        // 2. Cancel all existing backorders
        // 3. Allocate stock to the new area(s) for the new quantity

        $originalQuantity = $changes['quantity']['old'];
        $newQuantity = $changes['quantity']['new'];

        // Step 1: Restore stock from original area assignments for original quantity
        $restoreWarnings = $this->restoreStockFromAreaChanges($record, $changes['area_allocations']);
        $warnings = array_merge($warnings, $restoreWarnings);

        // Step 2: Cancel all existing backorders
        $cancelWarnings = $this->cancelAllBackordersForRecord($record);
        $warnings = array_merge($warnings, $cancelWarnings);

        // Step 3: Clear existing area assignments and allocate fresh for new configuration
        $record->wholesaleOutRecordsArea()->delete();

        // Refresh the record to clear the relationship cache
        $record->refresh();

        // Step 4: Use new area configuration from newData to allocate for new quantity
        // allocateStockForRecord will use allocateStockFromSpecificAreas internally if area_quantities provided
        $wholesaleOut = $record->wholesaleOut;
        $allocatedQuantity = $this->allocateStockForRecord($record, $wholesaleOut, $newData);
        $backorderQuantity = $newQuantity - $allocatedQuantity;

        // Update shipped_quantity to reflect what was actually allocated with the new configuration
        $record->shipped_quantity = $allocatedQuantity;
        $record->save();

        if ($backorderQuantity > 0) {
            // Determine target area from new data
            $targetAreaId = $this->determineNewAreaFromChanges($changes['area_allocations'], $newData);
            $this->createBackorderForRecord($record, $backorderQuantity, $targetAreaId);

            if ($allocatedQuantity > 0) {
                $warnings[] = "Combined change completed. Allocated {$allocatedQuantity}, created backorder for {$backorderQuantity} units.";
            } else {
                $warnings[] = "Combined change completed. No stock available, created backorder for {$newQuantity} units.";
            }
        } else {
            $warnings[] = "Combined change successful. Allocated {$allocatedQuantity} units to new area.";
        }

        $this->logInfo('Combined quantity and area change completed', [
            'record_id' => $record->id,
            'old_quantity' => $originalQuantity,
            'new_quantity' => $newQuantity,
            'allocated' => $allocatedQuantity,
            'shipped_quantity' => $allocatedQuantity,
            'backorder' => $backorderQuantity,
        ]);

        return $warnings;
    }

    /**
     * Restore stock from an activated backorder
     *
     * This method restores stock that was physically decremented when a backorder was activated.
     * It's called before deleting activated backorders to ensure stock accuracy.
     *
     * Note: This restoration is based on current backorder_records_areas data, which may have
     * changed since activation. A full history table would be needed for exact restoration.
     *
     * @param  \App\Models\Backorder  $activatedBackorder  The activated backorder to restore stock from
     */
    public function restoreStockFromActivatedBackorder(\App\Models\Backorder $activatedBackorder): void
    {
        $this->logInfo('Restoring stock from activated backorder', [
            'backorder_id' => $activatedBackorder->id,
            'wholesale_out_id' => $activatedBackorder->wholesale_out_id,
        ]);

        // Load backorder records with their area allocations
        $backorderRecords = $activatedBackorder->backorderRecords()
            ->with('backorderRecordsAreas')
            ->get();

        foreach ($backorderRecords as $backorderRecord) {
            $shippedQuantity = $backorderRecord->shipped_quantity;

            if ($shippedQuantity <= 0) {
                // No stock was actually allocated for this backorder record
                continue;
            }

            // Get the wholesale out record to know which physical record we're dealing with
            $wholesaleOutRecord = $backorderRecord->wholesaleOutRecord;
            if (! $wholesaleOutRecord) {
                $this->logWarning('WholesaleOutRecord not found for BackorderRecord', [
                    'backorder_record_id' => $backorderRecord->id,
                ]);

                continue;
            }

            $recordId = $wholesaleOutRecord->record_id;

            // Restore stock to each area based on backorder_records_areas
            foreach ($backorderRecord->backorderRecordsAreas as $backorderArea) {
                $areaId = $backorderArea->area_id;
                $quantityToRestore = $backorderArea->quantity;

                if ($quantityToRestore <= 0) {
                    continue;
                }

                // Find or create the stock record
                $stock = Stock::where('record_id', $recordId)
                    ->where('area_id', $areaId)
                    ->first();

                if ($stock) {
                    $stock->increment('quantity', $quantityToRestore);

                    $this->logInfo('Restored stock from activated backorder', [
                        'backorder_record_id' => $backorderRecord->id,
                        'record_id' => $recordId,
                        'area_id' => $areaId,
                        'quantity_restored' => $quantityToRestore,
                        'new_stock_quantity' => $stock->quantity,
                    ]);
                } else {
                    $this->logWarning('Stock record not found for restoration', [
                        'backorder_record_id' => $backorderRecord->id,
                        'record_id' => $recordId,
                        'area_id' => $areaId,
                        'quantity_to_restore' => $quantityToRestore,
                        'note' => 'Stock may have been moved or deleted',
                    ]);
                }
            }
        }
    }
}
