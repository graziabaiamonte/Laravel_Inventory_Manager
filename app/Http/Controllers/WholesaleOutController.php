<?php

namespace App\Http\Controllers;

use App\Enums\LocationTypeEnum;
use App\Enums\RecordTypeEnum;
use App\Exports\WholesaleOutExport;
use App\Exports\WholesaleOutTemplateExport;
use App\Http\Requests\WholesaleOutRequest;
use App\Http\Resources\ComboResource;
use App\Http\Resources\WholesaleOutResource;
use App\Imports\WholesaleOutImport;
use App\Models\Area;
use App\Models\Backorder;
use App\Models\Customer;
use App\Models\Label;
use App\Models\Location;
use App\Models\SaleRecord;
use App\Models\Stock;
use App\Models\WholesaleOut;
use App\Models\WholesaleOutRecord;
use App\Models\WholesaleOutRecordsArea;
use App\Services\ActiveWholesaleOutReconciliationService;
use App\Services\WholesaleOutChangeDetector;
use App\Traits\LogsToChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class WholesaleOutController extends Controller
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'wholesale_out';
    }

    protected WholesaleOutChangeDetector $changeDetector;

    protected ActiveWholesaleOutReconciliationService $reconciliationService;

    public function __construct(
        WholesaleOutChangeDetector $changeDetector,
        ActiveWholesaleOutReconciliationService $reconciliationService
    ) {
        $this->changeDetector = $changeDetector;
        $this->reconciliationService = $reconciliationService;
    }

    /**
     * Handle file upload and import processing for both store and update operations
     */
    private function processFileImport(WholesaleOutRequest $request, ?int $defaultAreaId = null): array
    {
        $importError = [];
        $importedRecords = [];

        try {
            $import = new WholesaleOutImport($defaultAreaId);
            Excel::import($import, $request->file('file'));
            $importedRecords = $import->getRecords();

            // [ADD] Stock amount in "warehouse" type locations only for preview import
            $recordIds = collect($importedRecords)
                ->pluck('record_id')
                ->filter(fn ($v) => filled($v))
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values();

            if ($recordIds->isNotEmpty()) {
                $sumsByRecord = Stock::query()
                    ->whereIn('stocks.record_id', $recordIds)
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))
                            ->from('area_location as al')
                            ->join('locations as l', 'al.location_id', '=', 'l.id')
                            ->join('areas as a', 'al.area_id', '=', 'a.id')
                            ->whereColumn('al.area_id', 'stocks.area_id')
                            ->where('l.type', LocationTypeEnum::WAREHOUSE)
                            ->where('a.status', 1);
                    })
                    ->groupBy('stocks.record_id')
                    ->selectRaw('stocks.record_id as rid, SUM(stocks.quantity) as qty_sum')
                    ->pluck('qty_sum', 'rid');

                foreach ($importedRecords as &$r) {
                    $rid = isset($r['record_id']) ? (int) $r['record_id'] : null;
                    $r['total_warehouse_stocks'] = (int) ($rid ? ($sumsByRecord[$rid] ?? 0) : 0);
                }
                unset($r);
            } else {
                foreach ($importedRecords as &$r) {
                    $r['total_warehouse_stocks'] = 0;
                }
                unset($r);
            }

            $fileImportFeedback = $import->getErrors();

            if (! empty($fileImportFeedback['errors'])) {
                $importError['file'] = $fileImportFeedback['errors'];
            }

            $result = [
                'error' => $importError,
                'records' => $importedRecords,
                'warnings' => $fileImportFeedback['warnings'] ?? [],
                'notFoundRecords' => $import->getNotFoundRecords(),
            ];

            return $result;
        } catch (\Exception $e) {
            $this->logError('General Exception during import: '.$e->getMessage());
            $importError['file'] = 'An unexpected error occurred during file processing: '.$e->getMessage();
        }

        return [
            'error' => $importError,
            'records' => $importedRecords,
            'warnings' => [],
        ];
    }

    /**
     * Validate stock availability for a record (only when status = 1)
     */
    private function validateStockAvailability(?int $stockId, int $requestedQuantity, WholesaleOut $wholesaleOut): ?Stock
    {
        // Handle case where stock_id is null, 0, or invalid (backorder scenario)
        if (! $stockId || $stockId <= 0) {
            return null; // This will be handled by the backorder logic
        }

        $stock = Stock::find($stockId);
        if (! $stock) {
            return null; // This will also be handled by the backorder logic
        }

        // Only validate stock availability if WholesaleOut is active (status = 1)
        if ($wholesaleOut->status === 1) {
            // Single area mode - allow insufficient stock, will be handled by area logic
            // No exception thrown here, let the area-specific logic handle backorders and warnings
        }

        return $stock;
    }

    /**
     * Create a new WholesaleOutRecord
     */
    private function createWholesaleOutRecord(WholesaleOut $wholesaleOut, array $recordData): WholesaleOutRecord
    {
        return $wholesaleOut->records()->create([
            'position' => $recordData['position'] ?? 0,
            'record_id' => $recordData['record_id'],
            'stock_id' => $recordData['stock_id'],
            'quantity' => $recordData['quantity'],
            'unit_price' => (float) $recordData['unit_price'],
            'discount' => $recordData['discount'] ?? 0,
            'total_price' => (float) $recordData['total_price'],
            'vat' => $recordData['vat'] ?? 22,
        ]);
    }

    /**
     * Process records array for both store and update operations
     */
    private function processRecords(WholesaleOut $wholesaleOut, array $records, bool $isUpdate = false): float
    {
        $totalPrice = 0;

        foreach ($records as $position => $recordInput) {
            $stock = $this->validateStockAvailability($recordInput['stock_id'], $recordInput['quantity'], $wholesaleOut);

            if ($isUpdate && isset($recordInput['id'])) {
                // Update existing record
                $this->updateExistingRecord($recordInput, $stock, $wholesaleOut, $position);
            } else {
                // Create new record
                $recordData = [
                    'position' => $position,
                    'stock_id' => $stock ? $stock->id : null, // Use stock ID from validated stock or null for backorders
                    'quantity' => $recordInput['quantity'],
                    'unit_price' => $recordInput['unit_price'],
                    'discount' => $recordInput['discount'] ?? 0,
                    'total_price' => $recordInput['total_price'],
                    'vat' => $recordInput['vat'] ?? 22,
                    'record_id' => $stock ? $stock->record_id : $recordInput['record_id'],
                ];

                $wholesaleOutRecord = $this->createWholesaleOutRecord($wholesaleOut, $recordData);

                // Single area mode
                // Check if user selected a specific area via area_quantities in UI
                $targetAreaId = null;
                $recordQuantity = $recordInput['quantity'];

                if (isset($recordInput['area_quantities']) && is_array($recordInput['area_quantities']) && count($recordInput['area_quantities']) > 0) {
                    // User selected a specific area in the UI
                    $targetAreaId = $recordInput['area_quantities'][0]['area_id'];
                } elseif ($wholesaleOut->area_id) {
                    // Fallback to WholesaleOut's area if available
                    $targetAreaId = $wholesaleOut->area_id;
                } else {
                    // If WholesaleOut has no area_id (legacy data), log error for new records
                    // For new records, we cannot preserve an existing assignment since there isn't one yet
                    $this->logError('Cannot determine area for new WholesaleOutRecord - no area_quantities and no wholesaleOut.area_id', [
                        'wholesale_out_id' => $wholesaleOut->id,
                        'record_id' => $recordInput['record_id'],
                    ]);
                }

                if ($targetAreaId) {
                    // Update stock for the specific area ONLY if WholesaleOut is active (status = 1)
                    if ($wholesaleOut->status === 1) {
                        // CRITICAL: Use the selected targetAreaId, NOT the stock's area_id
                        $allocationResult = $this->allocateStockAndUpdateShippedQuantity(
                            $wholesaleOutRecord,
                            $recordInput['record_id'],
                            $targetAreaId,
                            $recordQuantity,
                            $isUpdate,
                            $wholesaleOut
                        );

                        if ($allocationResult['warning']) {
                            // Store warnings in a session array that accumulates warnings
                            $currentWarnings = session()->get('importWarnings', []);
                            $currentWarnings[] = $allocationResult['warning'];
                            session()->put('importWarnings', $currentWarnings);
                        }

                        // Update area assignment with the SHIPPED quantity (not requested)
                        $this->updateWholesaleOutRecordArea(
                            $wholesaleOutRecord->id,
                            $targetAreaId,
                            $wholesaleOutRecord->shipped_quantity, // Use shipped quantity
                            $isUpdate
                        );
                    } else {
                        // Draft WholesaleOut - no stock allocation, shipped_quantity = 0
                        $wholesaleOutRecord->shipped_quantity = 0;
                        $wholesaleOutRecord->save();

                        // For drafts, store REQUESTED quantity in area assignment (not shipped)
                        $this->updateWholesaleOutRecordArea(
                            $wholesaleOutRecord->id,
                            $targetAreaId,
                            $recordQuantity, // Use requested quantity for drafts
                            $isUpdate
                        );
                    }
                }
            }

            $totalPrice += (float) $recordInput['total_price'];
        }

        return $totalPrice;
    }

    /**
     * Update an existing WholesaleOutRecord
     */
    private function updateExistingRecord(array $recordInput, ?Stock $stock, WholesaleOut $wholesaleOut, int $position = 0): void
    {
        $wholesaleOutRecord = WholesaleOutRecord::find($recordInput['id']);
        if (! $wholesaleOutRecord) {
            throw new \Exception("WholesaleOutRecord not found with ID: {$recordInput['id']}");
        }

        $originalQuantity = $wholesaleOutRecord->quantity;
        $newQuantity = $recordInput['quantity'];
        $quantityDifference = $newQuantity - $originalQuantity;

        // First, update the WholesaleOutRecord
        $wholesaleOutRecord->update([
            'position' => $position,
            'stock_id' => $stock ? $stock->id : null, // Use stock ID from validated stock or null for backorders
            'quantity' => $newQuantity,
            'unit_price' => (float) $recordInput['unit_price'],
            'discount' => $recordInput['discount'] ?? 0,
            'total_price' => (float) $recordInput['total_price'],
            'vat' => $recordInput['vat'] ?? 22,
        ]);

        // SIMPLIFIED: Single area mode - handle area quantities changes
        // Get target area from user selection or fallback to WholesaleOut's area
        $targetAreaId = null;

        if (isset($recordInput['area_quantities']) && is_array($recordInput['area_quantities']) && count($recordInput['area_quantities']) > 0) {
            // User selected a specific area in the UI
            $targetAreaId = $recordInput['area_quantities'][0]['area_id'];
        } elseif ($wholesaleOut->area_id) {
            // Fallback to WholesaleOut's area if available
            $targetAreaId = $wholesaleOut->area_id;
        } else {
            // If WholesaleOut has no area_id (legacy data),
            // check if record already has an area assignment and preserve it
            $existingAreaAssignment = $wholesaleOutRecord->wholesaleOutRecordsArea()->first();
            if ($existingAreaAssignment) {
                $targetAreaId = $existingAreaAssignment->area_id;
                $this->logWarning('WholesaleOut has no area_id, preserving existing area assignment', [
                    'wholesale_out_id' => $wholesaleOut->id,
                    'record_id' => $wholesaleOutRecord->id,
                    'preserved_area_id' => $targetAreaId,
                ]);
            }
        }

        // Stock allocation only for active WholesaleOuts
        if ($wholesaleOut->status === 1) {

            // First, restore the old stock if any was previously allocated
            $this->restoreStockForExistingRecord($wholesaleOutRecord, $originalQuantity);

            // Then, allocate stock for the new area/quantity
            if ($targetAreaId) {
                // Apply the new stock allocation
                $allocationResult = $this->allocateStockAndUpdateShippedQuantity(
                    $wholesaleOutRecord,
                    $recordInput['record_id'],
                    $targetAreaId,
                    $newQuantity,
                    true, // isUpdate = true
                    $wholesaleOut
                );

                if ($allocationResult['warning']) {
                    // Store warnings in a session array that accumulates warnings
                    $currentWarnings = session()->get('importWarnings', []);
                    $currentWarnings[] = $allocationResult['warning'];
                    session()->put('importWarnings', $currentWarnings);
                }

                // Update area assignment with SHIPPED quantity (not requested)
                $this->updateWholesaleOutRecordArea(
                    $wholesaleOutRecord->id,
                    $targetAreaId,
                    $wholesaleOutRecord->shipped_quantity, // Use shipped quantity
                    true // isUpdate = true
                );
            }
        } else {
            // Draft WholesaleOut - no stock allocation, shipped_quantity = 0
            $wholesaleOutRecord->shipped_quantity = 0;
            $wholesaleOutRecord->save();

            // For drafts, update area assignment with REQUESTED quantity (not shipped)
            if ($targetAreaId) {
                $this->updateWholesaleOutRecordArea(
                    $wholesaleOutRecord->id,
                    $targetAreaId,
                    $newQuantity, // Use requested quantity for drafts
                    true // isUpdate = true
                );
            }
        }

        // If no area could be determined - this is a problem that should be logged
        if (! $targetAreaId) {
            $this->logError('Cannot determine area for WholesaleOutRecord - no area_quantities, no wholesaleOut.area_id, no existing assignment', [
                'wholesale_out_id' => $wholesaleOut->id,
                'wholesale_out_record_id' => $wholesaleOutRecord->id,
            ]);
        }

        $this->logInfo('WholesaleOutRecord updated.', ['id' => $wholesaleOutRecord->id]);
    }

    /**
     * Handle file upload if present (step 1: file processing)
     */
    private function handleFileUpload(WholesaleOutRequest $request, array $validated, ?WholesaleOut $wholesaleOut = null): ?\Illuminate\Http\RedirectResponse
    {
        if (! $request->hasFile('file')) {
            return null;
        }

        $this->logDetail('XLSX file detected for import preview process.');

        // For store operation, check if WholesaleOut already exists
        if (! $wholesaleOut && empty($validated['records'])) {
            if (WholesaleOut::where('customer_id', $validated['customer_id'])
                ->where('doc_num', $validated['doc_num'])
                ->exists()
            ) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->withErrors(['file' => 'Vendita già esistente per questo cliente e numero documento.']);
            }
        }

        try {
            // Get the default area ID from the validated data
            $defaultAreaId = $validated['area_id'] ?? ($wholesaleOut ? $wholesaleOut->area_id : null);

            $importResult = $this->processFileImport($request, $defaultAreaId);

            if (! empty($importResult['error'])) {
                $this->logWarning('Errors during XLSX import.', ['errors' => $importResult['error']]);
                throw \Illuminate\Validation\ValidationException::withMessages($importResult['error']);
            }

            $this->logInfo('XLSX import processed for preview. Records:', $importResult['records']);

            // Prepare preserved data for redirect
            $preservedData = $wholesaleOut ? [
                'customer_id' => $wholesaleOut->customer_id,
                'area_id' => $wholesaleOut->area_id,
                'doc_num' => $wholesaleOut->doc_num,
                'description' => $wholesaleOut->description,
                'status' => $wholesaleOut->status,
            ] : $validated;

            return redirect()
                ->back()
                ->withInput($preservedData)
                ->with('importedRecords', $importResult['records'])
                ->with('importWarnings', $importResult['warnings'] ?? [])
                ->with('notFoundRecords', $importResult['notFoundRecords'] ?? []);

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->logWarning('ValidationException during import preview:', ['errors' => $e->errors()]);

            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            $this->logWarning('Excel ValidationException during import preview:', ['failures' => $e->failures()]);
            $failures = $e->failures();
            $errorMessages = [];
            foreach ($failures as $failure) {
                $errorMessages[] = "Row {$failure->row()}: ".implode(', ', $failure->errors())." (Attribute: {$failure->attribute()}, Value: ".json_encode($failure->values()).')';
            }

            return redirect()->back()->withErrors(['file' => implode('; ', $errorMessages)])->withInput();
        } catch (\Exception $e) {
            $this->logError('General Exception during import preview: '.$e->getMessage());
            $userMessage = 'An unexpected error occurred during file processing: '.$e->getMessage();

            return redirect()->back()->withErrors(['file' => $userMessage])->withInput();
        }
    }

    /**
     * Create or Update WholesaleOut with records (step 2: final submission)
     * Handles both store() and update() operations
     */
    /**
     * Lock the stock rows referenced by an incoming set of records.
     *
     * Must be called inside the caller's transaction: both callers of
     * saveWholesaleOutWithRecords() wrap it in DB::transaction(). Ids are locked
     * in ascending order so two concurrent saves over overlapping stock cannot
     * deadlock by taking the same rows in a different order.
     *
     * A stock_id that has already gone by the time we lock is not a race we can
     * win: the row is not coming back, so the reference is dropped to null (the
     * value the schema uses for a line with no stock behind it, as backorders do)
     * and the user is warned, rather than letting the insert fail with a 1452.
     *
     * @param  array<int,array<string,mixed>>  $records  normalised, by reference
     */
    private function lockReferencedStocks(array &$records): void
    {
        $requested = collect($records)
            ->pluck('stock_id')
            ->filter() // backorder rows carry null and reference nothing
            ->unique()
            ->sort()
            ->values();

        if ($requested->isEmpty()) {
            return;
        }

        $locked = Stock::whereIn('id', $requested)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        $this->logDetail('Locked stocks referenced by this WholesaleOut save.', [
            'requested' => $requested->all(),
            'locked' => $locked->all(),
        ]);

        $missing = $requested->diff($locked);

        if ($missing->isEmpty()) {
            return;
        }

        $this->logWarning('Referenced stock no longer exists, dropping the reference.', [
            'missing_stock_ids' => $missing->values()->all(),
        ]);

        foreach ($records as &$record) {
            if (isset($record['stock_id']) && $missing->contains($record['stock_id'])) {
                $record['stock_id'] = null;
            }
        }
        unset($record);

        $warnings = session()->get('importWarnings', []);
        foreach ($missing as $stockId) {
            $warnings[] = "Lo stock #{$stockId} non esiste più: la riga è stata salvata senza riferimento di stock.";
        }
        session()->put('importWarnings', $warnings);
    }

    private function saveWholesaleOutWithRecords(array $validated, ?WholesaleOut $wholesaleOut = null): WholesaleOut
    {
        // Normalize area_quantities EARLY: If not provided or empty, use WholesaleOut's default area
        // This ensures UI will show the correct area after save/refresh, and reconciliation service gets clean data
        // This normalization happens before ANY processing, so it works for all code paths (draft, active, new, update)

        // Use the existing area_id from database if frontend doesn't provide one
        // This happens when the area combo is filtered and doesn't show the saved area
        $defaultAreaId = $validated['area_id'] ?? ($wholesaleOut->area_id ?? null);

        // CRITICAL: Double-check we have a valid area_id before proceeding
        // Validation should have caught this, but this prevents potential data corruption
        if (! $defaultAreaId) {
            throw new \Exception('Cannot save WholesaleOut: No valid area_id provided. Please select a warehouse area.');
        }

        if ($defaultAreaId && ! empty($validated['records'])) {
            foreach ($validated['records'] as $index => &$record) {
                // Normalize stock_id: the UI emits 0 for backorder rows (no suitable stock),
                // but stock_id references stocks.id, so coerce 0/invalid to null to avoid FK violations.
                if (! isset($record['stock_id']) || (int) $record['stock_id'] <= 0) {
                    $record['stock_id'] = null;
                }

                if (empty($record['area_quantities'])) {
                    // For an existing record, preserve its current area allocation instead of
                    // forcing it onto the WholesaleOut's default area (e.g. backorder rows may
                    // not submit area_quantities, but their area shouldn't silently change).
                    $existingAreas = isset($record['id'])
                        ? WholesaleOutRecordsArea::where('wholesale_out_records_id', $record['id'])
                            ->get(['area_id', 'quantity'])
                            ->map(fn ($a) => ['area_id' => $a->area_id, 'quantity' => $a->quantity])
                            ->toArray()
                        : [];

                    $record['area_quantities'] = ! empty($existingAreas) ? $existingAreas : [[
                        'area_id' => $defaultAreaId,
                        'quantity' => $record['quantity'],
                    ]];
                }
            }
            unset($record); // Break reference

            // Take the stock rows this save references under an exclusive lock for
            // the rest of the transaction, so a concurrent stock delete blocks
            // instead of racing the inserts below. Validation already checked that
            // each stock_id exists, but that check and the insert are not atomic.
            $this->lockReferencedStocks($validated['records']);
        }

        $previousStatus = null;
        $isUpdate = false;
        $originalWholesaleOut = null;

        if ($wholesaleOut) {
            $previousStatus = $wholesaleOut->status;
            $isUpdate = true;

            // If updating an active WholesaleOut, store original state for reconciliation
            if ($wholesaleOut->status === 1) {
                // Create a completely isolated copy by querying fresh from database
                // This prevents any reference contamination from the current model
                $originalWholesaleOut = WholesaleOut::with([
                    'records.parentRecord',
                    'records.wholesaleOutRecordsArea.area',
                    'records.backorderRecords',
                    'area',
                ])->find($wholesaleOut->id);

                // Create a data snapshot of original record values to prevent Laravel auto-refresh corruption
                $originalRecordData = [];
                foreach ($originalWholesaleOut->records as $record) {
                    $originalRecordData[$record->id] = [
                        'id' => $record->id,
                        'record_id' => $record->record_id,
                        'quantity' => $record->quantity,
                        'unit_price' => $record->unit_price->getAmount(),
                        'discount' => $record->discount ?? 0,
                        'area_allocations' => $record->wholesaleOutRecordsArea->map(function ($area) {
                            return [
                                'area_id' => $area->area_id,
                                'quantity' => $area->quantity,
                            ];
                        })->toArray(),
                    ];
                }

                $this->logInfo('Stored original state for active WholesaleOut reconciliation', [
                    'wholesale_out_id' => $wholesaleOut->id,
                    'original_records_count' => $originalWholesaleOut->records->count(),
                    'original_record_data_captured' => count($originalRecordData),
                ]);
            }

            // Update existing WholesaleOut
            $this->handleExistingRecordsForUpdate($wholesaleOut, $validated['records'] ?? []);
        } else {
            // Create new WholesaleOut
            $wholesaleOut = WholesaleOut::create([
                'customer_id' => $validated['customer_id'],
                'area_id' => $validated['area_id'] ?? null,
                'doc_num' => $validated['doc_num'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 0, // Default to 0 if not provided
                'total_price' => 0, // Will be updated after processing records
            ]);
            $this->logInfo('WholesaleOut entry created.', ['id' => $wholesaleOut->id, 'status' => $wholesaleOut->status]);
        }

        $totalPrice = 0;
        $statusChangeWarnings = []; // Initialize warnings array

        if (! empty($validated['records'])) {
            // For active WholesaleOuts being updated, run reconciliation service FIRST
            // before updating records in database
            if ($isUpdate && $originalWholesaleOut && $previousStatus === 1) {
                // Run reconciliation service with original data vs new data
                $this->logInfo('Processing changes to active WholesaleOut - RECONCILIATION STARTING', [
                    'wholesale_out_id' => $wholesaleOut->id,
                    'original_status' => $originalWholesaleOut->status,
                    'previous_status' => $previousStatus,
                    'is_update' => $isUpdate,
                    'has_original' => ! is_null($originalWholesaleOut),
                ]);

                // FIRST: Update record entities without stock changes for active WholesaleOuts
                // This creates new records in database that reconciliation service can find
                $totalPrice = $this->updateRecordsWithoutStockChanges($wholesaleOut, $validated['records']);
                $this->logInfo('Updated records without stock changes for active WholesaleOut - NOW running reconciliation service');

                try {
                    // CRITICAL: DO NOT reload relationships on $originalWholesaleOut as it corrupts the original state
                    // The original state was loaded above and must be preserved

                    // SECOND: Use the reconciliation service with the original WholesaleOut and new data
                    // Pass the original record data snapshot to prevent Laravel auto-refresh corruption
                    // Now that records exist in database, reconciliation can find them
                    $reconciliationWarnings = $this->reconciliationService->reconcileChanges(
                        $originalWholesaleOut,
                        $validated,
                        $originalRecordData
                    );

                    if (! empty($reconciliationWarnings)) {
                        $statusChangeWarnings = array_merge($statusChangeWarnings, $reconciliationWarnings);
                        $this->logInfo('Reconciliation completed with warnings', [
                            'wholesale_out_id' => $wholesaleOut->id,
                            'warnings' => $reconciliationWarnings,
                        ]);
                    } else {
                        $this->logInfo('Reconciliation completed successfully without warnings', [
                            'wholesale_out_id' => $wholesaleOut->id,
                        ]);
                    }

                } catch (\Exception $e) {
                    $this->logError('Error during active WholesaleOut reconciliation', [
                        'wholesale_out_id' => $wholesaleOut->id,
                        'error' => $e->getMessage(),
                        'original_wholesale_out_id' => $originalWholesaleOut->id ?? 'unknown',
                        'original_records_count' => $originalWholesaleOut->records->count() ?? 'unknown',
                    ]);

                    // Add warning but don't fail the whole operation
                    $statusChangeWarnings[] = 'Warning: Some stock reconciliation issues occurred. Please verify stock levels manually. Error: '.$e->getMessage();
                }
            } else {
                // Normal record processing for inactive WholesaleOuts
                $totalPrice = $this->processRecords($wholesaleOut, $validated['records'], $wholesaleOut->exists);
            }
        } else {
            // Handle case where no records are provided
            if (! $wholesaleOut->exists) {
                throw new \Exception('No records provided for the wholesale out operation.');
            }
            $this->logInfo('No records submitted in update request. All existing records were processed for deletion if any.');
        }

        // Update the WholesaleOut record first
        // Don't overwrite area_id with null if the frontend doesn't provide it
        // This happens when area combo is filtered and doesn't show the saved area
        $updateData = [
            'customer_id' => $validated['customer_id'],
            'doc_num' => $validated['doc_num'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? $wholesaleOut->status,
            'total_price' => $totalPrice,
        ];

        // Only update area_id if explicitly provided (not null)
        if (isset($validated['area_id']) && $validated['area_id'] !== null) {
            $updateData['area_id'] = $validated['area_id'];
        }

        $wholesaleOut->update($updateData);

        // Save label discounts (always process for updates to handle deletions)
        if (isset($validated['label_discounts'])) {
            $this->saveLabelDiscounts($wholesaleOut, $validated['label_discounts']);
        }

        // For UPDATES of active WholesaleOuts: backfill shipped_quantity for legacy records
        // Legacy records are old records created before the shipped_quantity column was added (value = 0)
        // New records already have shipped_quantity set by allocateStockAndUpdateShippedQuantity
        if ($isUpdate && $wholesaleOut->status === 1) {
            $this->backfillShippedQuantityForLegacyRecords($wholesaleOut);
        }

        // Handle status changes
        if ($isUpdate && $previousStatus !== ($validated['status'] ?? $wholesaleOut->status)) {
            $newStatus = $validated['status'] ?? $wholesaleOut->status;

            if ($previousStatus === 0 && $newStatus === 1) {
                // Status changed from inactive to active - remove stocks
                $this->logInfo('WholesaleOut status changed from 0 to 1 - removing stocks', [
                    'wholesale_out_id' => $wholesaleOut->id,
                ]);
                $statusChangeWarnings = array_merge($statusChangeWarnings, $this->handleStatusChangeToActive($wholesaleOut));
            } elseif ($previousStatus === 1 && $newStatus === 0) {
                // Status changed from active to inactive - restore stocks
                $this->logInfo('WholesaleOut status changed from 1 to 0 - restoring stocks', [
                    'wholesale_out_id' => $wholesaleOut->id,
                ]);
                $statusChangeWarnings = array_merge($statusChangeWarnings, $this->addStocksForWholesaleOut($wholesaleOut));
            }
        }

        // Store warnings in session if any
        if (! empty($statusChangeWarnings)) {
            session()->flash('importWarnings', $statusChangeWarnings);
        }

        return $wholesaleOut;
    }

    /**
     * Handle PDF file upload
     */
    private function handlePdfFileUpload(array $validated): ?string
    {
        // PDF upload is not exposed in the UI yet
        // if (! request()->hasFile('pdf_file')) {
        //     return null;
        // }

        // $this->logInfo('PDF file detected. Storing PDF.');
        // $storedPdfFile = request()->file('pdf_file')->store('wholesale-out/documents', 'local');
        // $this->logInfo('PDF stored.', ['path' => $storedPdfFile]);

        // return $storedPdfFile;

        return null;
    }

    /**
     * Handle existing records during update (delete removed records)
     */
    private function handleExistingRecordsForUpdate(WholesaleOut $wholesaleOut, array $submittedRecords): void
    {
        $existingRecordIds = $wholesaleOut->records()->pluck('id')->toArray();
        $submittedRecordIds = collect($submittedRecords)
            ->filter(fn ($record) => isset($record['id']))
            ->pluck('id')
            ->toArray();

        $recordsToDeleteIds = array_diff($existingRecordIds, $submittedRecordIds);

        if (! empty($recordsToDeleteIds)) {
            $this->logInfo('Records to delete identified for update.', ['ids' => $recordsToDeleteIds]);
            $recordsToDelete = WholesaleOutRecord::whereIn('id', $recordsToDeleteIds)
                ->with('wholesaleOutRecordsArea') // Eager load area relationships
                ->get();

            foreach ($recordsToDelete as $recordToDelete) {
                // IMPORTANT: For active WholesaleOuts (status = 1), do NOT delete or restore stock here
                // The reconciliation service will handle EVERYTHING (stock restoration AND deletion)
                // This ensures the reconciliation service has access to the original record data
                // For inactive WholesaleOuts, handle deletion directly since reconciliation doesn't run
                if ($wholesaleOut->status !== 1) {
                    // Drafts never decrement stock (see processRecords/updateExistingRecord), so there
                    // is nothing to restore here. Their area assignments hold the REQUESTED quantity,
                    // and crediting that back would inflate stock that was never taken.
                    $recordToDelete->delete();
                    $this->logInfo('WholesaleOutRecord deleted (inactive, no stock restoration).', ['id' => $recordToDelete->id]);
                } else {
                    $this->logInfo('Deferring record deletion to reconciliation service (active WholesaleOut)', [
                        'wholesale_out_record_id' => $recordToDelete->id,
                        'status' => $wholesaleOut->status,
                    ]);
                    // Do NOT delete - let reconciliation service handle it
                }
            }
        }
    }

    /**
     * Get shared view data for create and edit views
     */
    private function getSharedViewData(Request $request): array
    {
        $importedRecords = $request->session()->get('importedRecords', []);
        $importWarnings = $request->session()->get('importWarnings', []);
        $notFoundRecords = $request->session()->get('notFoundRecords', []);

        $warehouseAreas = Area::filterByAdminRoles()->defaultWarehouseAreas()->get();

        // Get system-wide default area, but only if user has access to it
        $systemDefaultLocation = Location::where('default_wholesaleout_location', 1)
            ->where('status', 1)->first();
        $defaultAreaId = null;
        if ($systemDefaultLocation && $systemDefaultLocation->default_area_id) {
            // Check if the default area is in the user's accessible areas
            $defaultAreaModel = $warehouseAreas->firstWhere('id', $systemDefaultLocation->default_area_id);
            if ($defaultAreaModel) {
                $defaultAreaId = $defaultAreaModel->id;
            }
        }

        // Get user's default area from default warehouse location (takes priority)
        /** @var \App\Models\User $user */
        $user = $request->user();
        $defaultArea = null;
        if ($user->defaultLocation && $user->defaultLocation->type === LocationTypeEnum::WAREHOUSE) {
            $defaultAreaModel = $user->defaultLocation->defaultArea ?? $user->defaultLocation->areas->first();
            if ($defaultAreaModel) {
                $defaultArea = new ComboResource($defaultAreaModel);
            }
        }

        return [
            'locations' => ComboResource::collection(Location::filterByAdminRoles()->where('status', 1)->where('type', LocationTypeEnum::WAREHOUSE)->get()),
            'defaultArea' => $defaultArea,
            'customers' => Customer::where('status', 1)
                ->selectRaw("id, TRIM(CONCAT_WS(' ', name, last_name)) as name")
                ->get(),
            'areas' => ComboResource::collection(Area::filterByAdminRoles()->where('status', 1)->get()),
            'areas_warehouses' => ComboResource::collection($warehouseAreas),
            'importedRecords' => $importedRecords,
            'importWarnings' => $importWarnings,
            'notFoundRecords' => $notFoundRecords,
            'defaultAreaId' => $defaultAreaId,
            'types_status' => RecordTypeEnum::getJsonValues(),
        ];
    }

    public function index(Request $request)
    {
        $baseQuery = QueryBuilder::for(WholesaleOut::class)
            ->filterByAdminRoles()
            ->join('customers', 'wholesale_outs.customer_id', '=', 'customers.id')
            // Corrected joins to fetch area_name through stock and wholesale_out_records
            ->leftJoin('wholesale_out_records', 'wholesale_outs.id', '=', 'wholesale_out_records.wholesale_out_id')
            ->leftJoin('stocks', 'wholesale_out_records.stock_id', '=', 'stocks.id')
            ->leftJoin('areas', 'stocks.area_id', '=', 'areas.id')
            // Select distinct wholesale_outs to avoid duplication due to joins with records
            ->selectRaw('DISTINCT wholesale_outs.*, GROUP_CONCAT(DISTINCT areas.name) as area_name')
            ->with(['customer', 'backorders'])
            ->allowedSorts(['doc_num', 'total_price', 'status', 'customers.name', 'area_name', 'created_at'])
            ->allowedFilters([
                AllowedFilter::exact('customers.id'),
                AllowedFilter::exact('areas.id', 'stocks.area_id'), // Filter by area through stocks
                AllowedFilter::exact('status'),
                AllowedFilter::callback('search', function ($query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['doc_num']);
                }),
                AllowedFilter::callback('date', function (Builder $query, $value) {
                    // Add time to make the date range inclusive of the entire day
                    $startDate = $value['startDate'].' 00:00:00';
                    $endDate = $value['endDate'].' 23:59:59';
                    $query->whereBetween('wholesale_outs.created_at', [$startDate, $endDate]);
                }),
            ])
            ->defaultSort('-created_at')
            ->groupBy('wholesale_outs.id'); // Group by wholesale_outs.id to make GROUP_CONCAT work correctly

        return Inertia::render('WholesaleOut/Index', [
            'wholesaleOuts' => WholesaleOutResource::collection($baseQuery->paginate($this->perPage($request))),
            'customers' => Customer::where('status', 1)->get()->map(function ($customer) {
                return ['id' => $customer->id, 'name' => $customer->full_name];
            }),
            'areas' => ComboResource::collection(Area::filterByAdminRoles()->where('status', 1)->get()), // Keep areas for filtering
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create(Request $request)
    {
        $sharedData = $this->getSharedViewData($request);

        return Inertia::render('WholesaleOut/Create', $sharedData);
    }

    public function downloadTemplate()
    {
        return Excel::download(new WholesaleOutTemplateExport, 'vendita_ingrosso.xlsx');
    }

    public function export(WholesaleOut $wholesaleOut)
    {
        $this->authorize('view', $wholesaleOut);

        $docNum = $wholesaleOut->doc_num ?? '';
        $cleanDocNum = preg_replace('/[^a-zA-Z0-9\s]/', '', $docNum); // Remove special characters
        $cleanDocNum = preg_replace('/\s+/', '-', trim($cleanDocNum)); // Replace whitespaces with dashes

        $filename = 'Scarico_'.$cleanDocNum.'_'.$wholesaleOut->created_at->format('dmY').'.xlsx';

        return Excel::download(new WholesaleOutExport($wholesaleOut), $filename);
    }

    public function store(WholesaleOutRequest $request)
    {
        $validated = $request->validated();

        try {
            // Handle file upload if present (step 1: file processing)
            if ($fileUploadResponse = $this->handleFileUpload($request, $validated)) {
                return $fileUploadResponse;
            }

            // Save WholesaleOut with records (step 2: final submission)
            return DB::transaction(function () use ($validated, $request) {
                $this->saveWholesaleOutWithRecords($validated);

                // Clear imported records from session after successful processing
                $request->session()->forget('importedRecords');
                $request->session()->forget('notFoundRecords');

                return redirect()->route('wholesale-out.index')->with('success', 'Wholesale Out created successfully.');
            });

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->logWarning('ValidationException caught in store:', ['errors' => $e->errors()]);

            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            $this->logError('General Exception caught in store: '.$e->getMessage());
            $userMessage = 'An unexpected error occurred: '.$e->getMessage();
            if (str_contains($e->getMessage(), 'Stock not found') || str_contains($e->getMessage(), 'Insufficient stock') || str_contains($e->getMessage(), 'No records provided')) {
                $userMessage = $e->getMessage();
            }
            $errorField = (str_contains($e->getMessage(), 'No records provided')) ? 'records' : 'error';

            return redirect()->back()->withErrors([$errorField => $userMessage])->withInput();
        }
    }

    public function edit(WholesaleOut $wholesaleOut, Request $request)
    {
        $this->authorize('view', $wholesaleOut);

        // Refresh the model from database to ensure we have latest stock quantities
        // especially important after file processing redirects
        $wholesaleOut->refresh();

        // Clear any cached relationships to ensure fresh stock data
        $wholesaleOut->unsetRelation('records');

        $wholesaleOut->load([
            'customer',
            'area',
            // Show records in add order, most recent first (position 0 = most recently added)
            'records' => function ($q) {
                $q->orderBy('position')->orderBy('id');
            },
            'records.wholesaleOut',
            'records.parentRecord' => function ($q) {
                $q->addSelect([
                    'last_sale_date' => SaleRecord::select('sales.date')
                        ->join('sales', 'sale_records.sale_id', '=', 'sales.id')
                        ->whereColumn('sale_records.record_id', 'records.id')
                        ->orderByDesc('sales.date')
                        ->limit(1),
                ])
                    ->withSum('stocks as total_stocks', 'quantity')
                    ->withSum(['stocks as total_warehouse_stocks' => function ($sq) {
                        $sq->whereIn('area_id', DB::table('area_location')
                            ->select('area_id')
                            ->join('locations', 'area_location.location_id', '=', 'locations.id')
                            ->join('areas', 'area_location.area_id', '=', 'areas.id')
                            ->where('locations.type', LocationTypeEnum::WAREHOUSE)
                            ->where('areas.status', 1)
                        );
                    }], 'quantity');
            },
            'records.parentRecord.artist',
            'records.parentRecord.format',
            'records.parentRecord.label',
            'records.parentRecord.media',
            'records.parentRecord.stocks',
            'records.stock.area',
            'records.backorderRecords.backorder',
            'records.wholesaleOutRecordsArea.area',
            'labelDiscounts.label',
            'backorders.backorderRecords.wholesaleOutRecord',
        ]);

        $sharedData = $this->getSharedViewData($request);

        $this->logInfo('Edit method - sharedData importedRecords:', [
            'count' => count($sharedData['importedRecords']),
            'has_imported_records' => ! empty($sharedData['importedRecords']),
            'wholesale_out_id' => $wholesaleOut->id,
        ]);

        $sessionWarnings = session('importWarnings', []);

        return Inertia::render('WholesaleOut/Edit', array_merge($sharedData, [
            'wholesaleOut' => new WholesaleOutResource($wholesaleOut),
            // Only pass importedRecords if they exist (right after file upload redirect)
            'importedRecords' => ! empty($sharedData['importedRecords']) ? $sharedData['importedRecords'] : null,
            // Pass warnings from flash data if they exist
            'importWarnings' => $sessionWarnings,
        ]));
    }

    public function update(WholesaleOutRequest $request, WholesaleOut $wholesaleOut)
    {
        $this->logInfo('WholesaleOutController update method started.', ['wholesale_out_id' => $wholesaleOut->id]);

        // Clear any previous warnings at the start of the update process
        $request->session()->forget('importWarnings');

        $validated = $request->validated();

        $this->logInfo('Request data validated.', $validated);

        try {
            // Handle file upload if present (step 1: file processing)
            if ($fileUploadResponse = $this->handleFileUpload($request, $validated, $wholesaleOut)) {
                return $fileUploadResponse;
            }

            // Save WholesaleOut with records (step 2: final submission)
            return DB::transaction(function () use ($validated, $wholesaleOut, $request) {
                $this->saveWholesaleOutWithRecords($validated, $wholesaleOut);

                // Clear imported records from session after successful processing
                $request->session()->forget('importedRecords');
                $request->session()->forget('notFoundRecords');

                // Get all warnings that might have been collected during processing
                $warnings = session()->get('importWarnings', []);

                // Always redirect back to edit page to show warnings/success message
                // This ensures users can see important warnings about stock issues, backorders, etc.
                return redirect()->route('wholesale-out.edit', $wholesaleOut->id)->with([
                    'success' => 'Wholesale Out updated successfully.',
                    'importWarnings' => $warnings,
                ]);
            });

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->logWarning('ValidationException caught in update:', ['errors' => $e->errors(), 'wholesale_out_id' => $wholesaleOut->id]);

            return redirect()->back()->withErrors($e->errors())->withInput();
        } catch (\Exception $e) {
            $this->logError('General Exception caught in update: '.$e->getMessage(), ['wholesale_out_id' => $wholesaleOut->id]);
            $userMessage = 'An unexpected error occurred during update: '.$e->getMessage();
            if (str_contains($e->getMessage(), 'Stock not found') || str_contains($e->getMessage(), 'Insufficient stock')) {
                $userMessage = $e->getMessage();
            }

            return redirect()->back()->withErrors(['error' => $userMessage])->withInput();
        }
    }

    public function destroy(?WholesaleOut $wholesaleOut, Request $request)
    {

        if ($request->ids) {
            foreach ($request->ids as $id) {

                $id = intval($id);

                $wholesaleoutMulti = WholesaleOut::filterByAdminRoles()->find($id);

                if (! $wholesaleoutMulti) {
                    continue;
                }

                // Only restore stock if WholesaleOut was active (status = 1)
                if ($wholesaleoutMulti->status === 1) {
                    $restoreWarnings = $this->addStocksForWholesaleOut($wholesaleoutMulti);
                    // Note: In bulk delete, we're not showing warnings to the user
                    // but they are logged for debugging purposes
                    if (! empty($restoreWarnings)) {
                        $this->logWarning('Warnings during bulk WholesaleOut deletion', [
                            'wholesale_out_id' => $wholesaleoutMulti->id,
                            'warnings' => $restoreWarnings,
                        ]);
                    }
                }

                $wholesaleoutMulti->delete();

            }

            return back()->with('success', 'Scarichi eliminati con successo');
        }

        $this->authorize('delete', $wholesaleOut);

        return DB::transaction(function () use ($wholesaleOut) {
            // Only restore stock if WholesaleOut was active (status = 1)
            $restoreWarnings = [];
            if ($wholesaleOut->status === 1) {
                $restoreWarnings = $this->addStocksForWholesaleOut($wholesaleOut);
            }
            $wholesaleOut->delete();

            if (! empty($restoreWarnings)) {
                return redirect()->route('wholesale-out.index')
                    ->with('success', 'Vendita eliminata correttamente.')
                    ->with('importWarnings', $restoreWarnings);
            }

            return redirect()->route('wholesale-out.index')->with('success', 'Vendita eliminata correttamente.');
        });
    }

    public function activationPreview(Request $request)
    {
        $payload = $request->validate([
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'records' => ['required', 'array'],
            'records.*.record_id' => ['required', 'integer', 'exists:records,id'],
            'records.*.quantity' => ['required', 'integer', 'min:1'],
            'records.*.area_quantities' => ['nullable', 'array'],
            'records.*.area_quantities.*.area_id' => ['required_with:records.*.area_quantities', 'integer', 'exists:areas,id'],
            'records.*.area_quantities.*.quantity' => ['required_with:records.*.area_quantities', 'integer', 'min:0'],
        ]);

        $fallbackAreaId = $payload['area_id'] ?? null;

        $demand = [];
        foreach ($payload['records'] as $r) {
            $recordId = (int) $r['record_id'];
            $lines = collect($r['area_quantities'] ?? [])
                ->filter(fn ($aq) => (int) ($aq['quantity'] ?? 0) > 0)
                ->map(fn ($aq) => ['area_id' => (int) $aq['area_id'], 'quantity' => (int) $aq['quantity']])
                ->values()
                ->all();

            if (empty($lines)) {
                if (! $fallbackAreaId) {
                    $demand[] = ['record_id' => $recordId, 'area_id' => null, 'requested' => (int) $r['quantity']];

                    continue;
                }
                $lines = [['area_id' => $fallbackAreaId, 'quantity' => (int) $r['quantity']]];
            }

            foreach ($lines as $line) {
                $demand[] = [
                    'record_id' => $recordId,
                    'area_id' => $line['area_id'],
                    'requested' => $line['quantity'],
                ];
            }
        }

        $lookupKeys = collect($demand)
            ->filter(fn ($d) => $d['area_id'] !== null)
            ->map(fn ($d) => [$d['record_id'], $d['area_id']])
            ->values();

        $stockLookup = collect();
        if ($lookupKeys->isNotEmpty()) {
            $stocks = Stock::query()
                ->where(function ($q) use ($lookupKeys) {
                    foreach ($lookupKeys as [$rid, $aid]) {
                        $q->orWhere(function ($qq) use ($rid, $aid) {
                            $qq->where('record_id', $rid)->where('area_id', $aid);
                        });
                    }
                })
                ->get(['record_id', 'area_id', 'quantity']);

            $stockLookup = $stocks->keyBy(fn ($s) => $s->record_id.':'.$s->area_id);
        }

        $recordIds = collect($demand)->pluck('record_id')->unique()->values();
        $records = \App\Models\Record::with('artist')->whereIn('id', $recordIds)->get()->keyBy('id');
        $areaIds = collect($demand)->pluck('area_id')->filter()->unique()->values();
        $areas = \App\Models\Area::whereIn('id', $areaIds)->get()->keyBy('id');

        $totalRequested = 0;
        $totalAllocatable = 0;
        $shortages = [];
        $linesFull = 0;
        $linesPartial = 0;
        $linesMissing = 0;

        foreach ($demand as $d) {
            $totalRequested += $d['requested'];
            $available = 0;
            if ($d['area_id'] !== null) {
                $stock = $stockLookup->get($d['record_id'].':'.$d['area_id']);
                $available = $stock ? max(0, (int) $stock->quantity) : 0;
            }
            $allocatable = min($available, $d['requested']);
            $totalAllocatable += $allocatable;

            if ($allocatable >= $d['requested']) {
                $linesFull++;

                continue;
            }

            if ($allocatable > 0) {
                $linesPartial++;
            } else {
                $linesMissing++;
            }

            $shortages[] = [
                'record_info' => $this->formatRecordInfo($records->get($d['record_id'])),
                'area_name' => $d['area_id'] === null ? 'Nessuna area' : ($areas->get($d['area_id'])->name ?? "Area {$d['area_id']}"),
                'requested' => $d['requested'],
                'available' => $available,
                'shortage' => $d['requested'] - $allocatable,
            ];
        }

        return response()->json([
            'total_lines' => count($demand),
            'total_requested_units' => $totalRequested,
            'total_allocatable_units' => $totalAllocatable,
            'total_backorder_units' => $totalRequested - $totalAllocatable,
            'lines_fully_allocatable' => $linesFull,
            'lines_partial' => $linesPartial,
            'lines_missing' => $linesMissing,
            'shortages' => $shortages,
        ]);
    }

    /**
     * Decrease quantities in Stocks when WholesaleOut becomes active (status = 1)
     */
    /**
     * Increase quantities in Stocks when WholesaleOut becomes inactive (status = 0) - area-aware
     */
    private function addStocksForWholesaleOut(WholesaleOut $wholesaleOut): array
    {
        $warnings = [];

        // Ensure we load the records with their area relationships
        $wholesaleOut->load('records.wholesaleOutRecordsArea');

        foreach ($wholesaleOut->records as $record) {
            // Get the area quantities that were originally decremented
            $areaQuantities = $record->wholesaleOutRecordsArea;

            // Use shipped_quantity as source of truth for what was actually decremented
            $shippedQuantity = $record->shipped_quantity;

            $this->logInfo('Restoring stock for WholesaleOut record', [
                'wholesale_out_record_id' => $record->id,
                'record_id' => $record->record_id,
                'shipped_quantity' => $shippedQuantity,
                'area_quantities_count' => $areaQuantities->count(),
            ]);

            if ($areaQuantities->isEmpty()) {
                // Fallback: if no area quantities are found, use the old logic with stock_id
                $stock = Stock::find($record->stock_id);
                if ($stock) {
                    $stock->increment('quantity', $shippedQuantity);
                    $this->logInfo('Stock incremented (fallback) for inactive WholesaleOut.', [
                        'stock_id' => $stock->id,
                        'requested_quantity' => $record->quantity,
                        'shipped_quantity' => $shippedQuantity,
                        'quantity_incremented' => $shippedQuantity,
                    ]);
                } else {
                    $this->logWarning('Stock not found for restoring WholesaleOut record (fallback).', [
                        'stock_id' => $record->stock_id,
                        'wholesale_out_record_id' => $record->id,
                    ]);

                    // Add warning for missing stock
                    $recordModel = \App\Models\Record::with(['artist'])->find($record->record_id);
                    if ($recordModel) {
                        $recordInfo = $recordModel->title;
                        if ($recordModel->artist) {
                            $recordInfo = $recordModel->artist->name.' - '.$recordInfo;
                        }
                        if ($recordModel->cat_number) {
                            $recordInfo .= ' ('.$recordModel->cat_number.')';
                        } elseif ($recordModel->barcode) {
                            $recordInfo .= ' ('.$recordModel->barcode.')';
                        }
                        $warnings[] = "Impossibile ripristinare stock per {$recordInfo} (stock ID {$record->stock_id} non trovato)";
                    } else {
                        $warnings[] = "Impossibile ripristinare stock per Record ID {$record->record_id} (stock ID {$record->stock_id} non trovato)";
                    }
                }
            } else {
                // Area-aware restoration: restore into the SAME area(s) the stock was originally taken from,
                // instead of record.stock_id which may point to a different area (e.g. after reallocation).
                foreach ($areaQuantities as $areaQuantity) {
                    $warning = $this->restoreStockForArea(
                        $record->record_id,
                        $areaQuantity->area_id,
                        $areaQuantity->quantity
                    );

                    if ($warning) {
                        $warnings[] = $warning;
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * Update or create wholesale out record area entries
     */
    private function updateWholesaleOutRecordArea(int $wholesaleOutRecordId, int $areaId, int $quantity, bool $isUpdate = false): void
    {
        $this->logDetail('updateWholesaleOutRecordArea called', [
            'wholesale_out_record_id' => $wholesaleOutRecordId,
            'area_id' => $areaId,
            'quantity' => $quantity,
            'is_update' => $isUpdate,
        ]);

        // In single-area mode, we should only have ONE area assignment per wholesale_out_record
        // First, delete any OTHER area assignments (areas that are NOT the target area)
        $deletedCount = WholesaleOutRecordsArea::where('wholesale_out_records_id', $wholesaleOutRecordId)
            ->where('area_id', '!=', $areaId)
            ->delete();

        if ($deletedCount > 0) {
            $this->logInfo('Deleted other area assignments (area changed)', [
                'wholesale_out_record_id' => $wholesaleOutRecordId,
                'deleted_count' => $deletedCount,
                'kept_area_id' => $areaId,
            ]);
        }

        // Then, update or create the target area assignment
        $areaAssignment = WholesaleOutRecordsArea::updateOrCreate(
            [
                'wholesale_out_records_id' => $wholesaleOutRecordId,
                'area_id' => $areaId,
            ],
            [
                'quantity' => $quantity,
            ]
        );

        $this->logInfo('Area assignment updated/created', [
            'area_assignment_id' => $areaAssignment->id,
            'wholesale_out_record_id' => $wholesaleOutRecordId,
            'area_id' => $areaId,
            'quantity' => $quantity,
            'was_recently_created' => $areaAssignment->wasRecentlyCreated,
        ]);
    }

    /**
     * Update stock for a specific area (decrement for WholesaleOut)
     */
    private function updateStockForArea(int $recordId, int $areaId, int $quantity, bool $isUpdate, WholesaleOut $wholesaleOut, ?WholesaleOutRecord $wholesaleOutRecord = null): ?string
    {
        $this->logInfo('===== updateStockForArea CALLED =====', [
            'record_id' => $recordId,
            'area_id' => $areaId,
            'quantity' => $quantity,
            'is_update' => $isUpdate,
            'wholesale_out_id' => $wholesaleOut->id,
            'wholesale_out_status' => $wholesaleOut->status,
            'wholesale_out_record_id' => $wholesaleOutRecord?->id,
        ]);

        // DEBUG: Show all existing stocks for this record
        $allStocks = \App\Models\Stock::where('record_id', $recordId)->get();
        $this->logInfo('===== ALL STOCKS FOR RECORD =====', [
            'record_id' => $recordId,
            'total_stocks_found' => $allStocks->count(),
            'stocks_details' => $allStocks->map(function ($s) {
                return [
                    'stock_id' => $s->id,
                    'area_id' => $s->area_id,
                    'area_name' => $s->area->name ?? 'UNKNOWN',
                    'quantity' => $s->quantity,
                ];
            })->toArray(),
        ]);

        $stock = Stock::where('record_id', $recordId)->where('area_id', $areaId)->first();

        if (! $stock) {
            $this->logWarning('Stock not found for WholesaleOut area update', [
                'record_id' => $recordId,
                'area_id' => $areaId,
                'quantity' => $quantity,
            ]);

            // Get record and area information for user-friendly warning
            $record = \App\Models\Record::with(['artist'])->find($recordId);
            $area = \App\Models\Area::find($areaId);
            $areaName = $area ? $area->name : "Area ID {$areaId}";

            if ($record) {
                $recordInfo = $record->title;
                if ($record->artist) {
                    $recordInfo = $record->artist->name.' - '.$recordInfo;
                }
                if ($record->cat_number) {
                    $recordInfo .= ' ('.$record->cat_number.')';
                } elseif ($record->barcode) {
                    $recordInfo .= ' ('.$record->barcode.')';
                }

                // Create backorder for missing stock (entire quantity)
                if ($wholesaleOutRecord && $wholesaleOut->status === 1) {
                    $this->createBackorderForMissingStock($wholesaleOut, $wholesaleOutRecord, $areaId, $quantity);
                }

                return "Stock non trovato per {$recordInfo} in {$areaName} (quantità: {$quantity}) - Backorder creato";
            }

            // Create backorder for missing stock (entire quantity)
            if ($wholesaleOutRecord && $wholesaleOut->status === 1) {
                $this->createBackorderForMissingStock($wholesaleOut, $wholesaleOutRecord, $areaId, $quantity);
            }

            return "Stock non trovato per Record ID {$recordId} in {$areaName} (quantità: {$quantity}) - Backorder creato";
        }

        // DEBUG: Log what we're about to do
        $this->logInfo('BEFORE stock update for WholesaleOut', [
            'record_id' => $recordId,
            'area_id' => $areaId,
            'current_stock_quantity' => $stock->quantity,
            'quantity_to_subtract' => $quantity,
            'is_update' => $isUpdate,
        ]);

        if ($quantity > $stock->quantity) {
            $this->logWarning('Insufficient stock for WholesaleOut area update', [
                'record_id' => $recordId,
                'area_id' => $areaId,
                'requested_quantity' => $quantity,
                'available_stock' => $stock->quantity,
            ]);

            // Calculate missing quantity
            $missingQuantity = $quantity - $stock->quantity;
            $availableQuantity = $stock->quantity;

            // Get record and area information for user-friendly warning
            $record = \App\Models\Record::with(['artist'])->find($recordId);
            $area = \App\Models\Area::find($areaId);
            $areaName = $area ? $area->name : "Area ID {$areaId}";

            if ($record) {
                $recordInfo = $record->title;
                if ($record->artist) {
                    $recordInfo = $record->artist->name.' - '.$recordInfo;
                }
                if ($record->cat_number) {
                    $recordInfo .= ' ('.$record->cat_number.')';
                } elseif ($record->barcode) {
                    $recordInfo .= ' ('.$record->barcode.')';
                }

                // Create backorder for missing quantity only
                if ($wholesaleOutRecord && $wholesaleOut->status === 1) {
                    $this->createBackorderForMissingStock($wholesaleOut, $wholesaleOutRecord, $areaId, $missingQuantity);
                }

                // Use all available stock if any exists
                if ($availableQuantity > 0) {
                    $stock->decrement('quantity', $availableQuantity);

                    return "Stock insufficiente per {$recordInfo} in {$areaName} (richiesto: {$quantity}, disponibile: {$availableQuantity}, mancante: {$missingQuantity}) - Backorder creato per {$missingQuantity}";
                }

                return "Stock insufficiente per {$recordInfo} in {$areaName} (richiesto: {$quantity}, disponibile: {$availableQuantity}) - Backorder creato per {$missingQuantity}";
            }

            // Create backorder for missing quantity only
            if ($wholesaleOutRecord && $wholesaleOut->status === 1) {
                $this->createBackorderForMissingStock($wholesaleOut, $wholesaleOutRecord, $areaId, $missingQuantity);
            }

            // Use all available stock if any exists
            if ($availableQuantity > 0) {
                $stock->decrement('quantity', $availableQuantity);

                return "Stock insufficiente per Record ID {$recordId} in {$areaName} (richiesto: {$quantity}, disponibile: {$availableQuantity}, mancante: {$missingQuantity}) - Backorder creato per {$missingQuantity}";
            }

            return "Stock insufficiente per Record ID {$recordId} in {$areaName} (richiesto: {$quantity}, disponibile: {$availableQuantity}) - Backorder creato per {$missingQuantity}";
        }

        // Decrement stock for WholesaleOut
        $stock->decrement('quantity', $quantity);

        // DEBUG: Log what happened
        $stock->refresh(); // Reload from database
        $this->logInfo('AFTER stock update for WholesaleOut', [
            'record_id' => $recordId,
            'area_id' => $areaId,
            'new_stock_quantity' => $stock->quantity,
            'quantity_subtracted' => $quantity,
        ]);

        $this->logInfo('Stock decremented for WholesaleOut area', [
            'stock_id' => $stock->id,
            'area_id' => $areaId,
            'quantity_decremented' => $quantity,
            'remaining_stock' => $stock->quantity,
        ]);

        return null; // No warning
    }

    /**
     * Allocate stock and update shipped_quantity
     * Returns array with 'allocated' quantity and optional 'warning' message
     */
    private function allocateStockAndUpdateShippedQuantity(
        WholesaleOutRecord $wholesaleOutRecord,
        int $recordId,
        int $areaId,
        int $requestedQuantity,
        bool $isUpdate,
        WholesaleOut $wholesaleOut
    ): array {
        $stock = Stock::where('record_id', $recordId)->where('area_id', $areaId)->first();

        if (! $stock) {
            // No stock found - create backorder for entire quantity
            $this->createBackorderForMissingStock($wholesaleOut, $wholesaleOutRecord, $areaId, $requestedQuantity);
            $wholesaleOutRecord->shipped_quantity = $this->determineShippedQuantity($wholesaleOutRecord, 'allocate', 0);
            $wholesaleOutRecord->save();

            $record = \App\Models\Record::with(['artist'])->find($recordId);
            $area = \App\Models\Area::find($areaId);
            $areaName = $area ? $area->name : "Area ID {$areaId}";
            $recordInfo = $this->formatRecordInfo($record);

            return [
                'allocated' => 0,
                'warning' => "Stock non trovato per {$recordInfo} in {$areaName} (quantità: {$requestedQuantity}) - Backorder creato",
            ];
        }

        $availableStock = $stock->quantity;

        if ($requestedQuantity > $availableStock) {
            // Insufficient stock - allocate what's available, backorder the rest
            $allocatedQuantity = $availableStock;
            $backorderQuantity = $requestedQuantity - $availableStock;

            if ($allocatedQuantity > 0) {
                $stock->decrement('quantity', $allocatedQuantity);
            }

            $this->createBackorderForMissingStock($wholesaleOut, $wholesaleOutRecord, $areaId, $backorderQuantity);
            $wholesaleOutRecord->shipped_quantity = $this->determineShippedQuantity($wholesaleOutRecord, 'allocate', $allocatedQuantity);
            $wholesaleOutRecord->save();

            $record = \App\Models\Record::with(['artist'])->find($recordId);
            $area = \App\Models\Area::find($areaId);
            $areaName = $area ? $area->name : "Area ID {$areaId}";
            $recordInfo = $this->formatRecordInfo($record);

            return [
                'allocated' => $allocatedQuantity,
                'warning' => "Stock insufficiente per {$recordInfo} in {$areaName} (richiesto: {$requestedQuantity}, disponibile: {$availableStock}) - Backorder creato per {$backorderQuantity}",
            ];
        }

        // Sufficient stock - allocate full amount
        $stock->decrement('quantity', $requestedQuantity);
        $wholesaleOutRecord->shipped_quantity = $this->determineShippedQuantity($wholesaleOutRecord, 'allocate', $requestedQuantity);
        $wholesaleOutRecord->save();

        return [
            'allocated' => $requestedQuantity,
            'warning' => null,
        ];
    }

    /**
     * Format record info for user-friendly messages
     */
    private function formatRecordInfo(?\App\Models\Record $record): string
    {
        if (! $record) {
            return 'Unknown Record';
        }

        $recordInfo = $record->title;
        if ($record->artist) {
            $recordInfo = $record->artist->name.' - '.$recordInfo;
        }
        if ($record->cat_number) {
            $recordInfo .= ' ('.$record->cat_number.')';
        } elseif ($record->barcode) {
            $recordInfo .= ' ('.$record->barcode.')';
        }

        return $recordInfo;
    }

    /**
     * Restore stock for a specific area (increment for WholesaleOut revert)
     */
    private function restoreStockForArea(int $recordId, int $areaId, int $quantity): ?string
    {
        $stock = Stock::where('record_id', $recordId)->where('area_id', $areaId)->first();

        if (! $stock) {
            $this->logWarning('Stock not found for WholesaleOut area restoration', [
                'record_id' => $recordId,
                'area_id' => $areaId,
                'quantity' => $quantity,
            ]);

            // Get record and area information for user-friendly warning
            $record = \App\Models\Record::with(['artist'])->find($recordId);
            $area = \App\Models\Area::find($areaId);
            $areaName = $area ? $area->name : "Area ID {$areaId}";

            if ($record) {
                $recordInfo = $record->title;
                if ($record->artist) {
                    $recordInfo = $record->artist->name.' - '.$recordInfo;
                }
                if ($record->cat_number) {
                    $recordInfo .= ' ('.$record->cat_number.')';
                } elseif ($record->barcode) {
                    $recordInfo .= ' ('.$record->barcode.')';
                }

                return "Impossibile ripristinare stock per {$recordInfo} in {$areaName} (stock non trovato)";
            }

            return "Impossibile ripristinare stock per Record ID {$recordId} in {$areaName} (stock non trovato)";
        }

        // DEBUG: Log what we're about to do
        $this->logInfo('BEFORE stock restoration for WholesaleOut', [
            'record_id' => $recordId,
            'area_id' => $areaId,
            'current_stock_quantity' => $stock->quantity,
            'quantity_to_add' => $quantity,
        ]);

        // Increment stock for WholesaleOut restoration
        $stock->increment('quantity', $quantity);

        // DEBUG: Log what happened
        $stock->refresh(); // Reload from database
        $this->logInfo('AFTER stock restoration for WholesaleOut', [
            'record_id' => $recordId,
            'area_id' => $areaId,
            'new_stock_quantity' => $stock->quantity,
            'quantity_added' => $quantity,
        ]);

        $this->logInfo('Stock incremented for WholesaleOut area restoration', [
            'stock_id' => $stock->id,
            'area_id' => $areaId,
            'quantity_incremented' => $quantity,
            'new_stock_quantity' => $stock->quantity,
        ]);

        return null; // No warning
    }

    /**
     * Handle status change from inactive (0) to active (1)
     * When this happens, process existing area records and update stock
     */
    private function handleStatusChange(WholesaleOut $wholesaleOut, int $newStatus, int $oldStatus): void
    {
        // Only handle the transition from inactive (0) to active (1)
        if ($oldStatus === 0 && $newStatus === 1) {
            $this->logInfo('Handling status change from inactive to active', [
                'wholesale_out_id' => $wholesaleOut->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            // Get all records with their area distributions
            $records = $wholesaleOut->records()->with('wholesaleOutRecordsArea.area')->get();

            foreach ($records as $record) {
                $areaRecords = $record->wholesaleOutRecordsArea;

                if ($areaRecords->isNotEmpty()) {
                    $this->logInfo('Processing area records for stock update', [
                        'record_id' => $record->id,
                        'area_records_count' => $areaRecords->count(),
                    ]);

                    foreach ($areaRecords as $areaRecord) {
                        $this->updateStockForArea(
                            $record->record_id,
                            $areaRecord->area_id,
                            $areaRecord->quantity,
                            false, // not an update operation
                            $wholesaleOut,
                            $record
                        );
                    }
                } else {
                    // No area records, use the main record quantity and area
                    $this->logInfo('No area records found, using main record data', [
                        'record_id' => $record->id,
                        'quantity' => $record->quantity,
                        'stock_id' => $record->stock_id,
                    ]);

                    $stock = Stock::find($record->stock_id);
                    if ($stock) {
                        // Use updateStockForArea to ensure proper stock management and backorder creation
                        $warning = $this->updateStockForArea(
                            $record->record_id,
                            $stock->area_id,
                            $record->quantity,
                            false, // not an update operation
                            $wholesaleOut,
                            $record
                        );
                        if ($warning) {
                            $warnings[] = $warning;
                        }
                    }
                }
            }
        }
    }

    /**
     * Handle status change from inactive (0) to active (1) with area-aware stock updates
     */
    private function handleStatusChangeToActive(WholesaleOut $wholesaleOut): array
    {
        $warnings = [];

        foreach ($wholesaleOut->records as $record) {
            // Get area quantities for this record
            $areaRecords = $record->wholesaleOutRecordsArea;

            if ($areaRecords->isNotEmpty()) {
                // Simplified mode: use the area from wholesale_out_records_area
                $this->logInfo('Processing stock allocation for status change to active', [
                    'record_id' => $record->record_id,
                    'stock_id' => $record->stock_id,
                    'requested_quantity' => $record->quantity,
                    'area_records_count' => $areaRecords->count(),
                ]);

                // Get the target area from the first area record (single area mode)
                $targetAreaId = $areaRecords->first()->area_id;

                // Use allocateStockAndUpdateShippedQuantity to properly handle stock allocation
                $result = $this->allocateStockAndUpdateShippedQuantity(
                    $record,
                    $record->record_id,
                    $targetAreaId,
                    $record->quantity,
                    false, // isUpdate
                    $wholesaleOut
                );

                if (isset($result['warning'])) {
                    $warnings[] = $result['warning'];
                }

                // Update area assignment with shipped quantity (not requested)
                $record->wholesaleOutRecordsArea()->updateOrCreate([
                    'area_id' => $targetAreaId,
                ], [
                    'quantity' => $result['allocated'],
                ]);

                $this->logInfo('Stock allocated for status change with shipped_quantity', [
                    'target_area_id' => $targetAreaId,
                    'requested_quantity' => $record->quantity,
                    'shipped_quantity' => $result['allocated'],
                    'warning' => $result['warning'] ?? null,
                ]);
            } else {
                // Fallback to WholesaleOut's default area if no area records
                if ($wholesaleOut->area_id) {
                    $this->logInfo('No area records found, using WholesaleOut default area for status change', [
                        'record_id' => $record->record_id,
                        'wholesale_out_area_id' => $wholesaleOut->area_id,
                        'requested_quantity' => $record->quantity,
                    ]);

                    // Use allocateStockAndUpdateShippedQuantity with WholesaleOut's default area
                    $result = $this->allocateStockAndUpdateShippedQuantity(
                        $record,
                        $record->record_id,
                        $wholesaleOut->area_id,
                        $record->quantity,
                        false, // isUpdate
                        $wholesaleOut
                    );

                    if (isset($result['warning'])) {
                        $warnings[] = $result['warning'];
                    }

                    // Create area allocation record with shipped quantity
                    $record->wholesaleOutRecordsArea()->updateOrCreate([
                        'area_id' => $wholesaleOut->area_id,
                    ], [
                        'quantity' => $result['allocated'],
                    ]);

                    $this->logInfo('Stock allocated (WholesaleOut area fallback) for status change', [
                        'fallback_area_id' => $wholesaleOut->area_id,
                        'requested_quantity' => $record->quantity,
                        'shipped_quantity' => $result['allocated'],
                        'warning' => $result['warning'] ?? null,
                    ]);
                } else {
                    // No WholesaleOut area_id set either, create user-friendly warning
                    $recordModel = \App\Models\Record::with(['artist'])->find($record->record_id);
                    if ($recordModel) {
                        $recordInfo = $recordModel->title;
                        if ($recordModel->artist) {
                            $recordInfo = $recordModel->artist->name.' - '.$recordInfo;
                        }
                        if ($recordModel->cat_number) {
                            $recordInfo .= ' ('.$recordModel->cat_number.')';
                        } elseif ($recordModel->barcode) {
                            $recordInfo .= ' ('.$recordModel->barcode.')';
                        }
                        $warnings[] = "Stock non trovato per {$recordInfo} - Nessuna area di default configurata";
                    } else {
                        $warnings[] = "Stock non trovato per Record ID {$record->record_id} - Nessuna area di default configurata";
                    }

                    // Set shipped_quantity to 0 for failed allocations
                    $record->shipped_quantity = 0;
                    $record->save();
                }
            }
        }

        return $warnings;
    }

    /**
     * Restore stock for an existing record being updated (restores the previously allocated quantity)
     */
    private function restoreStockForExistingRecord(WholesaleOutRecord $wholesaleOutRecord, int $originalQuantity): void
    {
        // Get all area allocations for this record and restore the stock
        $areaAllocations = $wholesaleOutRecord->wholesaleOutRecordsArea;

        foreach ($areaAllocations as $areaAllocation) {
            $this->restoreStockForArea(
                $wholesaleOutRecord->record_id,
                $areaAllocation->area_id,
                $areaAllocation->quantity
            );
        }

        // Clear existing area allocations since we're updating them
        $wholesaleOutRecord->wholesaleOutRecordsArea()->delete();

        // IMPORTANT: Also clean up any existing backorder entries for this record
        // when changing areas, we need to remove old backorder entries
        $this->cleanupBackorderEntriesForRecord($wholesaleOutRecord);

        $this->logInfo('Restored stock for existing record update', [
            'wholesale_out_record_id' => $wholesaleOutRecord->id,
            'original_quantity' => $originalQuantity,
            'area_allocations_count' => $areaAllocations->count(),
        ]);
    }

    /**
     * Clean up existing backorder entries for a record when updating area allocations
     */
    private function cleanupBackorderEntriesForRecord(WholesaleOutRecord $wholesaleOutRecord): void
    {
        // Find any existing backorder records for this wholesale out record
        $backorderRecords = \App\Models\BackorderRecord::where('wholesale_out_record_id', $wholesaleOutRecord->id)->get();

        foreach ($backorderRecords as $backorderRecord) {
            // Delete all area entries for this backorder record
            $backorderRecord->backorderRecordsAreas()->delete();

            // Delete the backorder record itself
            $backorderRecord->delete();

            $this->logInfo('Cleaned up backorder record', [
                'backorder_record_id' => $backorderRecord->id,
                'wholesale_out_record_id' => $wholesaleOutRecord->id,
            ]);
        }

        // Clean up any empty backorders (backorders with no records left)
        $wholesaleOut = $wholesaleOutRecord->wholesaleOut;
        if ($wholesaleOut) {
            $emptyBackorders = $wholesaleOut->backorders()
                ->whereDoesntHave('backorderRecords')
                ->where('status', 0) // Only pending backorders
                ->get();

            foreach ($emptyBackorders as $emptyBackorder) {
                $emptyBackorder->delete();
                $this->logInfo('Cleaned up empty backorder', [
                    'backorder_id' => $emptyBackorder->id,
                    'wholesale_out_id' => $wholesaleOut->id,
                ]);
            }
        }
    }

    /**
     * Save or update label discounts for a WholesaleOut
     */
    private function saveLabelDiscounts(WholesaleOut $wholesaleOut, array $labelDiscounts): void
    {
        // Delete existing label discounts for this wholesale out
        $wholesaleOut->labelDiscounts()->delete();

        // Save new label discounts
        foreach ($labelDiscounts as $labelDiscount) {
            if (isset($labelDiscount['label_id']) && isset($labelDiscount['discount']) && $labelDiscount['discount'] > 0) {
                $wholesaleOut->labelDiscounts()->create([
                    'label_id' => $labelDiscount['label_id'],
                    'discount' => $labelDiscount['discount'],
                ]);
            }
        }
    }

    /**
     * Create a backorder for missing stock quantity
     */
    private function createBackorderForMissingStock(WholesaleOut $wholesaleOut, WholesaleOutRecord $wholesaleOutRecord, int $areaId, int $missingQuantity): void
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
            'wholesale_out_record_id' => $wholesaleOutRecord->id,
            'area_id' => $areaId,
            'missing_quantity' => $missingQuantity,
            'backorder_id' => $backorder->id,
            'backorder_record_id' => $backorderRecord->id,
        ]);
    }

    /**
     * Backfill shipped_quantity for legacy records where shipped_quantity = 0
     * This happens for old records created before the shipped_quantity column was added
     */
    private function backfillShippedQuantityForLegacyRecords(WholesaleOut $wholesaleOut): void
    {
        // Only backfill for active WholesaleOuts
        if ($wholesaleOut->status !== 1) {
            return;
        }

        foreach ($wholesaleOut->records as $record) {
            // Check if shipped_quantity needs backfilling (0 means not yet populated)
            // Note: we check the database value directly, not the accessor
            if ($record->getAttributes()['shipped_quantity'] == 0 && $record->quantity > 0) {
                // Use inference strategy to calculate from historical data
                $calculatedShipped = $this->determineShippedQuantity($record, 'infer');

                // Store it in the database
                $record->update(['shipped_quantity' => $calculatedShipped]);

                $this->logInfo('Backfilled shipped_quantity for legacy record', [
                    'wholesale_out_record_id' => $record->id,
                    'requested_quantity' => $record->quantity,
                    'calculated_shipped' => $calculatedShipped,
                    'strategy' => 'infer',
                ]);
            }
        }
    }

    /**
     * Determine shipped quantity using the appropriate strategy
     *
     * This method provides a single source of truth for shipped_quantity calculation,
     * but acknowledges that there are two valid strategies depending on context:
     *
     * - 'allocate': Use the actual quantity allocated from stock during active allocation
     *               This is AUTHORITATIVE - we know exactly what was taken from stock
     *
     * - 'infer': Calculate from historical data (requested - backordered)
     *            This is INFERENTIAL - reconstruct what was shipped from existing records
     *
     * @param  WholesaleOutRecord  $record  The record to calculate shipped quantity for
     * @param  string  $mode  Either 'allocate' or 'infer'
     * @param  int|null  $allocatedQuantity  Required when mode='allocate', the actual quantity allocated from stock
     * @return int The shipped quantity
     *
     * @throws \InvalidArgumentException If mode='allocate' but allocatedQuantity is null
     */
    private function determineShippedQuantity(
        WholesaleOutRecord $record,
        string $mode = 'infer',
        ?int $allocatedQuantity = null
    ): int {
        if ($mode === 'allocate') {
            // Strategy 1: We're actively allocating stock - use what was ACTUALLY allocated
            if ($allocatedQuantity === null) {
                throw new \InvalidArgumentException('allocatedQuantity is required when mode is "allocate"');
            }

            return $allocatedQuantity;
        }

        if ($mode === 'infer') {
            // Strategy 2: Infer from historical data - calculate from backorders
            return $this->calculateShippedQuantity($record);
        }

        throw new \InvalidArgumentException("Invalid mode: {$mode}. Must be 'allocate' or 'infer'");
    }

    /**
     * Calculate shipped quantity for a record: requested - backordered
     * This is the shared calculation logic used for inferring shipped quantity from historical data
     *
     * IMPORTANT: Only counts PENDING backorders (status = 0), not activated ones (status = 1)
     * Activated backorders have already been fulfilled and shouldn't reduce the shipped quantity
     */
    private function calculateShippedQuantity(WholesaleOutRecord $record): int
    {
        // Only count pending backorders - activated ones have already been fulfilled
        $backorderedQuantity = $record->backorderRecords()
            ->whereHas('backorder', function ($query) {
                $query->where('status', 0); // Only pending backorders
            })
            ->sum('quantity');

        return max(0, $record->quantity - $backorderedQuantity);
    }

    /**
     * @deprecated No longer needed - use $wholesaleOutRecord->shipped_quantity directly
     *
     * Calculate the actually decremented quantity for a WholesaleOutRecord
     * This is the requested quantity minus any backorder quantities
     *
     * OBSOLETE: This method calculated shipped quantity on-the-fly by subtracting backorders.
     * Now we store shipped_quantity directly in the database for accuracy and performance.
     */
    private function calculateActuallyDecrementedQuantity(WholesaleOutRecord $wholesaleOutRecord): int
    {
        $requestedQuantity = $wholesaleOutRecord->quantity;

        // Find any backorder records for this wholesale out record
        $backorderRecords = \App\Models\BackorderRecord::where('wholesale_out_record_id', $wholesaleOutRecord->id)->get();
        $backorderQuantity = $backorderRecords->sum('quantity');

        $actuallyDecrementedQuantity = $requestedQuantity - $backorderQuantity;

        // Ensure we never return a negative value
        return max(0, $actuallyDecrementedQuantity);
    }

    /**
     * Update records without stock changes (for active WholesaleOuts)
     * The reconciliation service will handle stock changes separately
     * Note: area_quantities are already normalized in saveWholesaleOutWithRecords()
     */
    private function updateRecordsWithoutStockChanges(WholesaleOut $wholesaleOut, array $records): float
    {
        $totalPrice = 0;

        // Handle existing records for update (delete removed records)
        if ($wholesaleOut->exists) {
            $this->handleExistingRecordsForUpdate($wholesaleOut, $records);
        }

        foreach ($records as $position => $record) {
            $totalPrice += ($record['total_price'] ?? 0);

            if (isset($record['id']) && $record['id']) {
                // Update existing record - only update fields, no stock changes
                $existingRecord = WholesaleOutRecord::find($record['id']);
                if ($existingRecord) {
                    $existingRecord->update([
                        'position' => $position,
                        'quantity' => $record['quantity'],
                        'unit_price' => $record['unit_price'],
                        'discount' => $record['discount'] ?? 0,
                        'total_price' => (float) $record['total_price'],
                        'vat' => $record['vat'] ?? 22,
                    ]);

                    // Update area quantities to reflect user's intended allocation
                    // Reconciliation service will correct these to actual allocated quantities
                    // Note: area_quantities are already normalized at the beginning of this method
                    if (isset($record['area_quantities']) && is_array($record['area_quantities']) && count($record['area_quantities']) > 0) {
                        $this->updateRecordAreaQuantitiesWithoutStock($existingRecord, $record['area_quantities']);
                    }
                }
            } else {
                // Create new record - let reconciliation service handle stock allocation
                $newRecord = $wholesaleOut->records()->create([
                    'position' => $position,
                    'record_id' => $record['record_id'],
                    'stock_id' => $record['stock_id'] ?? null,
                    'quantity' => $record['quantity'],
                    'unit_price' => $record['unit_price'],
                    'discount' => $record['discount'] ?? 0,
                    'total_price' => (float) $record['total_price'],
                    'vat' => $record['vat'] ?? 22,
                ]);

                // Add area quantities if provided (already normalized at beginning of method)
                if (isset($record['area_quantities']) && is_array($record['area_quantities']) && count($record['area_quantities']) > 0) {
                    $this->updateRecordAreaQuantitiesWithoutStock($newRecord, $record['area_quantities']);
                }
            }
        }

        $this->logInfo('Records updated without stock changes', [
            'wholesale_out_id' => $wholesaleOut->id,
            'total_price' => $totalPrice,
        ]);

        return $totalPrice;
    }

    /**
     * Update record area quantities without affecting stock
     */
    private function updateRecordAreaQuantitiesWithoutStock(WholesaleOutRecord $record, array $areaQuantities): void
    {
        $this->logInfo('Updating record area quantities without stock changes', [
            'record_id' => $record->id,
            'area_quantities' => $areaQuantities,
        ]);

        // Clear existing area quantities
        $record->wholesaleOutRecordsArea()->delete();

        // Add new area quantities (no stock changes)
        foreach ($areaQuantities as $areaQuantity) {
            if (($areaQuantity['quantity'] ?? 0) > 0) {
                $record->wholesaleOutRecordsArea()->create([
                    'area_id' => $areaQuantity['area_id'],
                    'quantity' => $areaQuantity['quantity'],
                ]);

                $this->logDetail('Created area quantity record', [
                    'record_id' => $record->id,
                    'area_id' => $areaQuantity['area_id'],
                    'quantity' => $areaQuantity['quantity'],
                ]);
            }
        }
    }
}
