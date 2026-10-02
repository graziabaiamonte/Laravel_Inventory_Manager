<?php

namespace App\Http\Controllers;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Enums\RecordTypeEnum;
use App\Exports\WholesaleInExport;
use App\Exports\WholesaleInTemplateExport;
use App\Facades\Flash;
use App\Http\Requests\WholesaleInRequest;
use App\Http\Resources\ComboResource;
use App\Http\Resources\WholesaleInResource;
use App\Imports\WholesaleInImport;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\SaleRecord;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\WholesaleIn;
use App\Models\WholesaleInRecord;
use App\Models\WholesaleinRecordsArea;
use App\Services\BarcodeLabelService;
use App\Services\RecordImportService;
use App\Traits\LogsToChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class WholesaleInController extends Controller
{
    use \App\Traits\Helpers;
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'wholesale_in';
    }

    public function __construct(
        private RecordImportService $recordImportService
    ) {}

    /**
     * Handle file upload and import processing for both store and update operations
     */
    private function processFileImport(WholesaleInRequest $request): array
    {
        $importError = [];
        $importedRecords = [];

        try {
            $import = new WholesaleInImport;
            $import->import($request->file('file'));
            $importedRecords = $import->getProcessedData();
        } catch (\Exception $e) {
            $importError['file'] = 'Errore nel file xlsx! '.$e->getMessage();
        }

        return [
            'error' => $importError,
            'records' => $importedRecords,
        ];
    }

    /**
     * Create a new Record when record_id is 0
     */
    private function createNewRecord(array $recordInput): Record
    {
        // Map condition string values to enum values
        $diskStatus = DiskStatusEnum::Mint;
        if (! empty($recordInput['condition_disk'])) {
            $diskStatus = DiskStatusEnum::fromDescription($recordInput['condition_disk']);
        }

        $coverStatus = CoverStatusEnum::Mint;
        if (! empty($recordInput['condition_cover'])) {
            $coverStatus = CoverStatusEnum::fromDescription($recordInput['condition_cover']);
        }

        $newRecord = Record::create([
            'barcode' => self::cleanBarcode($recordInput['barcode'] ?? ''),
            'cat_number' => $recordInput['cat_number'] ?? '',
            // Release ID from the import; enables a new record to be published on Discogs straight
            // away, without editing it first. Stored as null (not 0) when absent: the column is
            // nullable and 0 is not a valid Discogs release ID, so null cleanly means "none".
            'release_id' => ! empty($recordInput['release_id']) ? (int) $recordInput['release_id'] : null,
            'type' => 'new',
            'title' => $recordInput['title'] ?? '',
            'retail_price' => (float) ($recordInput['retail_price'] ?? 0),
            'wholesale_price' => (float) ($recordInput['wholesale_price'] ?? 0),
            'purchase_price' => (float) ($recordInput['purchase_price'] ?? 0),
            'disk_status' => $diskStatus,
            'cover_status' => $coverStatus,
            'description' => $recordInput['description'] ?? '',
            'comments' => $recordInput['comments'] ?? '',
            'for_sale_on_discogs' => $recordInput['for_sale_on_discogs'] ?? 0,
            // discogs_id (the Discogs listing ID) is never taken from the import. It is written
            // only from Discogs' confirmation response when a listing is actually created, so the
            // "listed on Discogs" state can never be a false positive.
            'discogs_id' => null,
            'format_id' => ! empty($recordInput['format']) ? $this->recordImportService->findOrCreateModel($recordInput['format'], Format::class) : null,
            'label_id' => ! empty($recordInput['label']) ? $this->recordImportService->findOrCreateModel($recordInput['label'], Label::class) : null,
            'artist_id' => ! empty($recordInput['artist']) ? $this->recordImportService->findOrCreateModel($recordInput['artist'], Artist::class) : null,
        ]);

        // Refresh to get auto-filled values from model's created event
        $newRecord->refresh();

        return $newRecord;
    }

    /**
     * Create a WholesaleInRecord with proper price casting
     */
    private function createWholesaleInRecord(WholesaleIn $wholesaleIn, array $recordInput, int $position = 0): WholesaleInRecord
    {
        return $wholesaleIn->records()->create([
            'position' => $position,
            'record_id' => $recordInput['record_id'],
            'quantity' => $recordInput['quantity'],
            'unit_price' => (float) $recordInput['unit_price'],
            'discount' => $recordInput['discount'],
            'total_price' => (float) $recordInput['total_price'],
            'vat' => $recordInput['vat'],
        ]);
    }

    /**
     * Update or create stock entries for multiple areas
     */
    private function updateStockForAreas(int $recordId, array $areaQuantities, int $fallbackQuantity, WholesaleIn $wholesaleIn, bool $isUpdate = false): void
    {
        // Only update stocks if WholesaleIn is active (status = 1)
        if ($wholesaleIn->status !== 1) {
            $this->logInfo('Skipping stock update for areas - WholesaleIn is not active', [
                'wholesale_in_id' => $wholesaleIn->id,
                'status' => $wholesaleIn->status,
            ]);

            return;
        }

        // Debug logging to see what we receive
        $this->logDetail('updateStockForAreas called', [
            'record_id' => $recordId,
            'area_quantities' => $areaQuantities,
            'fallback_quantity' => $fallbackQuantity,
            'wholesale_in_area_id' => $wholesaleIn->area_id,
            'is_update' => $isUpdate,
            'wholesale_in_status' => $wholesaleIn->status,
        ]);

        $totalAreaQuantity = 0;

        if (! empty($areaQuantities)) {
            // First, update stock for each area specified in the spreadsheet
            foreach ($areaQuantities as $areaQuantity) {
                $this->logInfo('Processing area quantity', [
                    'area_id' => $areaQuantity['area_id'],
                    'quantity' => $areaQuantity['quantity'],
                ]);
                $this->updateStock(
                    $recordId,
                    $areaQuantity['area_id'],
                    $areaQuantity['quantity'],
                    $wholesaleIn,
                    $isUpdate
                );
                $totalAreaQuantity += $areaQuantity['quantity'];
            }

            // Calculate remaining quantity that needs to be assigned to the fallback area
            $remainingQuantity = $fallbackQuantity - $totalAreaQuantity;

            // Log for debugging
            $this->logInfo('Stock distribution calculation', [
                'record_id' => $recordId,
                'total_quantity_from_q_column' => $fallbackQuantity,
                'area_quantities' => $areaQuantities,
                'total_area_quantity' => $totalAreaQuantity,
                'remaining_quantity' => $remainingQuantity,
                'wholesale_in_area_id' => $wholesaleIn->area_id,
            ]);

            // If there's remaining quantity, add it to the wholesaleIn area_id
            if ($remainingQuantity > 0) {
                if ($wholesaleIn->area_id) {
                    $this->logInfo('Adding remaining quantity to wholesale area', [
                        'area_id' => $wholesaleIn->area_id,
                        'remaining_quantity' => $remainingQuantity,
                    ]);
                    $this->updateStock(
                        $recordId,
                        $wholesaleIn->area_id,
                        $remainingQuantity,
                        $wholesaleIn,
                        $isUpdate
                    );
                } else {
                    // Ultimate fallback: use first active area for remaining quantity
                    $firstActiveArea = \App\Models\Area::filterByAdminRoles()->where('status', 1)->first();
                    if ($firstActiveArea) {
                        $this->logInfo('Adding remaining quantity to first active area (no wholesale area set)', [
                            'area_id' => $firstActiveArea->id,
                            'remaining_quantity' => $remainingQuantity,
                        ]);
                        $this->updateStock(
                            $recordId,
                            $firstActiveArea->id,
                            $remainingQuantity,
                            $wholesaleIn,
                            $isUpdate
                        );
                    }
                }
            } elseif ($remainingQuantity < 0) {
                // Log warning if area quantities exceed main quantity
                $this->logWarning('Area quantities exceed main quantity', [
                    'record_id' => $recordId,
                    'total_quantity' => $fallbackQuantity,
                    'total_area_quantity' => $totalAreaQuantity,
                    'excess' => abs($remainingQuantity),
                ]);
            } else {
                $this->logInfo('No remaining quantity - area quantities exactly match total quantity');
            }
        } else {
            // No area-specific quantities provided, use wholesale_in area_id as fallback for full quantity
            if ($wholesaleIn->area_id) {
                $this->logInfo('No area quantities, adding full quantity to wholesale area', [
                    'area_id' => $wholesaleIn->area_id,
                    'full_quantity' => $fallbackQuantity,
                ]);
                $this->updateStock(
                    $recordId,
                    $wholesaleIn->area_id,
                    $fallbackQuantity,
                    $wholesaleIn,
                    $isUpdate
                );
            } else {
                // Ultimate fallback: use first active area if wholesale_in area_id is not set
                $firstActiveArea = \App\Models\Area::filterByAdminRoles()->where('status', 1)->first();
                if ($firstActiveArea) {
                    $this->logInfo('No area quantities and no wholesale area, adding full quantity to first active area', [
                        'area_id' => $firstActiveArea->id,
                        'full_quantity' => $fallbackQuantity,
                    ]);
                    $this->updateStock(
                        $recordId,
                        $firstActiveArea->id,
                        $fallbackQuantity,
                        $wholesaleIn,
                        $isUpdate
                    );
                }
            }
        }
    }

    /**
     * Update or create stock entries
     */
    private function updateStock(int $recordId, int $areaId, int $quantity, WholesaleIn $wholesaleIn, bool $isUpdate = false): void
    {
        // Only update stocks if WholesaleIn is active (status = 1)
        if ($wholesaleIn->status !== 1) {
            $this->logInfo('Skipping stock update - WholesaleIn is not active', [
                'wholesale_in_id' => $wholesaleIn->id,
                'status' => $wholesaleIn->status,
            ]);

            return;
        }

        $this->logDetail('updateStock called', [
            'record_id' => $recordId,
            'area_id' => $areaId,
            'quantity' => $quantity,
            'wholesale_in_id' => $wholesaleIn->id,
            'is_update' => $isUpdate,
        ]);

        $existingStock = Stock::where('record_id', $recordId)
            ->where('area_id', $areaId)
            ->first();

        if ($existingStock) {
            $this->logInfo('Found existing stock', [
                'record_id' => $recordId,
                'area_id' => $areaId,
                'existing_quantity' => $existingStock->quantity,
                'quantity_to_add' => $quantity,
                'is_update' => $isUpdate,
            ]);

            if ($isUpdate) {
                // For updates, set the stock to the new quantity from spreadsheet
                $this->logInfo('Update mode: replacing stock quantity', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                    'old_quantity' => $existingStock->quantity,
                    'new_quantity' => $quantity,
                ]);
                $existingStock->update(['quantity' => $quantity]);
            } else {
                // For creates, add to existing stock
                $newQuantity = $existingStock->quantity + $quantity;
                $this->logInfo('Create mode: adding to existing stock', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                    'existing_quantity' => $existingStock->quantity,
                    'quantity_to_add' => $quantity,
                    'new_total_quantity' => $newQuantity,
                ]);
                $existingStock->update(['quantity' => $newQuantity]);
            }
        } else {
            $this->logInfo('No existing stock found - creating new stock entry', [
                'record_id' => $recordId,
                'area_id' => $areaId,
                'quantity' => $quantity,
            ]);
            // Create new stock entry
            Stock::create([
                'record_id' => $recordId,
                'area_id' => $areaId,
                'quantity' => $quantity,
            ]);
        }
    }

    /**
     * Increase quantities in Stocks or delete them
     */
    private function addStocksForWholesaleIn(WholesaleIn $wholesaleIn): void
    {
        $this->logDetail('addStocksForWholesaleIn called', [
            'wholesale_in_id' => $wholesaleIn->id,
            'records_count' => $wholesaleIn->records->count(),
        ]);

        foreach ($wholesaleIn->records as $record) {
            $this->logInfo('Processing record for stock addition', [
                'wholesale_in_record_id' => $record->id,
                'record_id' => $record->record_id,
                'quantity' => $record->quantity,
                'area_records_count' => $record->wholesaleInRecordsArea->count(),
            ]);

            $areaQuantities = $record->wholesaleInRecordsArea->map(function ($recordArea) {
                return [
                    'area_id' => $recordArea->area_id,
                    'quantity' => $recordArea->quantity,
                ];
            })->toArray();

            $this->logInfo('Area quantities for record', [
                'record_id' => $record->record_id,
                'area_quantities' => $areaQuantities,
            ]);

            $this->logInfo('Calling updateStockForAreas from addStocksForWholesaleIn', [
                'record_id' => $record->record_id,
                'area_quantities' => $areaQuantities,
                'fallback_quantity' => $record->quantity,
                'is_update' => false, // Always false for adding stocks
            ]);

            $this->updateStockForAreas(
                $record->record_id,
                $areaQuantities,
                $record->quantity,
                $wholesaleIn,
                false // Use false to ADD to existing stock, not replace it
            );
        }
    }

    /**
     * Decrease quantities in Stocks or delete them
     */
    private function removeStocksForWholesaleIn(WholesaleIn $wholesaleIn): void
    {
        foreach ($wholesaleIn->records as $record) {
            // Get area-specific quantities for this wholesale record
            $areaQuantities = $record->wholesaleInRecordsArea->map(function ($recordArea) {
                return [
                    'area_id' => $recordArea->area_id,
                    'quantity' => $recordArea->quantity,
                ];
            })->toArray();

            if (! empty($areaQuantities)) {
                // Remove stock based on area-specific quantities
                foreach ($areaQuantities as $areaQuantity) {
                    $this->removeStockFromArea(
                        $record->record_id,
                        $areaQuantity['area_id'],
                        $areaQuantity['quantity']
                    );
                }
            } else {
                // Fallback: remove from the wholesale's main area if no area-specific quantities
                if ($wholesaleIn->area_id) {
                    $this->removeStockFromArea(
                        $record->record_id,
                        $wholesaleIn->area_id,
                        $record->quantity
                    );
                } else {
                    // Last resort: subtract from all stocks proportionally (old behavior)
                    $this->logWarning('No area information available for stock removal', [
                        'wholesale_in_id' => $wholesaleIn->id,
                        'record_id' => $record->record_id,
                        'quantity' => $record->quantity,
                    ]);
                    $this->removeStockLegacyMethod($record->record_id, $record->quantity);
                }
            }
        }
    }

    /**
     * Remove stock from a specific area
     */
    private function removeStockFromArea(int $recordId, int $areaId, int $quantity): void
    {
        $stock = Stock::where('record_id', $recordId)
            ->where('area_id', $areaId)
            ->first();

        if ($stock) {
            $newQuantity = $stock->quantity - $quantity;

            // Check for negative stock condition (data integrity issue)
            if ($newQuantity < 0) {
                $record = \App\Models\Record::find($recordId);
                $area = \App\Models\Area::find($areaId);
                $recordIdentifier = $record->barcode ?: $record->rr_uid;
                $recordTitle = $record->title ?? 'N/A';

                $this->logError('CRITICAL: Attempted to remove more stock than available', [
                    'record_id' => $recordId,
                    'record_identifier' => $recordIdentifier,
                    'area_id' => $areaId,
                    'area_name' => $area->name ?? 'N/A',
                    'current_quantity' => $stock->quantity,
                    'attempted_removal' => $quantity,
                    'would_result_in' => $newQuantity,
                ]);

                throw new \RuntimeException(
                    'Stock insufficiente. '.
                    "Disco: {$recordIdentifier} - {$recordTitle}. ".
                    "Area: {$area->name}. ".
                    "Richieste {$quantity} unità ma solo {$stock->quantity} disponibili."
                );
            }

            // CRITICAL: Never delete stock records, even when quantity reaches 0
            // Stock records may be referenced by WholesaleOut records via foreign keys
            if ($newQuantity === 0) {
                $stock->update(['quantity' => 0]);
                $this->logInfo('Set stock quantity to zero (kept record to avoid FK violations)', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                    'reduced_by' => $quantity,
                    'note' => 'Stock record preserved due to potential WholesaleOut references',
                ]);
            } else {
                $stock->update(['quantity' => $newQuantity]);
                $this->logInfo('Reduced stock quantity', [
                    'record_id' => $recordId,
                    'area_id' => $areaId,
                    'reduced_by' => $quantity,
                    'new_quantity' => $newQuantity,
                ]);
            }
        } else {
            $this->logError('Stock not found for removal - this may indicate data inconsistency', [
                'record_id' => $recordId,
                'area_id' => $areaId,
                'quantity_to_remove' => $quantity,
                'suggestion' => 'The stock may have been manually adjusted or this area never had stock for this record',
            ]);
        }
    }

    /**
     * Legacy method: remove stock from all areas proportionally (fallback)
     */
    private function removeStockLegacyMethod(int $recordId, int $quantity): void
    {
        $stocks = Stock::where('record_id', $recordId)->get();

        foreach ($stocks as $stock) {
            // Same rule as removeStockFromArea(): never delete the row when the
            // quantity reaches zero, it may be referenced by a WholesaleOut or
            // Sale line via stock_id.
            $stock->update(['quantity' => $stock->quantity - $quantity]);
        }
    }

    /**
     * Update or create whole sale record area entries
     */
    private function updateWholeSaleRecordArea(int $WholesaleinRecordId, int $areaId, int $quantity, bool $isUpdate = false): void
    {
        $existingWholeSaleRecordArea = WholesaleinRecordsArea::where('wholesale_in_records_id', $WholesaleinRecordId)
            ->where('area_id', $areaId)
            ->first();

        if ($existingWholeSaleRecordArea) {

            $existingWholeSaleRecordArea->update(['quantity' => $quantity]);

        } else {
            // Create new wholesale in record area entry
            WholesaleinRecordsArea::create([
                'wholesale_in_records_id' => $WholesaleinRecordId,
                'area_id' => $areaId,
                'quantity' => $quantity,
            ]);
        }
    }

    /**
     * Process records array for both store and update operations
     */
    private function processRecords(WholesaleIn $wholesaleIn, array $records, bool $isUpdate = false, ?int $previousStatus = null): array
    {
        $totalPrice = 0;
        $autoBarcodeRecords = [];
        $duplicateWarnings = [];

        $this->logDetail('processRecords called', [
            'wholesale_in_id' => $wholesaleIn->id,
            'records_count' => count($records),
            'is_update' => $isUpdate,
            'wholesale_in_status' => $wholesaleIn->status,
            'previous_status' => $previousStatus,
        ]);

        // Determine if we should update stocks during record processing
        // Don't update stocks if:
        // 1. Status is changing from inactive (0) to active (1) - stocks will be handled by addStocksForWholesaleIn
        // 2. Status is changing from active (1) to inactive (0) - stocks will be handled by removeStocksForWholesaleIn
        // 3. Current status is inactive (0) - no stock updates needed
        $shouldUpdateStocks = true;

        if ($isUpdate && $previousStatus !== null && $previousStatus !== $wholesaleIn->status) {
            // Status is changing - let the dedicated status change handlers deal with stocks
            $shouldUpdateStocks = false;
            $this->logInfo('Status is changing - skipping stock updates in processRecords', [
                'previous_status' => $previousStatus,
                'new_status' => $wholesaleIn->status,
            ]);
        } elseif ($wholesaleIn->status === 0) {
            // WholesaleIn is inactive - no stock updates needed
            $shouldUpdateStocks = false;
            $this->logInfo('WholesaleIn is inactive - skipping stock updates in processRecords');
        }

        foreach ($records as $position => $recordInput) {
            // When area-specific quantities are provided, they are the source of truth for the total
            // quantity. The frontend's main "quantity" field can go stale once areas are added via the
            // "+" button (it keeps whatever was typed before), so recompute it here to keep the stored
            // quantity, pricing and stock distribution consistent with what was actually allocated per area.
            if (! empty($recordInput['area_quantities'])) {
                $recordInput['quantity'] = array_sum(array_column($recordInput['area_quantities'], 'quantity'));
            }

            // Debug logging to see what record data we receive
            $this->logInfo('Processing record', [
                'cat_number' => $recordInput['cat_number'] ?? 'N/A',
                'barcode' => $recordInput['barcode'] ?? 'N/A',
                'quantity' => $recordInput['quantity'] ?? 'N/A',
                'area_quantities' => $recordInput['area_quantities'] ?? [],
                'has_area_quantities' => isset($recordInput['area_quantities']) && ! empty($recordInput['area_quantities']),
                'for_sale_on_discogs' => $recordInput['for_sale_on_discogs'] ?? 0,
            ]);

            // NOTE: kept from the old application (whole_in.php): a line without a record_id always
            // creates a new record, without looking for an existing one by barcode or cat_number,
            // so a line added by hand can duplicate an existing record. Only a duplicate warning is
            // shown afterwards.
            if ((int) $recordInput['record_id'] === 0) {
                $newRecord = $this->createNewRecord($recordInput);
                $recordInput['record_id'] = $newRecord->id;

                // Track records that got auto-generated barcodes (both barcode and cat_number were empty)
                if ($newRecord->barcode === $newRecord->rr_uid) {
                    $autoBarcodeRecords[] = $newRecord;
                }

                // Check for duplicate barcode/cat_number on newly created records
                if (! empty($newRecord->barcode) && $newRecord->barcode !== $newRecord->rr_uid) {
                    $dupeCount = Record::where('barcode', $newRecord->barcode)
                        ->where('id', '!=', $newRecord->id)->count();
                    if ($dupeCount > 0) {
                        $url = route('record.index', ['filter' => ['search' => $newRecord->barcode]]);
                        $duplicateWarnings[] = "Barcode <strong>{$newRecord->barcode}</strong> già presente su {$dupeCount} record. <a href=\"{$url}\" target=\"_blank\">Vedi duplicati</a>";
                    }
                }
                if (! empty($newRecord->cat_number)) {
                    $dupeCount = Record::where('cat_number', $newRecord->cat_number)
                        ->where('id', '!=', $newRecord->id)->count();
                    if ($dupeCount > 0) {
                        $url = route('record.index', ['filter' => ['search' => $newRecord->cat_number]]);
                        $duplicateWarnings[] = "Cat# <strong>{$newRecord->cat_number}</strong> già presente su {$dupeCount} record. <a href=\"{$url}\" target=\"_blank\">Vedi duplicati</a>";
                    }
                }
            } else {
                // For existing records, update fields and check for duplicates
                $existingRecord = Record::withoutGlobalScopes()->find($recordInput['record_id']);
                if ($existingRecord) {
                    // Check for duplicate barcode/cat_number on existing records (only on initial save, not re-activation)
                    if (! $isUpdate) {
                        if (! empty($existingRecord->barcode)) {
                            $dupeCount = Record::where('barcode', $existingRecord->barcode)
                                ->where('id', '!=', $existingRecord->id)->count();
                            if ($dupeCount > 0) {
                                $url = route('record.index', ['filter' => ['search' => $existingRecord->barcode]]);
                                $duplicateWarnings[] = "Barcode <strong>{$existingRecord->barcode}</strong> già presente su {$dupeCount} record. <a href=\"{$url}\" target=\"_blank\">Vedi duplicati</a>";
                            }
                        }
                        if (! empty($existingRecord->cat_number)) {
                            $dupeCount = Record::where('cat_number', $existingRecord->cat_number)
                                ->where('id', '!=', $existingRecord->id)->count();
                            if ($dupeCount > 0) {
                                $url = route('record.index', ['filter' => ['search' => $existingRecord->cat_number]]);
                                $duplicateWarnings[] = "Cat# <strong>{$existingRecord->cat_number}</strong> già presente su {$dupeCount} record. <a href=\"{$url}\" target=\"_blank\">Vedi duplicati</a>";
                            }
                        }
                    }

                    // Update the for_sale_on_discogs field if provided in the import
                    if (isset($recordInput['for_sale_on_discogs'])) {
                        $this->logInfo('Checking existing record for Discogs update', [
                            'record_id' => $existingRecord->id,
                            'old_for_sale_on_discogs' => $existingRecord->for_sale_on_discogs,
                            'new_for_sale_on_discogs' => $recordInput['for_sale_on_discogs'],
                            'old_discogs_id' => $existingRecord->discogs_id,
                            'new_discogs_id' => $recordInput['discogs_id'] ?? null,
                        ]);

                        $updateData = [];

                        // Update condition fields if provided
                        if (! empty($recordInput['condition_disk'])) {
                            $updateData['disk_status'] = DiskStatusEnum::fromDescription($recordInput['condition_disk']);
                        }
                        if (! empty($recordInput['condition_cover'])) {
                            $updateData['cover_status'] = CoverStatusEnum::fromDescription($recordInput['condition_cover']);
                        }

                        // Backfill the Discogs Release ID from the import when the record does not have
                        // one yet. release_id identifies the disc and is REQUIRED to create a Discogs
                        // listing: without it, flagging the record for sale below only makes the queued
                        // listing job fail ("no release_id") and clear the flag again - so the record can
                        // never be published. Unlike discogs_id (the listing ID, never taken from import),
                        // release_id is a stable property of the disc and is safe to import. We only set it
                        // when missing, to avoid clobbering an existing, correct value.
                        if (empty($existingRecord->release_id) && ! empty($recordInput['release_id'])) {
                            $updateData['release_id'] = (int) $recordInput['release_id'];
                            $this->logInfo('Backfilling release_id from import for existing record', [
                                'record_id' => $existingRecord->id,
                                'release_id' => (int) $recordInput['release_id'],
                            ]);
                        }

                        // Only update for_sale_on_discogs if the record is NOT already listed on Discogs
                        // AND the import explicitly requests it to be listed
                        if (! $existingRecord->for_sale_on_discogs && ! $existingRecord->discogs_id && (int) $recordInput['for_sale_on_discogs'] === 1) {
                            $updateData['for_sale_on_discogs'] = 1;
                            $this->logInfo('Record not on Discogs and import requests listing - updating for_sale_on_discogs', [
                                'record_id' => $existingRecord->id,
                                'new_for_sale_on_discogs' => 1,
                            ]);
                        } else {
                            $this->logInfo('Preserving existing Discogs status', [
                                'record_id' => $existingRecord->id,
                                'existing_for_sale_on_discogs' => $existingRecord->for_sale_on_discogs,
                                'existing_discogs_id' => $existingRecord->discogs_id,
                                'import_for_sale_on_discogs' => $recordInput['for_sale_on_discogs'],
                                'reason' => $existingRecord->for_sale_on_discogs || $existingRecord->discogs_id ? 'already_on_discogs' : 'import_not_requesting_listing',
                            ]);
                        }

                        // discogs_id (the Discogs listing ID) is intentionally NOT taken from the
                        // import. A value supplied in the Excel `discogs_id` column is ignored here:
                        // the field is written only from Discogs' confirmation response when a listing
                        // is created, so a stale or wrong value can never mark a record as "listed".
                        if (isset($recordInput['discogs_id']) && $recordInput['discogs_id'] !== null && $recordInput['discogs_id'] !== '') {
                            $this->logInfo('Ignoring discogs_id from import - only set from Discogs confirmation', [
                                'record_id' => $existingRecord->id,
                                'ignored_discogs_id' => $recordInput['discogs_id'],
                                'existing_discogs_id' => $existingRecord->discogs_id,
                            ]);
                        }

                        // Only update if there's data to update
                        if (! empty($updateData)) {
                            $this->logInfo('About to update record with data', [
                                'record_id' => $existingRecord->id,
                                'update_data' => $updateData,
                            ]);

                            // Use updateQuietly to bypass model events (Discogs integration is handled separately in WholesaleIn)
                            $updateResult = $existingRecord->updateQuietly($updateData);

                            $this->logInfo('Update result', [
                                'record_id' => $existingRecord->id,
                                'update_result' => $updateResult,
                                'updated_for_sale_on_discogs' => $existingRecord->fresh()->for_sale_on_discogs,
                                'updated_discogs_id' => $existingRecord->fresh()->discogs_id,
                            ]);
                        } else {
                            $this->logInfo('No updates needed for record', [
                                'record_id' => $existingRecord->id,
                            ]);
                        }
                    }
                } else {
                    $this->logWarning('Record not found for update', [
                        'record_id' => $recordInput['record_id'],
                    ]);
                }
            }

            // Create the WholesaleInRecord
            $wholesaleInRecord = $this->createWholesaleInRecord($wholesaleIn, $recordInput, $position);

            if (! empty($recordInput['area_quantities'])) {
                // Handle area quantities if provided
                foreach ($recordInput['area_quantities'] as $areaQuantity) {
                    $this->updateWholeSaleRecordArea(
                        $wholesaleInRecord->id,
                        $areaQuantity['area_id'],
                        $areaQuantity['quantity'],
                        $isUpdate
                    );
                }

                // Update stock for areas (use area-specific quantities if available)
                if ($shouldUpdateStocks) {
                    $areaQuantities = $recordInput['area_quantities'] ?? [];
                    $this->logInfo('Calling updateStockForAreas from processRecords', [
                        'record_id' => $recordInput['record_id'],
                        'area_quantities' => $areaQuantities,
                        'fallback_quantity' => $recordInput['quantity'],
                        'is_update' => $isUpdate,
                    ]);
                    $this->updateStockForAreas(
                        $recordInput['record_id'],
                        $areaQuantities,
                        $recordInput['quantity'],
                        $wholesaleIn,
                        $isUpdate
                    );
                } else {
                    $this->logInfo('Skipping stock update for areas - will be handled by status change handlers');
                }
            } else {
                // No area_quantities provided, treat the main quantity as belonging to the wholesale_in area
                if ($wholesaleIn->area_id) {
                    // Create wholesale_in_records_areas entry for the main quantity
                    $this->updateWholeSaleRecordArea(
                        $wholesaleInRecord->id,
                        $wholesaleIn->area_id,
                        $recordInput['quantity'],
                        $isUpdate
                    );
                }

                // Update stock
                if ($shouldUpdateStocks) {
                    $this->updateStock(
                        $recordInput['record_id'],
                        $wholesaleIn->area_id,
                        $recordInput['quantity'],
                        $wholesaleIn,
                        $isUpdate
                    );
                } else {
                    $this->logInfo('Skipping stock update - will be handled by status change handlers');
                }
            }

            $totalPrice += (float) $recordInput['total_price'];
        }

        return [
            'total_price' => $totalPrice,
            'auto_barcode_records' => $autoBarcodeRecords,
            'duplicate_warnings' => $duplicateWarnings,
        ];
    }

    /**
     * Handle file upload processing for both store and update operations
     */
    private function handleFileUpload(WholesaleInRequest $request, array $validated, ?WholesaleIn $wholesaleIn = null): ?RedirectResponse
    {
        if (! $request->hasFile('file')) {
            return null;
        }

        // For store operation, check if WholesaleIn already exists
        if (! $wholesaleIn && empty($validated['records'])) {
            if (WholesaleIn::where('supplier_id', $validated['supplier_id'])
                ->where('doc_num', $validated['doc_num'])
                ->exists()
            ) {
                return redirect()
                    ->back()
                    ->withInput()
                    ->withErrors(['file' => 'Carico già esistente per questo fornitore e numero documento.']);
            }
        }

        $importResult = $this->processFileImport($request);

        if (! empty($importResult['error'])) {
            return redirect()
                ->back()
                ->withInput()
                ->withErrors($importResult['error']);
        }

        // Debug: Log what we're about to store in session
        $this->logInfo('Storing in session - importResult records', [
            'records_count' => count($importResult['records']),
            'first_record_area_quantities' => isset($importResult['records'][0]['area_quantities']) ? $importResult['records'][0]['area_quantities'] : 'NOT_SET',
            'first_record_full' => $importResult['records'][0] ?? 'NO_RECORDS',
        ]);

        // Prepare preserved data for redirect
        $preservedData = $wholesaleIn ? [
            'supplier_id' => $wholesaleIn->supplier_id,
            'area_id' => $wholesaleIn->area_id,
            'doc_num' => $wholesaleIn->doc_num,
            'description' => $wholesaleIn->description,
            'status' => $wholesaleIn->status,
        ] : $validated;

        return redirect()
            ->back()
            ->withInput($preservedData)
            ->with('importedRecords', $importResult['records']);
    }

    /**
     * Create or update WholesaleIn with records
     */
    private function saveWholesaleInWithRecords(array $validated, ?WholesaleIn $wholesaleIn = null): WholesaleIn
    {
        $previousStatus = null;
        $isUpdate = false;

        // Debug log the incoming validated records
        $this->logInfo('saveWholesaleInWithRecords - received records', [
            'records_count' => count($validated['records'] ?? []),
            'first_record' => isset($validated['records'][0]) ? $validated['records'][0] : null,
        ]);

        if ($wholesaleIn) {
            $previousStatus = $wholesaleIn->status;
            $isUpdate = true;

            // Update existing WholesaleIn
            $wholesaleIn->fill(collect($validated)->except(['records', 'file'])->toArray());

            // Delete existing records to replace with new ones
            if (isset($validated['records']) && ! empty($validated['records'])) {
                $wholesaleIn->records()->delete();
            }
        } else {
            // Create new WholesaleIn
            $wholesaleIn = WholesaleIn::create([
                'supplier_id' => $validated['supplier_id'],
                'area_id' => $validated['area_id'] ?? null,
                'total_price' => 0, // Will be calculated by processRecords
                'doc_num' => $validated['doc_num'],
                'description' => $validated['description'],
                'status' => $validated['status'],
                'file' => null, // Store file if needed
            ]);
        }

        // Process records and calculate total price
        $result = $this->processRecords(
            $wholesaleIn,
            $validated['records'] ?? [],
            $wholesaleIn->wasRecentlyCreated === false, // true for updates, false for creates
            $previousStatus // Pass the previous status to determine if we should update stocks
        );

        $totalPrice = $result['total_price'];

        // Flash warning for records with auto-generated barcodes
        if (! empty($result['auto_barcode_records'])) {
            $lines = array_map(function ($record) {
                $editUrl = route('record.edit', $record->id);

                return "<a href=\"{$editUrl}\" target=\"_blank\">{$record->rr_uid}</a> - {$record->title}";
            }, $result['auto_barcode_records']);

            Flash::warning(
                'I seguenti record hanno ricevuto un barcode generato automaticamente (uguale a RRID) perché privi di barcode e cat#. '
                .'Puoi modificarli per inserire un cat# e rimuovere il barcode automatico:<br>'
                .implode('<br>', $lines)
            );
        }

        // Flash warnings for duplicate barcode/cat_number
        if (! empty($result['duplicate_warnings'])) {
            Flash::warning(implode('<br>', $result['duplicate_warnings']));
        }

        $this->logInfo('processRecords completed', [
            'wholesale_in_id' => $wholesaleIn->id,
            'is_update_flag' => $wholesaleIn->wasRecentlyCreated === false,
            'total_price' => $totalPrice,
        ]);

        if ($isUpdate && $previousStatus !== $wholesaleIn->status) {
            $this->logInfo('WholesaleIn status changed', [
                'wholesale_in_id' => $wholesaleIn->id,
                'previous_status' => $previousStatus,
                'new_status' => $wholesaleIn->status,
            ]);

            if ($previousStatus === 0 && $wholesaleIn->status === 1) {
                // Status changed from inactive to active - add stocks
                $this->logInfo('Status changed from inactive to active - adding stocks', [
                    'wholesale_in_id' => $wholesaleIn->id,
                ]);
                // Reload the wholesale in with necessary relationships
                $wholesaleIn->load(['records.wholesaleInRecordsArea']);
                $this->addStocksForWholesaleIn($wholesaleIn);
            } elseif ($previousStatus === 1 && $wholesaleIn->status === 0) {
                // Status changed from active to inactive - remove stocks
                $this->logInfo('Status changed from active to inactive - removing stocks', [
                    'wholesale_in_id' => $wholesaleIn->id,
                ]);
                // Reload the wholesale in with necessary relationships
                $wholesaleIn->load(['records.wholesaleInRecordsArea']);
                // Let the exception propagate to the outer catch in update() method
                $this->removeStocksForWholesaleIn($wholesaleIn);
            }
        } else {
            $this->logInfo('WholesaleIn status update check', [
                'wholesale_in_id' => $wholesaleIn->id,
                'is_update' => $isUpdate,
                'previous_status' => $previousStatus,
                'new_status' => $wholesaleIn->status,
                'status_changed' => $previousStatus !== $wholesaleIn->status,
            ]);
        }

        // Update total price
        $wholesaleIn->total_price = $totalPrice;
        $wholesaleIn->save();

        return $wholesaleIn;
    }

    /**
     * Get shared data for create and edit views
     */
    private function getSharedViewData(Request $request): array
    {
        $importedRecords = $request->session()->get('importedRecords', []);

        $warehouseAreas = Area::filterByAdminRoles()->defaulLocationsAreas()->get();

        // Debug: Log what we retrieve from session
        $this->logInfo('Retrieved from session - importedRecords', [
            'records_count' => count($importedRecords),
            'first_record_area_quantities' => isset($importedRecords[0]['area_quantities']) ? $importedRecords[0]['area_quantities'] : 'NOT_SET',
            'first_record_full' => $importedRecords[0] ?? 'NO_RECORDS',
        ]);

        // Get user's default area from default warehouse location
        /** @var \App\Models\User $user */
        $user = $request->user();
        $defaultArea = null;
        if ($user->defaultLocation) {
            $defaultAreaModel = $user->defaultLocation->defaultArea ?? $user->defaultLocation->areas->first();
            if ($defaultAreaModel) {
                $defaultArea = new ComboResource($defaultAreaModel);
            }
        }

        return [
            'suppliers' => ComboResource::collection(Supplier::where('status', 1)->get()),
            'areas' => ComboResource::collection(Area::filterByAdminRoles()->where('status', 1)->get()),
            'areas_warehouses' => ComboResource::collection($warehouseAreas),
            'defaultArea' => $defaultArea,
            'importedRecords' => $importedRecords,
            'types_status' => RecordTypeEnum::getJsonValues(),
        ];
    }

    public function index(Request $request)
    {
        $baseQuery = QueryBuilder::for(WholesaleIn::class)
            ->filterByAdminRoles()
            ->join('suppliers', 'wholesale_ins.supplier_id', '=', 'suppliers.id')
            ->join('areas', 'wholesale_ins.area_id', '=', 'areas.id')
            ->select('wholesale_ins.*')
            ->with(['supplier', 'area'])
            ->allowedSorts([
                'doc_num',
                'total_price',
                'status',
                'created_at',
                AllowedSort::field('suppliers.name'),
            ])
            ->allowedFilters([
                AllowedFilter::exact('suppliers.id'),
                AllowedFilter::exact('areas.id'),
                AllowedFilter::exact('status'),
                AllowedFilter::callback('search', function ($query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['doc_num', 'description']);
                }),
                AllowedFilter::callback('date', function (Builder $query, $value) {
                    // Add time to make the date range inclusive of the entire day
                    $startDate = $value['startDate'].' 00:00:00';
                    $endDate = $value['endDate'].' 23:59:59';
                    $query->whereBetween('wholesale_ins.created_at', [$startDate, $endDate]);
                }),
            ])
            ->defaultSort('-status', '-created_at');

        return Inertia::render('WholesaleIn/Index', [
            'wholesaleIns' => WholesaleInResource::collection($baseQuery->paginate($this->perPage($request))),
            'suppliers' => ComboResource::collection(Supplier::where('status', 1)->get()),
            'areas' => ComboResource::collection(Area::filterByAdminRoles()->where('status', 1)->get()),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create(Request $request)
    {
        $sharedData = $this->getSharedViewData($request);

        return Inertia::render('WholesaleIn/Create', $sharedData);
    }

    public function downloadTemplate()
    {
        return Excel::download(new WholesaleInTemplateExport, 'carico.xlsx');
    }

    public function export(WholesaleIn $wholesaleIn)
    {
        $this->authorize('view', $wholesaleIn);

        $docNum = $wholesaleIn->doc_num ?? '';
        $cleanDocNum = preg_replace('/[^a-zA-Z0-9\s]/', '', $docNum); // Remove special characters
        $cleanDocNum = preg_replace('/\s+/', '-', trim($cleanDocNum)); // Replace whitespaces with dashes

        $filename = 'Carico_'.$cleanDocNum.'_'.$wholesaleIn->created_at->format('dmY').'.xlsx';

        return Excel::download(new WholesaleInExport($wholesaleIn), $filename);
    }

    public function printBarcodes(WholesaleIn $wholesaleIn, BarcodeLabelService $barcodeLabels)
    {
        $this->authorize('view', $wholesaleIn);

        $wholesaleIn->load([
            'records.parentRecord.artist',
            'records.parentRecord.label',
            'records.parentRecord.format',
        ]);

        $labels = $wholesaleIn->records
            ->map->parentRecord
            ->filter()
            ->map(fn ($record) => $barcodeLabels->forRecord($record))
            ->values()
            ->all();

        return view('pdf.barcode-wholesale-in', ['labels' => $labels]);
    }

    public function store(WholesaleInRequest $request)
    {
        $validated = $request->validated();

        // Handle file upload if present (step 1: file processing)
        if ($fileUploadResponse = $this->handleFileUpload($request, $validated)) {
            return $fileUploadResponse;
        }

        // Save WholesaleIn with records (step 2: final submission)
        $this->saveWholesaleInWithRecords($validated);

        // Clear imported records from session after successful processing
        $request->session()->forget('importedRecords');

        return redirect()
            ->route('wholesale-in.index')
            ->with('success', 'Carico inserito correttamente.');
    }

    public function edit(WholesaleIn $wholesaleIn, Request $request)
    {
        $this->authorize('view', $wholesaleIn);

        // Load records with necessary nested relationships for the table
        $wholesaleIn->load([
            'supplier',
            'area',
            // Show records in add order, most recent first (position 0 = most recently added)
            'records' => function ($q) {
                $q->orderBy('position')->orderBy('id');
            },
            'records.parentRecord' => function ($q) {
                $q->addSelect([
                    'last_sale_date' => SaleRecord::select('sales.date')
                        ->join('sales', 'sale_records.sale_id', '=', 'sales.id')
                        ->whereColumn('sale_records.record_id', 'records.id')
                        ->orderByDesc('sales.date')
                        ->limit(1),
                ])->withSum('stocks as total_stocks', 'quantity');
            },
            'records.parentRecord.artist',
            'records.parentRecord.format',
            'records.parentRecord.label',
            'records.parentRecord.media',
            'records.parentRecord.stocks',
            'records.wholesaleInRecordsArea',
        ]);

        $sharedData = $this->getSharedViewData($request);

        return Inertia::render('WholesaleIn/Edit', array_merge($sharedData, [
            // Pass the full WholesaleIn data including formatted records
            'wholesaleIn' => WholesaleInResource::make($wholesaleIn),
            // Only pass importedRecords if they exist (right after file upload redirect)
            // Otherwise pass null so existing records are shown
            'importedRecords' => ! empty($sharedData['importedRecords']) ? $sharedData['importedRecords'] : null,
        ]));
    }

    public function update(WholesaleInRequest $request, WholesaleIn $wholesaleIn)
    {
        $validated = $request->validated();

        // Allow deactivation (status change from 1 to 0) without blocking
        // This is safe because removeStocksForWholesaleIn uses the current DB records,
        // not the submitted data. Any other field changes submitted during deactivation
        // are ignored/safe since the WholesaleIn becomes inactive.
        // Use loose comparison (==) because status might come as string "0" from frontend
        $isDeactivating = $wholesaleIn->status == 1 &&
                         isset($validated['status']) &&
                         $validated['status'] == 0;

        // Block ALL other updates to active WholesaleIns (except deactivation)
        // Once activated, records and quantities must not change to maintain stock integrity
        if ($wholesaleIn->status == 1 && ! $isDeactivating) {
            return redirect()
                ->back()
                ->withErrors([
                    'status' => 'Non è possibile modificare un carico già attivo. Un carico attivato non può essere modificato per mantenere la coerenza dello stock.',
                ])
                ->withInput();
        }

        // Handle file upload if present (step 1: file processing)
        if ($fileUploadResponse = $this->handleFileUpload($request, $validated, $wholesaleIn)) {
            return $fileUploadResponse;
        }

        // Save WholesaleIn with records (step 2: final submission)
        try {
            $this->saveWholesaleInWithRecords($validated, $wholesaleIn);
        } catch (\RuntimeException $e) {
            return redirect()
                ->back()
                ->withErrors([
                    'status' => 'Impossibile disattivare il carico: '.$e->getMessage(),
                ])
                ->withInput();
        }

        // Clear imported records from session after successful processing
        $request->session()->forget('importedRecords');

        // Use Flash (which combines messages) rather than ->with('success', ...), so that a
        // "Pubblicazione su Discogs in corso" message queued during activation is not overwritten.
        Flash::success('Carico aggiornato correttamente.');

        return redirect()->route('wholesale-in.index');
    }

    public function destroy(?WholesaleIn $wholesaleIn, Request $request)
    {

        if ($request->ids) {
            // First pass: validate all can be deleted (check stock for active ones)
            $wholesaleInsToDelete = [];
            foreach ($request->ids as $id) {

                $id = intval($id);

                $wholesailinMulti = WholesaleIn::filterByAdminRoles()->find($id);

                if (! $wholesailinMulti) {
                    continue;
                }

                if ($wholesailinMulti->status === 1) {
                    // Load necessary relationships before checking stocks
                    $wholesailinMulti->load(['records.wholesaleInRecordsArea', 'records.parentRecord']);

                    // Validate stock removal is possible without actually removing
                    foreach ($wholesailinMulti->records as $record) {
                        $areaQuantities = $record->wholesaleInRecordsArea->map(function ($recordArea) {
                            return [
                                'area_id' => $recordArea->area_id,
                                'quantity' => $recordArea->quantity,
                            ];
                        })->toArray();

                        foreach ($areaQuantities as $areaQuantity) {
                            $stock = \App\Models\Stock::where('record_id', $record->record_id)
                                ->where('area_id', $areaQuantity['area_id'])
                                ->first();

                            if ($stock && $stock->quantity < $areaQuantity['quantity']) {
                                $area = \App\Models\Area::find($areaQuantity['area_id']);
                                $parentRecord = $record->parentRecord;
                                $recordIdentifier = $parentRecord->barcode ?: $parentRecord->rr_uid;
                                $recordTitle = $parentRecord->title ?? 'N/A';

                                return back()->withErrors([
                                    'status' => "Impossibile eliminare il carico (ID: {$id}): stock insufficiente. ".
                                        "Disco: {$recordIdentifier} - {$recordTitle}. ".
                                        "Area: {$area->name}. ".
                                        "Richieste {$areaQuantity['quantity']} unità ma solo {$stock->quantity} disponibili.",
                                ]);
                            }
                        }
                    }
                }

                $wholesaleInsToDelete[] = $wholesailinMulti;
            }

            // Second pass: actually delete (we know all checks passed)
            foreach ($wholesaleInsToDelete as $wholesailinMulti) {
                if ($wholesailinMulti->status === 1) {
                    $this->removeStocksForWholesaleIn($wholesailinMulti);
                }

                $wholesailinMulti->delete();
            }

            return back()->with('success', 'Record eliminati con successo');
        }

        $this->authorize('delete', $wholesaleIn);

        if ($wholesaleIn->status === 1) {
            // Load necessary relationships before removing stocks
            $wholesaleIn->load(['records.wholesaleInRecordsArea', 'records.parentRecord']);

            try {
                $this->removeStocksForWholesaleIn($wholesaleIn);
            } catch (\RuntimeException $e) {
                // The exception already contains the detailed message in Italian
                return redirect()
                    ->route('wholesale-in.index')
                    ->withErrors([
                        'status' => 'Impossibile eliminare il carico: '.$e->getMessage(),
                    ]);
            }
        }

        $wholesaleIn->delete();

        return redirect()->route('wholesale-in.index')->with('success', 'Wholesale In deleted successfully.');
    }
}
