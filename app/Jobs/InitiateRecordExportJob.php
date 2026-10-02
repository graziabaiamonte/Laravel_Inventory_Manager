<?php

namespace App\Jobs;

use App\Models\Record;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InitiateRecordExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    protected array $filters;

    protected array $sorts;

    protected bool $isWholesaleExport;

    protected bool $isUppercaseExport;

    protected int $chunkSize;

    public function __construct(array $filters = [], array $sorts = [], bool $isWholesaleExport = false, bool $isUppercaseExport = false, int $chunkSize = 2000)
    {
        $this->filters = $filters;
        $this->sorts = $sorts;
        $this->isWholesaleExport = $isWholesaleExport;
        $this->isUppercaseExport = $isUppercaseExport;
        $this->chunkSize = $chunkSize;
    }

    public function handle(): string
    {
        // Generate unique export ID
        $exportId = Str::uuid()->toString();

        // Create export directory with proper permissions
        Storage::disk('local')->makeDirectory("exports/{$exportId}");

        // Set proper permissions for the export directory
        // chown/chgrp require root and fail in local dev environments.
        $exportPath = Storage::disk('local')->path("exports/{$exportId}");
        chmod($exportPath, 0775);
        if (app()->environment('production', 'staging')) {
            chown($exportPath, 'www-data');
            chgrp($exportPath, 'www-data');
        }

        // Count total records to determine number of chunks
        $totalRecords = $this->countTotalRecords();
        $totalChunks = ceil($totalRecords / $this->chunkSize);

        // Initialize progress
        $this->initializeProgress($exportId, $totalRecords, $totalChunks);

        // Dispatch chunk jobs
        for ($i = 1; $i <= $totalChunks; $i++) {
            ExportRecordsJob::dispatch(
                $exportId,
                $this->filters,
                $this->sorts,
                $this->isWholesaleExport,
                $this->isUppercaseExport,
                $i,
                $totalChunks,
                $totalRecords,
                $this->chunkSize
            )->onQueue('exports');
        }

        return $exportId;
    }

    protected function countTotalRecords(): int
    {
        $query = Record::query()
            ->select('records.*')
            ->leftJoin('artists', 'records.artist_id', '=', 'artists.id')
            ->leftJoin('formats', 'records.format_id', '=', 'formats.id')
            ->leftJoin('labels', 'records.label_id', '=', 'labels.id');

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

        $totalRecords = $query->count();

        return $totalRecords;
    }

    protected function initializeProgress(string $exportId, int $totalRecords, int $totalChunks): void
    {
        $progressData = [
            'export_id' => $exportId,
            'status' => 'initiated',
            'message' => "Esportazione iniziata per {$totalRecords} dischi in {$totalChunks} chunk",
            'total_records' => $totalRecords,
            'total_chunks' => $totalChunks,
            'chunk_size' => $this->chunkSize,
            'records_processed' => 0,
            'started_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        Storage::disk('local')->put(
            "exports/{$exportId}/progress.json",
            json_encode($progressData)
        );
    }
}
