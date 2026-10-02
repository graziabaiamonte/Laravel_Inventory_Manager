<?php

namespace App\Jobs;

use App\Models\Sale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InitiateSaleExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    protected array $filters;

    protected array $sorts;

    protected int $chunkSize;

    /** Locations the requesting user can see, null when unrestricted */
    protected ?array $allowedLocationIds;

    public function __construct(array $filters = [], array $sorts = [], int $chunkSize = 1000, ?array $allowedLocationIds = null)
    {
        $this->filters = $filters;
        $this->sorts = $sorts;
        $this->chunkSize = $chunkSize;
        $this->allowedLocationIds = $allowedLocationIds;
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

        // Count total sales to determine number of chunks
        $totalSales = $this->countTotalSales();
        $totalChunks = ceil($totalSales / $this->chunkSize);

        // Initialize progress
        $this->initializeProgress($exportId, $totalSales, $totalChunks);

        // Dispatch chunk jobs
        for ($i = 1; $i <= $totalChunks; $i++) {
            ExportSalesJob::dispatch(
                $exportId,
                $this->filters,
                $this->sorts,
                $i,
                $totalChunks,
                $totalSales,
                $this->chunkSize,
                $this->allowedLocationIds
            )->onQueue('exports');
        }

        return $exportId;
    }

    protected function countTotalSales(): int
    {
        $query = Sale::query()
            ->select('sales.*')
            ->leftJoin('locations', 'sales.location_id', '=', 'locations.id');

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

        return $query->count();
    }

    protected function initializeProgress(string $exportId, int $totalSales, int $totalChunks): void
    {
        $progressData = [
            'export_id' => $exportId,
            'status' => 'initiated',
            'message' => "Esportazione iniziata per {$totalSales} vendite in {$totalChunks} chunk",
            'total_records' => $totalSales,
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
