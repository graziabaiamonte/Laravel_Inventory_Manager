<?php

namespace App\Http\Controllers;

use App\Enums\StatesEnum;
use App\Exports\BackorderExport;
use App\Http\Requests\BackorderRequest;
use App\Http\Resources\BackorderRecordResource;
use App\Http\Resources\BackorderResource;
use App\Http\Resources\ComboResource;
use App\Models\Area;
use App\Models\Backorder;
use App\Models\BackorderRecordsArea;
use App\Models\Customer;
use App\Models\Stock;
use App\Models\WholesaleOut;
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

class BackorderController extends Controller
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'wholesale_out';
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $baseQuery = QueryBuilder::for(Backorder::class)
            ->filterByAdminRoles()
            ->join('wholesale_outs', 'backorders.wholesale_out_id', '=', 'wholesale_outs.id')
            ->join('customers', 'wholesale_outs.customer_id', '=', 'customers.id')
            ->select('backorders.*', 'customers.name as customer_name')
            ->with(['wholesaleOut.customer'])
            ->orderBy('created_at', 'desc')
            ->allowedFilters([
                AllowedFilter::callback('search', function ($query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['customers.name']);
                }),
                AllowedFilter::callback('customer_id', function ($query, $value) {
                    $query->where('wholesale_outs.customer_id', $value);
                }),
                AllowedFilter::callback('date', function (Builder $query, $value) {
                    // Add time to make the date range inclusive of the entire day
                    $startDate = $value['startDate'].' 00:00:00';
                    $endDate = $value['endDate'].' 23:59:59';
                    $query->whereBetween('backorders.created_at', [$startDate, $endDate]);
                }),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('wholesale_out_id'),

            ]);

        return Inertia::render('Backorder/Index', [
            'backorders' => BackorderResource::collection($baseQuery->paginate($this->perPage($request))),
            'applied_filters' => $request->get('filter', []),
            'status_list' => StatesEnum::getJsonValues(),
            'customers' => ComboResource::collection(Customer::where('status', 1)->get()),
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Export the specified backorder as Excel file.
     */
    public function export(Backorder $backorder)
    {
        $docNum = $backorder->wholesaleOut->doc_num ?? '';
        $cleanDocNum = preg_replace('/[^a-zA-Z0-9\s]/', '', $docNum); // Remove special characters
        $cleanDocNum = preg_replace('/\s+/', '-', trim($cleanDocNum)); // Replace whitespaces with dashes

        $filename = 'Backorder_'.$cleanDocNum.'_'.$backorder->created_at->format('dmY').'.xlsx';

        return Excel::download(new BackorderExport($backorder), $filename);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Backorder $backorder)
    {
        // Load necessary relationships for the backorder
        $backorder->load([
            'wholesaleOut.customer',
            'backorderRecords.backorder', // For checking backorder status in shipped quantity calculation
            'backorderRecords.wholesaleOutRecord.parentRecord.artist',
            'backorderRecords.wholesaleOutRecord.parentRecord.format',
            'backorderRecords.wholesaleOutRecord.parentRecord.label',
            'backorderRecords.wholesaleOutRecord.parentRecord.stocks', // For calculating available_quantity
            'backorderRecords.wholesaleOutRecord.stock',
            'backorderRecords.wholesaleOutRecord.backorderRecords', // For calculating shipped quantity
            'backorderRecords.backorderRecordsAreas.area',
        ]);

        // Get imported records from session (similar to WholesaleOutController)
        $importedRecords = $backorder->backorderRecords;

        $warehouseAreas = Area::filterByAdminRoles()->defaultWarehouseAreas()->get();

        return Inertia::render('Backorder/Edit', [
            'backOrder' => new BackorderResource($backorder),
            'areas' => ComboResource::collection($warehouseAreas),
            'importedRecords' => ! empty($importedRecords) ? BackorderRecordResource::collection($importedRecords) : null,
            'importWarnings' => session('importWarnings', []),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(BackorderRequest $request, Backorder $backorder)
    {

        $oldStatus = $backorder->status;

        $validated = $request->validated();

        $status = intval($validated['status']) ?? 0;

        $backorder->update([
            'status' => $status,
        ]);

        // Handle backorder activation - check stock availability and create new backorders for missing quantities
        if ($oldStatus !== 1 && $status === 1) {
            $this->processBackorderActivation($backorder);
        }

        if (isset($validated['records'])) {
            foreach ($validated['records'] as $record) {

                if (! empty($record['area_quantities'])) {
                    foreach ($record['area_quantities'] as $areaQuantity) {

                        // $idArea = intval($areaQuantity['id']) ?? null;

                        $backorderRecordId = intval($record['id']) ?? null;

                        // $backorderRecordArea = BackorderRecordsArea::find($idArea);
                        $backorderRecordArea = BackorderRecordsArea::where('backorder_records_id', $backorderRecordId)->first();

                        if ($backorderRecordArea) {
                            $backorderRecordArea->update([
                                'area_id' => intval($areaQuantity['area_id']),
                            ]);
                        }

                    }
                }

            }
        }

        // Determine redirect based on whether there are warnings to show
        $warnings = session()->get('importWarnings', []);

        if (! empty($warnings)) {
            // If there are warnings from activation, redirect back to edit page to show them
            return redirect()
                ->route('backorder.edit', $backorder->id)
                ->with('success', 'Backorder aggiornato con successo. Controllare le informazioni sotto per i dettagli.')
                ->with('importWarnings', $warnings);
        } else {
            // No warnings, safe to redirect to index with just a success message
            return redirect()
                ->route('backorder.edit', $backorder->id)
                ->with('success', 'Backorder aggiornato con successo.');
        }

    }

    /**
     * Validate stock availability for a record (only when status = 1)
     */
    private function validateStockAvailability(int $stockId, int $requestedQuantity, WholesaleOut $backorder): Stock
    {
        $stock = Stock::find($stockId);
        if (! $stock) {
            throw new \Exception("Stock not found with ID: {$stockId}");
        }

        // Only validate stock availability if WholesaleOut is active (status = 1)
        if ($backorder->status === 1 && $stock->quantity < $requestedQuantity) {
            throw new \Exception("Insufficient stock for Stock ID: {$stockId}. Available: {$stock->quantity}, Requested: {$requestedQuantity}");
        }

        return $stock;
    }

    /**
     * Process backorder activation - check stock availability and create new backorders for missing quantities
     */
    private function processBackorderActivation(Backorder $backorder): void
    {
        // Load all backorder records with their areas and wholesale out records
        $backorder->load([
            'backorderRecords.backorderRecordsAreas.area',
            'backorderRecords.wholesaleOutRecord.parentRecord.artist',
            'backorderRecords.wholesaleOutRecord.stock',
            'wholesaleOut',
        ]);

        $newBackordersCreated = [];
        $fulfilledRecords = [];
        $partiallyFulfilledRecords = [];

        foreach ($backorder->backorderRecords as $backorderRecord) {
            $this->processBackorderRecordActivation(
                $backorderRecord,
                $backorder,
                $newBackordersCreated,
                $fulfilledRecords,
                $partiallyFulfilledRecords
            );
        }

        // Log summary of what happened
        $this->logInfo('Backorder activation completed', [
            'backorder_id' => $backorder->id,
            'wholesale_out_id' => $backorder->wholesale_out_id,
            'new_backorders_created' => count($newBackordersCreated),
            'fully_fulfilled_records' => count($fulfilledRecords),
            'partially_fulfilled_records' => count($partiallyFulfilledRecords),
        ]);

        // Store results in session for user feedback
        if (! empty($newBackordersCreated) || ! empty($partiallyFulfilledRecords)) {
            $warnings = [];

            foreach ($partiallyFulfilledRecords as $record) {
                $warnings[] = $record['message'];
            }

            if (! empty($newBackordersCreated)) {
                $warnings[] = 'Creati '.count($newBackordersCreated).' nuovi backorders per le quantità mancanti.';
            }

            session()->put('importWarnings', $warnings);
        }
    }

    /**
     * Process individual backorder record activation
     */
    private function processBackorderRecordActivation(
        \App\Models\BackorderRecord $backorderRecord,
        Backorder $backorder,
        array &$newBackordersCreated,
        array &$fulfilledRecords,
        array &$partiallyFulfilledRecords
    ): void {
        $wholesaleOutRecord = $backorderRecord->wholesaleOutRecord;
        $requestedQuantity = $backorderRecord->quantity;

        // Get record information for user-friendly messages
        $record = $wholesaleOutRecord->parentRecord;
        $recordInfo = $record->title;
        if ($record->artist) {
            $recordInfo = $record->artist->name.' - '.$recordInfo;
        }
        if ($record->cat_number) {
            $recordInfo .= ' ('.$record->cat_number.')';
        } elseif ($record->barcode) {
            $recordInfo .= ' ('.$record->barcode.')';
        }

        // Process each area for this backorder record
        foreach ($backorderRecord->backorderRecordsAreas as $backorderArea) {
            $areaId = $backorderArea->area_id;
            $areaQuantity = $backorderArea->quantity;
            $areaName = $backorderArea->area->name;

            // Check stock availability in this area
            $stock = \App\Models\Stock::where('record_id', $record->id)
                ->where('area_id', $areaId)
                ->first();

            if (! $stock) {
                // No stock found - create new backorder for full quantity
                // Backorder not fulfilled at all
                $backorderRecord->shipped_quantity = 0;
                $backorderRecord->save();

                $this->createNewBackorderForMissingStock(
                    $backorder->wholesaleOut,
                    $wholesaleOutRecord,
                    $areaId,
                    $areaQuantity,
                    $newBackordersCreated
                );

                $partiallyFulfilledRecords[] = [
                    'record_info' => $recordInfo,
                    'area_name' => $areaName,
                    'requested' => $areaQuantity,
                    'available' => 0,
                    'message' => "Stock non trovato per {$recordInfo} in {$areaName} (quantità: {$areaQuantity}) - Nuovo backorder creato",
                ];

                continue;
            }

            if ($stock->quantity >= $areaQuantity) {
                // Sufficient stock available - fulfill the backorder
                $stock->decrement('quantity', $areaQuantity);

                // Track what was shipped for THIS backorder (historical record)
                $backorderRecord->shipped_quantity = $areaQuantity;
                $backorderRecord->save();

                $fulfilledRecords[] = [
                    'record_info' => $recordInfo,
                    'area_name' => $areaName,
                    'quantity' => $areaQuantity,
                ];

                $this->logInfo('Backorder record fulfilled', [
                    'backorder_record_id' => $backorderRecord->id,
                    'area_id' => $areaId,
                    'quantity_fulfilled' => $areaQuantity,
                    'remaining_stock' => $stock->quantity,
                    'backorder_shipped_quantity' => $areaQuantity,
                    'wholesale_out_record_total_shipped' => $wholesaleOutRecord->shipped_quantity,
                ]);

            } else {
                // Partial stock available - use what's available and create new backorder for missing quantity
                $availableQuantity = $stock->quantity;
                $missingQuantity = $areaQuantity - $availableQuantity;

                if ($availableQuantity > 0) {
                    // Use available stock
                    $stock->decrement('quantity', $availableQuantity);

                    // Track partial shipment for THIS backorder (historical record)
                    $backorderRecord->shipped_quantity = $availableQuantity;
                    $backorderRecord->save();

                    $this->logInfo('Backorder record partially fulfilled', [
                        'backorder_record_id' => $backorderRecord->id,
                        'area_id' => $areaId,
                        'requested' => $areaQuantity,
                        'available_fulfilled' => $availableQuantity,
                        'missing' => $missingQuantity,
                        'backorder_shipped_quantity' => $availableQuantity,
                        'wholesale_out_record_total_shipped' => $wholesaleOutRecord->shipped_quantity,
                    ]);
                } else {
                    // No stock available at all - backorder not fulfilled
                    $backorderRecord->shipped_quantity = 0;
                    $backorderRecord->save();
                }

                // Create new backorder for missing quantity
                $this->createNewBackorderForMissingStock(
                    $backorder->wholesaleOut,
                    $wholesaleOutRecord,
                    $areaId,
                    $missingQuantity,
                    $newBackordersCreated
                );

                $partiallyFulfilledRecords[] = [
                    'record_info' => $recordInfo,
                    'area_name' => $areaName,
                    'requested' => $areaQuantity,
                    'available' => $availableQuantity,
                    'missing' => $missingQuantity,
                    'message' => "Stock parziale per {$recordInfo} in {$areaName} (richiesto: {$areaQuantity}, disponibile: {$availableQuantity}) - Nuovo backorder creato per {$missingQuantity}",
                ];
            }
        }

        // Keep the original backorder record for historical purposes
        // The parent backorder status already indicates this backorder has been processed
    }

    /**
     * Create a new backorder for missing stock quantities
     */
    private function createNewBackorderForMissingStock(
        WholesaleOut $wholesaleOut,
        \App\Models\WholesaleOutRecord $wholesaleOutRecord,
        int $areaId,
        int $missingQuantity,
        array &$newBackordersCreated
    ): void {
        // Check if a pending backorder already exists for this wholesale out
        $newBackorder = $wholesaleOut->backorders()
            ->where('status', 0) // 0 = pending
            ->where('id', '!=', request()->route('backorder')->id) // Exclude current backorder being activated
            ->first();

        if (! $newBackorder) {
            // Create new backorder
            $newBackorder = $wholesaleOut->backorders()->create([
                'status' => 0, // 0 = pending
            ]);

            $newBackordersCreated[] = $newBackorder->id;
        }

        // Check if a backorder record already exists for this wholesale out record in the new backorder
        $newBackorderRecord = $newBackorder->backorderRecords()
            ->where('wholesale_out_record_id', $wholesaleOutRecord->id)
            ->first();

        if (! $newBackorderRecord) {
            // Create new backorder record
            $newBackorderRecord = $newBackorder->backorderRecords()->create([
                'wholesale_out_record_id' => $wholesaleOutRecord->id,
                'quantity' => $missingQuantity,
            ]);
        } else {
            // Update existing backorder record quantity
            $newBackorderRecord->increment('quantity', $missingQuantity);
        }

        // Check if area record already exists
        $newBackorderRecordsArea = $newBackorderRecord->backorderRecordsAreas()
            ->where('area_id', $areaId)
            ->first();

        if (! $newBackorderRecordsArea) {
            // Create new area record
            $newBackorderRecord->backorderRecordsAreas()->create([
                'area_id' => $areaId,
                'quantity' => $missingQuantity,
            ]);
        } else {
            // Update existing area record quantity
            $newBackorderRecordsArea->increment('quantity', $missingQuantity);
        }

        $this->logInfo('New backorder created for missing stock during activation', [
            'wholesale_out_id' => $wholesaleOut->id,
            'wholesale_out_record_id' => $wholesaleOutRecord->id,
            'area_id' => $areaId,
            'missing_quantity' => $missingQuantity,
            'new_backorder_id' => $newBackorder->id,
            'new_backorder_record_id' => $newBackorderRecord->id,
        ]);
    }

    /**
     * Deactivate a backorder and restore stock
     */
    public function deactivate(Backorder $backorder)
    {
        try {
            // Check if backorder is active
            if ($backorder->status !== 1) {
                return redirect()->back()->withErrors([
                    'backorder' => 'Il backorder non è attivo e non può essere disattivato.',
                ]);
            }

            DB::transaction(function () use ($backorder) {
                // STEP 1: Ripristina lo stock
                $changeDetector = new WholesaleOutChangeDetector;
                $reconciliationService = new ActiveWholesaleOutReconciliationService($changeDetector);
                $reconciliationService->restoreStockFromActivatedBackorder($backorder);

                // STEP 2: Cancella i backorder figli creati da questo backorder
                $this->cancelChildBackorders($backorder);

                // STEP 3: Disattiva il backorder
                $backorder->update(['status' => 0]);

                $this->logInfo('Backorder deactivated successfully', [
                    'backorder_id' => $backorder->id,
                    'wholesale_out_id' => $backorder->wholesale_out_id,
                ]);
            });

            return redirect()->route('backorder.index')
                ->with('success', 'Backorder disattivato con successo. Lo stock è stato ripristinato e i backorder figli sono stati cancellati.');

        } catch (\Exception $e) {
            $this->logError('Error deactivating backorder: '.$e->getMessage(), [
                'backorder_id' => $backorder->id,
            ]);

            return redirect()->back()->withErrors([
                'backorder' => 'Errore durante la disattivazione del backorder: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel child backorders created when this backorder was activated
     */
    private function cancelChildBackorders(Backorder $parentBackorder): void
    {
        // Trova il WholesaleOut associato a questo backorder
        $wholesaleOut = $parentBackorder->wholesaleOut;

        if (! $wholesaleOut) {
            $this->logWarning('WholesaleOut not found for backorder', [
                'backorder_id' => $parentBackorder->id,
            ]);

            return;
        }

        // Trova tutti i backorder PENDING (status = 0) creati DOPO l'attivazione di questo backorder
        // Questi sono i "figli" che sono stati creati perché non c'era abbastanza stock
        $childBackorders = $wholesaleOut->backorders()
            ->where('id', '!=', $parentBackorder->id)  // Non il backorder che stiamo disattivando
            ->where('status', 0)  // Solo quelli pending (non ancora attivati)
            ->where('created_at', '>=', $parentBackorder->updated_at)  // Creati dopo l'attivazione
            ->get();

        foreach ($childBackorders as $childBackorder) {
            $this->logInfo('Cancelling child backorder', [
                'parent_backorder_id' => $parentBackorder->id,
                'child_backorder_id' => $childBackorder->id,
                'wholesale_out_id' => $wholesaleOut->id,
            ]);

            // Cancella i record del backorder figlio
            foreach ($childBackorder->backorderRecords as $record) {
                $record->backorderRecordsAreas()->delete();
                $record->delete();
            }

            // Cancella il backorder figlio
            $childBackorder->delete();
        }

        if ($childBackorders->count() > 0) {
            $this->logInfo('Cancelled child backorders', [
                'parent_backorder_id' => $parentBackorder->id,
                'child_backorders_count' => $childBackorders->count(),
            ]);
        }
    }
}
