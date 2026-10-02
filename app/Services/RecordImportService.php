<?php

namespace App\Services;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Models\Area;
use App\Models\Artist;
use App\Models\Format;
use App\Models\Label;
use App\Models\Record;
use App\Models\RecordsImport;
use App\Models\RecordsImportRecordTmp;
use App\Models\Stock;
use App\Services\External\DiscogsListingService;
use Illuminate\Support\Facades\Schema;

class RecordImportService
{
    public function __construct(
        private DiscogsListingService $listingService
    ) {}

    /**
     * Find or create a model by name with case-insensitive search
     */
    public function findOrCreateModel(?string $name, string $modelClass, string $nameField = 'name'): ?int
    {
        $trimmed = trim($name ?? '');
        if (empty($trimmed)) {
            return null;
        }

        $model = $modelClass::whereRaw('LOWER('.$nameField.') = ?', [strtolower($trimmed)])->first();

        if (! $model) {
            $createData = [$nameField => $trimmed];
            if (Schema::hasColumn((new $modelClass)->getTable(), 'status')) {
                $createData['status'] = 1;
            }
            $model = $modelClass::create($createData);
        }

        return $model->id;
    }

    /**
     * Find existing record using barcode, cat_number, or record_id
     */
    public function findExistingRecord(array $recordData): ?Record
    {
        // NOTE: record matching derived from the old application (bulk_ins.php, Item::GetByData)
        $recordId = empty($recordData['record_id']) ? 0 : intval($recordData['record_id']);

        // First try to find by record_id if provided
        if ($recordId > 0) {
            $record = Record::find($recordId);
            if ($record) {
                return $record;
            }
        }

        // Then try by barcode
        if (! empty($recordData['barcode'])) {
            $record = Record::where('barcode', $recordData['barcode'])->first();
            if ($record) {
                return $record;
            }
        }

        // Finally try by cat_number
        if (! empty($recordData['cat_number'])) {
            return Record::where('cat_number', $recordData['cat_number'])->first();
        }

        return null;
    }

    /**
     * Build data array for RecordsImportRecordTmp creation/update
     */
    public function buildTmpRecordData(array $recordData, int $recordsImportId, ?int $existingRecordId = null): array
    {
        return [
            'barcode' => $recordData['barcode'] ?? '',
            'cat_number' => $recordData['cat_number'] ?? '',
            'release_id' => ! empty($recordData['release_id']) ? (int) $recordData['release_id'] : null, // release_id from Excel file, null (not 0) when absent
            'type' => 'new',
            'title' => $recordData['title'] ?? '',
            'retail_price' => (float) ($recordData['retail_price'] ?? 0),
            'wholesale_price' => (float) ($recordData['wholesale_price'] ?? 0),
            'purchase_price' => (float) ($recordData['purchase_price'] ?? 0),
            'disk_status' => isset($recordData['condition_disk'])
                ? DiskStatusEnum::fromDescription($recordData['condition_disk'])
                : DiskStatusEnum::Mint,
            'cover_status' => isset($recordData['condition_cover'])
                ? CoverStatusEnum::fromDescription($recordData['condition_cover'])
                : CoverStatusEnum::Mint,
            'description' => $recordData['description'] ?? '',
            'comments' => $recordData['comments'] ?? '',
            'for_sale_on_discogs' => (($recordData['d_delete'] ?? false) || empty($recordData['release_id'])) ? 0 : 1,
            'format_id' => ! empty($recordData['format'])
                ? $this->findOrCreateModel($recordData['format'], Format::class)
                : null,
            'label_id' => ! empty($recordData['label'])
                ? $this->findOrCreateModel($recordData['label'], Label::class)
                : null,
            'artist_id' => ! empty($recordData['artist'])
                ? $this->findOrCreateModel($recordData['artist'], Artist::class)
                : null,
            'records_import_id' => $recordsImportId,
            'record_id' => $existingRecordId,
            'soft_delete' => (int) ($recordData['soft_delete'] ?? 0),
            'delete' => (int) ($recordData['delete'] ?? 0),
            'd_delete' => (int) ($recordData['d_delete'] ?? 0),
            'stocks_tmp' => $recordData['stocks_tmp'] ?? null,
        ];
    }

    /**
     * Create or update temporary record
     */
    public function createOrUpdateTmpRecord(array $recordData, RecordsImport $recordsImport): void
    {
        $existingRecord = $this->findExistingRecord($recordData);
        $existingRecordId = $existingRecord?->id;

        if (! $existingRecordId) {
            // Create new temporary record
            $tmpData = $this->buildTmpRecordData($recordData, $recordsImport->id);
            RecordsImportRecordTmp::create($tmpData);
        } else {
            // Handle existing record
            $existingRecordTmp = RecordsImportRecordTmp::where('record_id', $existingRecordId)->first();
            $tmpData = $this->buildTmpRecordData($recordData, $recordsImport->id, $existingRecordId);

            if (! $existingRecordTmp) {
                RecordsImportRecordTmp::create($tmpData);
            } else {
                $existingRecordTmp->update($tmpData);
            }
        }
    }

    /**
     * Process record deletion operations in priority order
     */
    public function processRecordDeletions(RecordsImportRecordTmp $tmpRecord, Record $record): bool
    {
        // PRIORITY 1: Discogs unlisting (if checked, always execute first)
        if ($tmpRecord->d_delete) {
            $this->listingService->deleteRecordListing($record);
        }

        // PRIORITY 2: Soft delete (if checked, execute and skip further operations)
        if ($tmpRecord->soft_delete) {
            $record->delete();

            return true; // Skip further processing
        }

        // PRIORITY 3: Hard delete (if checked, execute and skip further operations)
        if ($tmpRecord->delete) {
            $record->forceDelete();

            return true; // Skip further processing
        }

        return false; // Continue with normal processing
    }

    /**
     * Update existing record from temporary record data
     */
    public function updateRecordFromTmp(Record $record, RecordsImportRecordTmp $tmpRecord): void
    {
        $record->update([
            'barcode' => $tmpRecord->barcode,
            'cat_number' => $tmpRecord->cat_number,
            'release_id' => $tmpRecord->release_id,
            'type' => $tmpRecord->type,
            'title' => $tmpRecord->title,
            'retail_price' => $tmpRecord->retail_price,
            'wholesale_price' => $tmpRecord->wholesale_price,
            'purchase_price' => $tmpRecord->purchase_price,
            'disk_status' => $tmpRecord->disk_status,
            'cover_status' => $tmpRecord->cover_status,
            'description' => $tmpRecord->description,
            'comments' => $tmpRecord->comments,
            'for_sale_on_discogs' => $tmpRecord->for_sale_on_discogs,
            'format_id' => $tmpRecord->format_id,
            'label_id' => $tmpRecord->label_id,
            'artist_id' => $tmpRecord->artist_id,
            'discogs_id' => $tmpRecord->discogs_id,
        ]);

        if ($tmpRecord->stocks_tmp) {
            $importStocks = json_decode($tmpRecord->stocks_tmp, true);
            if (is_array($importStocks)) {

                $importStocksByArea = [];
                foreach ($importStocks as $stock) {
                    if (
                        isset($stock['area_id']) &&
                        isset($stock['quantity']) &&
                        Area::find($stock['area_id'])
                    ) {
                        $importStocksByArea[$stock['area_id']] = $stock['quantity'];
                    }
                }

                $existingStocks = $record->stocks()->get()->keyBy('area_id');

                foreach ($importStocksByArea as $areaId => $quantity) {
                    if ($existingStocks->has($areaId)) {

                        if ($existingStocks[$areaId]->quantity != $quantity) {
                            $existingStocks[$areaId]->update(['quantity' => $quantity]);
                        }
                    } else {

                        Stock::create([
                            'area_id' => $areaId,
                            'quantity' => $quantity,
                            'record_id' => $record->id,
                        ]);
                    }
                }

                // $importAreaIds = array_keys($importStocksByArea);
                // foreach ($existingStocks as $areaId => $stock) {
                //     if (! in_array($areaId, $importAreaIds)) {
                //         $stock->delete();
                //     }
                // }
            }
        }

    }

    /**
     * Create new record from temporary record data
     */
    public function createRecordFromTmp(RecordsImportRecordTmp $tmpRecord): Record
    {

        $record = Record::create([
            'barcode' => $tmpRecord->barcode,
            'cat_number' => $tmpRecord->cat_number,
            'release_id' => $tmpRecord->release_id,
            'type' => $tmpRecord->type,
            'title' => $tmpRecord->title,
            'retail_price' => $tmpRecord->retail_price,
            'wholesale_price' => $tmpRecord->wholesale_price,
            'purchase_price' => $tmpRecord->purchase_price,
            'disk_status' => $tmpRecord->disk_status,
            'cover_status' => $tmpRecord->cover_status,
            'description' => $tmpRecord->description,
            'comments' => $tmpRecord->comments,
            'for_sale_on_discogs' => $tmpRecord->for_sale_on_discogs,
            'format_id' => $tmpRecord->format_id,
            'label_id' => $tmpRecord->label_id,
            'artist_id' => $tmpRecord->artist_id,
            'discogs_id' => $tmpRecord->discogs_id,
        ]);

        if ($tmpRecord->stocks_tmp) {
            $stocks = json_decode($tmpRecord->stocks_tmp, true);
            if (is_array($stocks)) {
                foreach ($stocks as $stock) {
                    if (
                        isset($stock['area_id']) &&
                        isset($stock['quantity']) &&
                        Area::find($stock['area_id'])
                    ) {
                        Stock::create([
                            'area_id' => $stock['area_id'],
                            'quantity' => $stock['quantity'],
                            'record_id' => $record->id,
                        ]);
                    }
                }
            }
        }

        return $record;
    }

    /**
     * Publish draft import by converting temporary records to actual records
     */
    /**
     * Publish draft import by converting temporary records to actual records.
     *
     * @return array{auto_barcode_records: array, duplicate_warnings: array} Records that received auto-generated barcodes and duplicate warnings
     */
    public function publishImport(RecordsImport $recordsImport): array
    {
        $tmpRecords = RecordsImportRecordTmp::where('records_import_id', $recordsImport->id)->get();
        $autoBarcodeRecords = [];
        $duplicateWarnings = [];

        foreach ($tmpRecords as $tmpRecord) {
            if ($tmpRecord->record_id) {
                // Update existing record
                $existingRecord = Record::find($tmpRecord->record_id);
                if ($existingRecord && ! $this->processRecordDeletions($tmpRecord, $existingRecord)) {
                    $this->updateRecordFromTmp($existingRecord, $tmpRecord);

                    // Check for duplicate barcode/cat_number on the matched record
                    $this->checkDuplicates($existingRecord, $duplicateWarnings);
                }
            } else {
                // Create new record, but handle special deletion cases
                if ($tmpRecord->delete && ! $tmpRecord->d_delete && ! $tmpRecord->soft_delete) {
                    // Skip creation for hard delete only case
                    continue;
                }

                $hadNoIdentifiers = empty($tmpRecord->barcode) && empty($tmpRecord->cat_number);

                $newRecord = $this->createRecordFromTmp($tmpRecord);

                // Track records that got auto-generated barcode (both barcode and cat_number were empty)
                if ($hadNoIdentifiers) {
                    $newRecord->refresh();
                    $autoBarcodeRecords[] = $newRecord;
                }

                // Check for duplicate barcode/cat_number on the newly created record
                $newRecord->refresh();
                $this->checkDuplicates($newRecord, $duplicateWarnings);

                // Process deletions after creation
                if (! $this->processRecordDeletions($tmpRecord, $newRecord) && ! $tmpRecord->delete) {
                    // Update temporary record with new record ID only if not hard deleted
                    $tmpRecord->update(['record_id' => $newRecord->id]);
                }
            }
        }

        // Clean up temporary records
        RecordsImportRecordTmp::where('records_import_id', $recordsImport->id)->forceDelete();

        return ['auto_barcode_records' => $autoBarcodeRecords, 'duplicate_warnings' => $duplicateWarnings];
    }

    /**
     * Check for duplicate barcode/cat_number on a record and add warnings
     */
    private function checkDuplicates(Record $record, array &$duplicateWarnings): void
    {
        if (! empty($record->barcode)) {
            $dupeCount = Record::where('barcode', $record->barcode)
                ->where('id', '!=', $record->id)->count();
            if ($dupeCount > 0) {
                $url = route('record.index', ['filter' => ['search' => $record->barcode]]);
                $duplicateWarnings[] = "Barcode <strong>{$record->barcode}</strong> già presente su {$dupeCount} record. <a href=\"{$url}\" target=\"_blank\">Vedi duplicati</a>";
            }
        }

        if (! empty($record->cat_number)) {
            $dupeCount = Record::where('cat_number', $record->cat_number)
                ->where('id', '!=', $record->id)->count();
            if ($dupeCount > 0) {
                $url = route('record.index', ['filter' => ['search' => $record->cat_number]]);
                $duplicateWarnings[] = "Cat# <strong>{$record->cat_number}</strong> già presente su {$dupeCount} record. <a href=\"{$url}\" target=\"_blank\">Vedi duplicati</a>";
            }
        }
    }
}
