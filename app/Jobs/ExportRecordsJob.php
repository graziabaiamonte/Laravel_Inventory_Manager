<?php

namespace App\Jobs;

use App\Enums\CoverStatusEnum;
use App\Enums\DiskStatusEnum;
use App\Models\Record;
use App\Traits\LogsToChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExportRecordsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'exports';
    }

    public $timeout = 600; // 10 minutes per chunk

    public $tries = 3;

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public $uniqueFor = 3600; // 1 hour - longer than job timeout

    protected string $exportId;

    protected array $filters;

    protected array $sorts;

    protected bool $isWholesaleExport;

    protected bool $isUppercaseExport;

    protected int $chunkNumber;

    protected int $totalChunks;

    protected int $chunkSize;

    protected int $totalRecords;

    public function __construct(
        string $exportId,
        array $filters,
        array $sorts,
        bool $isWholesaleExport,
        bool $isUppercaseExport,
        int $chunkNumber,
        int $totalChunks,
        int $totalRecords,
        int $chunkSize = 2000
    ) {
        $this->exportId = $exportId;
        $this->filters = $filters;
        $this->sorts = $sorts;
        $this->isWholesaleExport = (bool) $isWholesaleExport;
        $this->isUppercaseExport = (bool) $isUppercaseExport;
        $this->chunkNumber = $chunkNumber;
        $this->totalChunks = $totalChunks;
        $this->totalRecords = $totalRecords;
        $this->chunkSize = $chunkSize;

        $this->logDetail("ExportRecordsJob constructor: exportId={$exportId}, chunk={$chunkNumber}, totalRecords={$totalRecords}, isWholesaleExport={$isWholesaleExport}");
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "export-{$this->exportId}-chunk-{$this->chunkNumber}";
    }

    public function handle(): void
    {
        // Check if export is cancelled before starting
        if ($this->isExportCancelled() || $this->job->isDeleted()) {
            $this->cleanupOnCancellation();

            return;
        }

        // Check if this chunk was already completed
        $chunkFilePath = "exports/{$this->exportId}/chunk_{$this->chunkNumber}.json";
        if (Storage::disk('local')->exists($chunkFilePath)) {
            $this->logInfo("ExportRecordsJob chunk {$this->chunkNumber} already completed, skipping...");

            // If this is the last chunk, try to combine (in case combination failed before)
            if ($this->chunkNumber === $this->totalChunks) {
                $this->checkAndCombineIfReady();
            }

            return;
        }

        // Update progress with records count instead of chunk info
        $recordsProcessed = ($this->chunkNumber - 1) * $this->chunkSize;

        $message = "{$recordsProcessed} dischi su {$this->totalRecords} processati";
        $this->logInfo("ExportRecordsJob chunk {$this->chunkNumber}: totalRecords = {$this->totalRecords}, message = {$message}");
        $this->updateProgress('processing', $message);

        try {
            // Check again for cancellation before processing
            if ($this->isExportCancelled() || $this->job->isDeleted()) {
                $this->cleanupOnCancellation();

                return;
            }

            // Build the query with the same filters and sorts as the original export
            $query = $this->buildQuery();

            // Get records for this chunk
            $offset = ($this->chunkNumber - 1) * $this->chunkSize;
            $records = $query->offset($offset)->limit($this->chunkSize)->get();

            if ($records->isEmpty()) {
                $this->updateProgress('completed_chunk', 'Nessun disco trovato in questo chunk');

                return;
            }

            // Process records and create chunk file
            $chunkData = $this->processRecords($records);

            // Check if processing was aborted due to cancellation
            if (empty($chunkData) && ! $records->isEmpty()) {
                return; // Exit early if cancelled during processing
            }

            $chunkFilePath = $this->saveChunkData($chunkData);

            // Calculate accurate records processed
            $recordsProcessed = ($this->chunkNumber - 1) * $this->chunkSize + $records->count();

            // Update progress
            $this->updateProgress(
                'completed_chunk',
                "{$recordsProcessed} dischi su {$this->totalRecords} processati",
                $recordsProcessed
            );

            // If this is the last chunk, combine all chunks into final file
            if ($this->chunkNumber === $this->totalChunks) {
                $this->checkAndCombineIfReady();
            }

        } catch (\Exception $e) {
            $this->updateProgress('error', "Error processing chunk {$this->chunkNumber}: ".$e->getMessage());
            throw $e;
        }
    }

    protected function isExportCancelled(): bool
    {
        $progressFile = "exports/{$this->exportId}/progress.json";

        if (! Storage::disk('local')->exists($progressFile)) {
            return true; // Consider missing progress file as cancelled
        }

        $progress = json_decode(Storage::disk('local')->get($progressFile), true);

        return isset($progress['status']) && $progress['status'] === 'cancelled';
    }

    protected function cleanupOnCancellation(): void
    {
        try {
            // Clean up chunk file if it exists
            $chunkFilePath = "exports/{$this->exportId}/chunk_{$this->chunkNumber}.json";
            if (Storage::disk('local')->exists($chunkFilePath)) {
                Storage::disk('local')->delete($chunkFilePath);
            }

            // If this is the last chunk, clean up the entire export directory
            if ($this->chunkNumber === $this->totalChunks) {
                if (Storage::disk('local')->exists("exports/{$this->exportId}")) {
                    Storage::disk('local')->deleteDirectory("exports/{$this->exportId}");
                }
            }
        } catch (\Exception $e) {
            // Log the error but don't fail the job
            $this->logWarning("Failed to cleanup export files for {$this->exportId}: ".$e->getMessage());
        }
    }

    protected function buildQuery()
    {
        $query = Record::query()
            ->select('records.*')
            ->leftJoin('artists', 'records.artist_id', '=', 'artists.id')
            ->leftJoin('formats', 'records.format_id', '=', 'formats.id')
            ->leftJoin('labels', 'records.label_id', '=', 'labels.id')
            ->with([
                'artist', 'label', 'format',
                'wholesaleInRecords.wholesaleIn.supplier',
                'wholesaleOutRecords.backorderRecords',
            ]);

        // Load additional relationships for wholesale export
        if ($this->isWholesaleExport) {
            $query->with([
                'stocks' => function ($q) {
                    $q->whereHas('area.locations', function ($subQ) {
                        $subQ->where('type', \App\Enums\LocationTypeEnum::WAREHOUSE);
                    });
                },
            ]);
        }

        // Apply filters
        foreach ($this->filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            switch ($key) {
                case 'location_id':
                case 'format_id':
                case 'artist_id':
                case 'label_id':
                    $query->where('records.'.$key, $value);
                    break;
                case 'supplier_id':
                    $query->whereHas('wholesaleInRecords.wholesaleIn', function ($q) use ($value) {
                        $q->where('wholesale_ins.supplier_id', $value);
                    });
                    break;
                case 'wholesale':
                    // Skip wholesale filter if this is a wholesale export
                    if ($this->isWholesaleExport) {
                        continue 2;
                    }
                    if ($value === 'yes') {
                        $query->where('wholesale_price', '>', 0);
                    } elseif ($value === 'no') {
                        $query->where('wholesale_price', '=', 0);
                    }
                    break;
                case 'price_type':
                    // This is used by price_min and price_max, skip it
                    break;
                case 'search':
                    // Full-text search on multiple columns
                    $query->where(function ($q) use ($value) {
                        $q->where('records.title', 'LIKE', '%'.$value.'%')
                            ->orWhere('records.barcode', 'LIKE', '%'.$value.'%')
                            ->orWhere('records.cat_number', 'LIKE', '%'.$value.'%')
                            ->orWhere('artists.name', 'LIKE', '%'.$value.'%')
                            ->orWhere('formats.name', 'LIKE', '%'.$value.'%')
                            ->orWhere('labels.name', 'LIKE', '%'.$value.'%');
                    });
                    break;
                case 'price_min':
                    $priceType = $this->filters['price_type'] ?? 'purchase_price';
                    if (in_array($priceType, ['purchase_price', 'wholesale_price', 'retail_price'])) {
                        $query->where($priceType, '>=', floatval($value) * 100);
                    }
                    break;
                case 'price_max':
                    $priceType = $this->filters['price_type'] ?? 'purchase_price';
                    if (in_array($priceType, ['purchase_price', 'wholesale_price', 'retail_price'])) {
                        $query->where($priceType, '<=', floatval($value) * 100);
                    }
                    break;
                default:
                    $query->where($key, $value);
            }
        }

        // If this is a wholesale export, automatically apply wholesale condition
        if ($this->isWholesaleExport) {
            $query->where('wholesale_price', '>', 0);

            $query->whereHas('stocks', function ($q) {
                $q->where('quantity', '>', 0);
            });
        }

        // Apply sorts
        foreach ($this->sorts as $sort) {
            $direction = $sort['direction'] ?? 'asc';
            switch ($sort['field']) {
                case 'label_name':
                    $query->orderBy('labels.name', $direction);
                    break;
                case 'artist_name':
                    $query->orderBy('artists.name', $direction);
                    break;
                case 'format_name':
                    $query->orderBy('formats.name', $direction);
                    break;
                default:
                    $query->orderBy('records.'.$sort['field'], $direction);
                    break;
            }
        }

        return $query;
    }

    protected function processRecords($records): array
    {
        $data = [];
        $processedCount = 0;
        $areaColumns = $this->getAreaColumns();

        foreach ($records as $record) {
            // Check for cancellation every 100 records during processing
            if ($processedCount % 100 === 0 && ($this->isExportCancelled() || $this->job->isDeleted())) {
                $this->cleanupOnCancellation();

                return []; // Return empty array to abort processing
            }

            if ($this->isWholesaleExport) {
                // Calculate warehouse stocks - stocks are already filtered to warehouse locations in the query
                $warehouseStockQuantity = $record->stocks->sum('quantity');

                $row = [
                    $record->cat_number,
                    $record->artist?->name,
                    $record->title,
                    $record->format?->name,
                    $record->label?->name,
                    $record->barcode,
                    0,
                    $record->wholesale_price ? $record->wholesale_price->getAmount() / 100 : 0,
                    0,
                    0,
                    $warehouseStockQuantity > 0 ? $warehouseStockQuantity : 0,
                ];
            } else {
                $supplier = $record->wholesaleInRecords->first()?->wholesaleIn?->supplier;

                // $backorderQuantity = $record->wholesaleOutRecords
                //     ->flatMap(function ($outRecord) {
                //         return $outRecord->backorderRecords;
                //     })
                //     ->sum('quantity');

                $last_sale = $record->getLastSaleDate();

                if ($this->isUppercaseExport) {

                    $row = [
                        $record->id,
                        $record->rr_uid,
                        strtoupper($record->cat_number),
                        strtoupper($record->artist?->name),
                        strtoupper($record->title),
                        strtoupper($record->format?->name),
                        strtoupper($record->label?->name),
                        strtoupper($record->barcode),
                        ! empty($record->total_stocks) ? $record->total_stocks : 0,
                        $record->purchase_price?->formatByDecimal(),
                        $record->wholesale_price?->formatByDecimal(),
                        $record->retail_price?->formatByDecimal(),
                        '',
                        '',
                        strtoupper($supplier?->name ?? ''),
                        $record->disk_status !== null ? strtoupper(DiskStatusEnum::from($record->disk_status)->getDescription()) : '', // condition_disk
                        $record->cover_status !== null ? strtoupper(CoverStatusEnum::from($record->cover_status)->getDescription()) : '', // condition_cover
                        strtoupper($record->comments ?? ''),
                        strtoupper($record->description ?? ''),
                        '', // soft_delete
                        '', // delete
                        '', // d_delete
                        $record->release_id ?? '',
                        $record->created_at ? $record->created_at->toDateString() : '',
                        $last_sale ? $last_sale : '',
                        // ! empty($backorderQuantity) ? $backorderQuantity : '0', # pending
                        // ! empty($orderQuantity) ? $orderQuantity : '0',
                    ];

                } else {

                    $row = [
                        $record->id,
                        $record->rr_uid,
                        $record->cat_number,
                        $record->artist?->name,
                        $record->title,
                        $record->format?->name,
                        $record->label?->name,
                        $record->barcode,
                        ! empty($record->total_stocks) ? $record->total_stocks : 0,
                        $record->purchase_price?->formatByDecimal(),
                        $record->wholesale_price?->formatByDecimal(),
                        $record->retail_price?->formatByDecimal(),
                        '',
                        '',
                        $supplier?->name ?? '',
                        $record->disk_status !== null ? DiskStatusEnum::from($record->disk_status)->getDescription() : '', // condition_disk
                        $record->cover_status !== null ? CoverStatusEnum::from($record->cover_status)->getDescription() : '', // condition_cover
                        $record->comments ?? '',
                        $record->description ?? '',
                        '', // soft_delete
                        '', // delete
                        '', // d_delete
                        $record->release_id ?? '',
                        $record->created_at ? $record->created_at->toDateString() : '',
                        $last_sale ? $last_sale : '',
                        // ! empty($backorderQuantity) ? $backorderQuantity : '0', # pending
                        // ! empty($orderQuantity) ? $orderQuantity : '0',
                    ];

                }

                foreach ($areaColumns as $col) {
                    $stock = $record->stocks->firstWhere('area_id', $col['area']->id);
                    $row[] = $stock ? $stock->quantity : 0;
                }
            }

            $data[] = $row;

            $processedCount++;
        }

        return $data;
    }

    protected function saveChunkData(array $data): string
    {
        $chunkFilePath = "exports/{$this->exportId}/chunk_{$this->chunkNumber}.json";

        Storage::disk('local')->put($chunkFilePath, json_encode($data));

        return $chunkFilePath;
    }

    protected function combineChunks(): void
    {
        $this->updateProgressCombining('combining', 'Preparazione creazione file Excel finale...', 0, 0);

        // Create new spreadsheet
        $spreadsheet = new Spreadsheet;
        $worksheet = $spreadsheet->getActiveSheet();

        // Set headers
        if ($this->isWholesaleExport) {
            $headers = [
                'cat#',
                'artist',
                'title',
                'fmt',
                'label',
                'barcode',
                'order_amount',
                'price',
                'discount',
                'iva',
                'q',
            ];
        } else {

            if ($this->isUppercaseExport) {

                $headers = [
                    'ID',
                    'RR UID',
                    'CAT#',
                    'ARTIST',
                    'TITLE',
                    'FMT',
                    'LABEL',
                    'BARCODE',
                    'Q',
                    'PURCHASE_PRICE',
                    'WHOLESALE_PRICE',
                    'RETAIL_PRICE',
                    'DISCOUNT',
                    'IVA',
                    'SUPPLIER',
                    'CONDITION_DISK',
                    'CONDITION_COVER',
                    'COMMENTS',
                    'DESCRIPTION',
                    'SOFT_DELETE',
                    'DELETE',
                    'D_DELETE',
                    'RELEASE_ID (DISCOGS)',
                    'CREATED_AT',
                    'LAST_SALE',
                    // 'pending',
                    // 'order_amount',
                ];

            } else {

                $headers = [
                    'ID',
                    'RR UID',
                    'cat#',
                    'artist',
                    'title',
                    'fmt',
                    'label',
                    'barcode',
                    'q',
                    'purchase_price',
                    'wholesale_price',
                    'retail_price',
                    'discount',
                    'iva',
                    'supplier',
                    'condition_disk',
                    'condition_cover',
                    'comments',
                    'description',
                    'soft_delete',
                    'delete',
                    'd_delete',
                    'release_id (discogs)',
                    'created_at',
                    'last_sale',
                    // 'pending',
                    // 'order_amount',
                ];

            }

            foreach ($this->getAreaColumns() as $col) {
                $label = $col['location']->name.' - '.$col['area']->name;

                if ($col['area']->id === $col['location']->default_area_id) {
                    $label .= ' (default)';
                }
                $headers[] = $label;
            }
        }

        // Write headers
        $worksheet->fromArray($headers, null, 'A1');

        // Style headers
        $lastColumn = $worksheet->getHighestColumn();
        $headerRange = "A1:{$lastColumn}1";
        $worksheet->getStyle($headerRange)->getFont()->setBold(true);

        $currentRow = 2;
        $chunksProcessed = 0;

        // Combine all chunk files (this represents 60% of the combining process)
        for ($i = 1; $i <= $this->totalChunks; $i++) {
            // Check for cancellation during combining phase
            if ($this->isExportCancelled() || $this->job->isDeleted()) {
                $this->cleanupOnCancellation();

                return;
            }

            $chunkFilePath = "exports/{$this->exportId}/chunk_{$i}.json";

            if (Storage::disk('local')->exists($chunkFilePath)) {
                // Calculate weighted progress for chunk combining (0-60%)
                $chunkProgress = round(($chunksProcessed / $this->totalChunks) * 60);

                $this->updateProgressCombining(
                    'combining',
                    'Creando file Excel finale...',
                    $chunksProcessed,
                    $chunkProgress
                );

                $chunkData = json_decode(Storage::disk('local')->get($chunkFilePath), true);

                if (! empty($chunkData)) {
                    // Null out Cat# and Barcode before fromArray to prevent
                    // PhpSpreadsheet from casting them as numeric floats.
                    // We write them separately as explicit strings below.
                    $catIdx = $this->isWholesaleExport ? 0 : 2;
                    $barcodeIdx = $this->isWholesaleExport ? 5 : 7;

                    $strippedData = array_map(function ($row) use ($catIdx, $barcodeIdx) {
                        $row[$catIdx] = null;
                        $row[$barcodeIdx] = null;

                        return $row;
                    }, $chunkData);

                    $worksheet->fromArray($strippedData, null, "A{$currentRow}");

                    // Write Cat# and Barcode as explicit strings
                    $catCol = $this->isWholesaleExport ? 'A' : 'C';
                    $barcodeCol = $this->isWholesaleExport ? 'F' : 'H';

                    foreach ($chunkData as $rowIndex => $row) {
                        $rowNum = $currentRow + $rowIndex;
                        $worksheet->setCellValueExplicit("{$catCol}{$rowNum}", $row[$catIdx] ?? '', DataType::TYPE_STRING);
                        $worksheet->setCellValueExplicit("{$barcodeCol}{$rowNum}", $row[$barcodeIdx] ?? '', DataType::TYPE_STRING);
                    }

                    $currentRow += count($chunkData);
                }

                // Clean up chunk file
                Storage::disk('local')->delete($chunkFilePath);

                // Increment AFTER processing
                $chunksProcessed++;
            }
        }

        // Phase 1 Complete: Chunk combining (0-60% of combining process)
        $this->updateProgressCombining('combining', 'Applicazione formattazione file Excel...', $this->totalChunks, 60);

        // Apply formatting
        $this->applyFormatting($worksheet);

        // Phase 2 Complete: Formatting (60-80% of combining process)
        $this->updateProgressCombining('combining', 'Generazione file Excel finale...', $this->totalChunks, 80);

        // Save final file
        $finalFilePath = "exports/{$this->exportId}/records.xlsx";
        $writer = new Xlsx($spreadsheet);

        $this->updateProgressCombining('combining', 'Scrittura file Excel su disco...', $this->totalChunks, 90);

        // Create a temporary file to write to
        $tempFile = tempnam(sys_get_temp_dir(), 'export_');
        $writer->save($tempFile);

        // Move to storage
        Storage::disk('local')->put($finalFilePath, file_get_contents($tempFile));

        // Mark as completed immediately after file is saved (better UX - download button appears earlier)
        $this->updateProgressCombining('completed', 'Esportazione completata con successo!', $this->totalChunks, 100);

        // Clean up temp file after marking complete
        unlink($tempFile);
    }

    protected function applyFormatting($worksheet): void
    {
        // Get the last column dynamically
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        // Auto-size all columns
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $worksheet->getColumnDimension($columnLetter)->setAutoSize(true);
        }

        // Format specific columns
        $lastRow = $worksheet->getHighestRow();

        // Cat Number column (C) - text format, left align
        $worksheet->getStyle("C2:C{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_TEXT);
        $worksheet->getStyle("C2:C{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Barcode column (H for wholesale, I for regular) - text format, right align
        $barcodeColumn = $this->isWholesaleExport ? 'F' : 'H';
        $worksheet->getStyle("{$barcodeColumn}2:{$barcodeColumn}{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode(NumberFormat::FORMAT_TEXT);
        $worksheet->getStyle("{$barcodeColumn}2:{$barcodeColumn}{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Price columns - money type so they read as currency and formulas work
        // Wholesale: price = H; Regular: purchase/wholesale/retail = J, K, L
        $priceColumns = $this->isWholesaleExport ? ['H'] : ['J', 'K', 'L'];
        foreach ($priceColumns as $priceColumn) {
            $worksheet->getStyle("{$priceColumn}2:{$priceColumn}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_CURRENCY_EUR);
        }
    }

    protected function updateProgress(string $status, string $message, ?int $recordsProcessed = null): void
    {
        // Read existing progress to preserve total_records and other data
        $progressFile = "exports/{$this->exportId}/progress.json";
        $progressData = [];
        if (Storage::disk('local')->exists($progressFile)) {
            $progressData = json_decode(Storage::disk('local')->get($progressFile), true) ?? [];
        }

        // Update only the fields we want to change
        $progressData['status'] = $status;
        $progressData['message'] = $message;
        $progressData['chunk'] = $this->chunkNumber;
        $progressData['total_chunks'] = $this->totalChunks;
        $progressData['updated_at'] = now()->toISOString();

        // Ensure total_records is preserved - use the instance variable
        $progressData['total_records'] = $this->totalRecords;

        // Add records processed if provided
        if ($recordsProcessed !== null) {
            $progressData['records_processed'] = $recordsProcessed;
        } elseif ($status === 'completed_chunk') {
            // Fallback calculation
            $progressData['records_processed'] = $this->chunkNumber * $this->chunkSize;
        }

        $this->logInfo("updateProgress: Setting total_records to {$this->totalRecords} for export {$this->exportId}");
        Storage::disk('local')->put(
            "exports/{$this->exportId}/progress.json",
            json_encode($progressData)
        );
    }

    protected function updateProgressCombining(string $status, string $message, int $chunksProcessed, ?int $customProgress = null): void
    {
        $progressData = [
            'status' => $status,
            'message' => $message,
            'updated_at' => now()->toISOString(),
        ];

        // Use custom progress percentage if provided, otherwise calculate from chunks
        if ($customProgress !== null) {
            // For custom progress, we'll use a virtual chunk system
            // Convert percentage to chunk equivalents for frontend compatibility
            $virtualChunk = round(($customProgress / 100) * $this->totalChunks);
            $progressData['chunk'] = $virtualChunk;
            $progressData['total_chunks'] = $this->totalChunks;
        } else {
            $progressData['chunk'] = $chunksProcessed;
            $progressData['total_chunks'] = $this->totalChunks;
        }

        Storage::disk('local')->put(
            "exports/{$this->exportId}/progress.json",
            json_encode($progressData)
        );
    }

    protected function checkAndCombineIfReady(): void
    {
        // Check if combination is already in progress or completed
        $progressFile = "exports/{$this->exportId}/progress.json";
        if (Storage::disk('local')->exists($progressFile)) {
            $progress = json_decode(Storage::disk('local')->get($progressFile), true);
            if (isset($progress['status']) && in_array($progress['status'], ['combining', 'completed'])) {
                $this->logInfo("ExportRecordsJob: Export already in '{$progress['status']}' status, skipping combination");

                return;
            }
        }

        // Check if final file already exists
        $finalFilePath = "exports/{$this->exportId}/records.xlsx";
        if (Storage::disk('local')->exists($finalFilePath)) {
            $this->logInfo('ExportRecordsJob: Final file already exists, skipping combination');

            return;
        }

        // Check if all chunks are completed before combining
        $allChunksExist = true;

        for ($i = 1; $i <= $this->totalChunks; $i++) {
            $chunkFilePath = "exports/{$this->exportId}/chunk_{$i}.json";
            if (! Storage::disk('local')->exists($chunkFilePath)) {
                $this->logInfo("ExportRecordsJob: Chunk {$i} missing, cannot combine yet");
                $allChunksExist = false;
                break;
            }
        }

        if ($allChunksExist) {
            $this->logInfo('ExportRecordsJob: All chunks exist, starting combination');
            $this->combineChunks();
        } else {
            $this->logInfo('ExportRecordsJob: Not all chunks ready, waiting...');
        }
    }

    private function getAreaColumns(): array
    {
        return \App\Models\Location::with('defaultArea')
            ->where('status', 1)
            ->whereNotNull('default_area_id')
            ->get()
            ->filter(fn ($location) => $location->defaultArea !== null)
            ->map(fn ($location) => [
                'location' => $location,
                'area' => $location->defaultArea,
            ])
            ->values()
            ->all();
    }

    public function failed(\Throwable $exception): void
    {
        $this->updateProgress('failed', 'Export failed: '.$exception->getMessage());
    }
}
