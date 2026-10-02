<?php

namespace App\Jobs;

use App\Enums\RecordTypeEnum;
use App\Models\Sale;
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

class ExportSalesJob implements ShouldBeUnique, ShouldQueue
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

    protected int $chunkNumber;

    protected int $totalChunks;

    protected int $chunkSize;

    protected int $totalSales;

    /** Locations the requesting user can see, null when unrestricted */
    protected ?array $allowedLocationIds = null;

    public function __construct(
        string $exportId,
        array $filters,
        array $sorts,
        int $chunkNumber,
        int $totalChunks,
        int $totalSales,
        int $chunkSize = 1000,
        ?array $allowedLocationIds = null
    ) {
        $this->exportId = $exportId;
        $this->filters = $filters;
        $this->sorts = $sorts;
        $this->chunkNumber = $chunkNumber;
        $this->totalChunks = $totalChunks;
        $this->totalSales = $totalSales;
        $this->chunkSize = $chunkSize;
        $this->allowedLocationIds = $allowedLocationIds;

        $this->logDetail("ExportSalesJob constructor: exportId={$exportId}, chunk={$chunkNumber}, totalSales={$totalSales}");
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "export-sales-{$this->exportId}-chunk-{$this->chunkNumber}";
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
            $this->logInfo("ExportSalesJob chunk {$this->chunkNumber} already completed, skipping...");

            // If this is the last chunk, try to combine (in case combination failed before)
            if ($this->chunkNumber === $this->totalChunks) {
                $this->checkAndCombineIfReady();
            }

            return;
        }

        // Update progress with sales count instead of chunk info
        $salesProcessed = ($this->chunkNumber - 1) * $this->chunkSize;

        $message = "{$salesProcessed} vendite su {$this->totalSales} processate";
        $this->logInfo("ExportSalesJob chunk {$this->chunkNumber}: totalSales = {$this->totalSales}, message = {$message}");
        $this->updateProgress('processing', $message);

        try {
            // Check again for cancellation before processing
            if ($this->isExportCancelled() || $this->job->isDeleted()) {
                $this->cleanupOnCancellation();

                return;
            }

            // Build the query with the same filters and sorts as the original export
            $query = $this->buildQuery();

            // Get sales for this chunk
            $offset = ($this->chunkNumber - 1) * $this->chunkSize;
            $sales = $query->offset($offset)->limit($this->chunkSize)->get();

            if ($sales->isEmpty()) {
                $this->updateProgress('completed_chunk', 'Nessuna vendita trovata in questo chunk');

                return;
            }

            // Process sales and create chunk file
            $chunkData = $this->processSales($sales);

            // Check if processing was aborted due to cancellation
            if (empty($chunkData) && ! $sales->isEmpty()) {
                return; // Exit early if cancelled during processing
            }

            $chunkFilePath = $this->saveChunkData($chunkData);

            // Calculate accurate sales processed
            $salesProcessed = ($this->chunkNumber - 1) * $this->chunkSize + $sales->count();

            // Update progress
            $this->updateProgress(
                'completed_chunk',
                "{$salesProcessed} vendite su {$this->totalSales} processate",
                $salesProcessed
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
        $query = Sale::query()
            ->select('sales.*')
            ->leftJoin('locations', 'sales.location_id', '=', 'locations.id')
            ->with(['user', 'location', 'saleRecords.record.artist', 'saleRecords.record.format', 'saleRecords.record.label', 'saleRecords.record.wholesaleInRecords.wholesaleIn.supplier', 'saleRecords.stock.area.locations']);

        if ($this->allowedLocationIds !== null) {
            $query->whereIn('sales.location_id', $this->allowedLocationIds);
        }

        // Apply filters
        foreach ($this->filters as $key => $value) {
            // Skip empty values but allow 0
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            switch ($key) {
                case 'locations.id':
                    $query->where('locations.id', $value);
                    break;
                case 'type':
                    $query->where('sales.type', $value);
                    break;
                case 'search':
                    $query->where(function ($q) use ($value) {
                        $q->where('sales.remote_customer_name', 'LIKE', '%'.$value.'%')
                            ->orWhere('sales.description', 'LIKE', '%'.$value.'%');
                    });
                    break;
                case 'date':
                    if (is_array($value) && isset($value['startDate']) && isset($value['endDate'])) {
                        $query->whereBetween('sales.date', [$value['startDate'], $value['endDate']]);
                    }
                    break;
                case 'supplier_id':
                    $query->whereHas('saleRecords.record.wholesaleInRecords.wholesaleIn', function ($q) use ($value) {
                        $q->where('supplier_id', $value);
                    });
                    break;
            }
        }

        // Apply sorts
        if (empty($this->sorts)) {
            // Default sort: date DESC if no sort specified
            $query->orderBy('sales.date', 'desc');
        } else {
            foreach ($this->sorts as $sort) {
                $direction = $sort['direction'] ?? 'asc';
                switch ($sort['field']) {
                    case 'locations.name':
                        $query->orderBy('locations.name', $direction);
                        break;
                    default:
                        $query->orderBy('sales.'.$sort['field'], $direction);
                        break;
                }
            }
        }

        return $query;
    }

    protected function processSales($sales): array
    {
        $data = [];
        $processedCount = 0;

        foreach ($sales as $sale) {
            // Check for cancellation every 50 sales during processing
            if ($processedCount % 50 === 0 && ($this->isExportCancelled() || $this->job->isDeleted())) {
                $this->cleanupOnCancellation();

                return []; // Return empty array to abort processing
            }

            // Only add rows for SaleRecords (no sale header row)
            if (! $sale->saleRecords->isEmpty()) {
                foreach ($sale->saleRecords as $saleRecord) {
                    $record = $saleRecord->record;
                    $supplier = $record?->wholesaleInRecords?->first()?->wholesaleIn?->supplier?->name ?? '';

                    $locationName = $saleRecord->stock?->area?->locations?->first()?->name ?? '';
                    $recordType = $record?->type ? RecordTypeEnum::from($record->type)->getDescription() : '';

                    $data[] = [
                        $record?->id ?? '',
                        $record?->rr_uid ?? '',
                        $record?->cat_number ?? '',
                        $record?->artist?->name ?? '',
                        $record?->title ?? '',
                        $record?->format?->name ?? '',
                        $record?->label?->name ?? '',
                        $supplier,
                        $record?->barcode ?? '',
                        $recordType,
                        $locationName,
                        $saleRecord->quantity ?? 0,
                        $saleRecord->price?->formatByDecimal() ?? 0,
                        $saleRecord->discount ?? 0,
                        $saleRecord->vat ?? 0,
                        $saleRecord->total_price?->formatByDecimal() ?? 0,
                    ];
                }
            }

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
        $headers = [
            'Record ID',
            'RR UID',
            'Cat#',
            'Artist',
            'Title',
            'Format',
            'Label',
            'Supplier',
            'Barcode',
            'Nuovo/Usato',
            'Location',
            'Quantity',
            'Unit Price',
            'Discount %',
            'VAT %',
            'Total Price',
        ];

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
                    // Null out Cat# (index 2) and Barcode (index 8) before fromArray
                    // to prevent PhpSpreadsheet from casting them as numeric floats.
                    // We write them separately as explicit strings below.
                    $strippedData = array_map(function ($row) {
                        $row[2] = null;
                        $row[8] = null;

                        return $row;
                    }, $chunkData);

                    $worksheet->fromArray($strippedData, null, "A{$currentRow}");

                    // Write Cat# and Barcode as explicit strings
                    foreach ($chunkData as $rowIndex => $row) {
                        $rowNum = $currentRow + $rowIndex;
                        $worksheet->setCellValueExplicit("C{$rowNum}", $row[2] ?? '', DataType::TYPE_STRING);
                        $worksheet->setCellValueExplicit("I{$rowNum}", $row[8] ?? '', DataType::TYPE_STRING);
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
        $finalFilePath = "exports/{$this->exportId}/sales.xlsx";
        $writer = new Xlsx($spreadsheet);

        $this->updateProgressCombining('combining', 'Scrittura file Excel su disco...', $this->totalChunks, 90);

        // Create a temporary file to write to
        $tempFile = tempnam(sys_get_temp_dir(), 'export_sales_');
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

        $lastRow = $worksheet->getHighestRow();

        // Cat# column (C) - left align
        $worksheet->getStyle("C2:C{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        // Barcode column (I) - right align
        $worksheet->getStyle("I2:I{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        // Price columns (M = Unit Price, P = Total Price) - money type so they read as currency and formulas work
        foreach (['M', 'P'] as $priceColumn) {
            $worksheet->getStyle("{$priceColumn}2:{$priceColumn}{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_CURRENCY_EUR);
        }
    }

    protected function updateProgress(string $status, string $message, ?int $salesProcessed = null): void
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
        $progressData['total_records'] = $this->totalSales;

        // Add sales processed if provided
        if ($salesProcessed !== null) {
            $progressData['records_processed'] = $salesProcessed;
        } elseif ($status === 'completed_chunk') {
            // Fallback calculation
            $progressData['records_processed'] = $this->chunkNumber * $this->chunkSize;
        }

        $this->logInfo("updateProgress: Setting total_records to {$this->totalSales} for export {$this->exportId}");
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
                $this->logInfo("ExportSalesJob: Export already in '{$progress['status']}' status, skipping combination");

                return;
            }
        }

        // Check if final file already exists
        $finalFilePath = "exports/{$this->exportId}/sales.xlsx";
        if (Storage::disk('local')->exists($finalFilePath)) {
            $this->logInfo('ExportSalesJob: Final file already exists, skipping combination');

            return;
        }

        // Check if all chunks are completed before combining
        $allChunksExist = true;

        for ($i = 1; $i <= $this->totalChunks; $i++) {
            $chunkFilePath = "exports/{$this->exportId}/chunk_{$i}.json";
            if (! Storage::disk('local')->exists($chunkFilePath)) {
                $this->logInfo("ExportSalesJob: Chunk {$i} missing, cannot combine yet");
                $allChunksExist = false;
                break;
            }
        }

        if ($allChunksExist) {
            $this->logInfo('ExportSalesJob: All chunks exist, starting combination');
            $this->combineChunks();
        } else {
            $this->logInfo('ExportSalesJob: Not all chunks ready, waiting...');
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->updateProgress('failed', 'Export failed: '.$exception->getMessage());
    }
}
