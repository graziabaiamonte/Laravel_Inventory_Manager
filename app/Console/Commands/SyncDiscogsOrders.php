<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\External\DiscogsClient;
use App\Services\External\DiscogsOrderProcessor;
use App\Traits\LogsToChannel;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncDiscogsOrders extends Command
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'discogs';
    }

    protected $signature = 'discogs:sync-orders {--timestamp= : Custom timestamp to sync orders from (ISO format)}';

    protected $description = 'Sync new orders from Discogs marketplace';

    public function __construct(
        private DiscogsClient $discogsClient,
        private DiscogsOrderProcessor $orderProcessor,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Starting Discogs order synchronization...');
        $this->logInfo('Starting Discogs order synchronization...');

        $customTimestamp = $this->option('timestamp');

        $this->info('Received custom timestamp: '.$customTimestamp);
        $this->logInfo('Received custom timestamp: '.$customTimestamp);

        if ($customTimestamp) {
            try {
                $parsedTimestamp = Carbon::parse($customTimestamp)->toISOString();
                $this->info('Using parsed custom timestamp: '.$parsedTimestamp);
                $this->logInfo('Using parsed custom timestamp: '.$parsedTimestamp);
            } catch (\Exception $e) {
                $this->info('Invalid timestamp format provided, using default. Error: '.$e->getMessage());
                $this->logInfo('Invalid timestamp format provided, using default. Error: '.$e->getMessage());
                $parsedTimestamp = null;
            }
        }

        try {
            $lastTimestamp = $customTimestamp ?: $this->getLastTimestamp();

            $this->info('Fetching order from Discogs since: '.$lastTimestamp);
            $this->logInfo('Fetching order from Discogs since: '.$lastTimestamp);

            $orders = $this->fetchDiscogsOrders($lastTimestamp);

            if (empty($orders)) {
                $this->info('No new orders found.');
                $this->logInfo('No new orders found.');

                return 0;
            }

            $this->info('Found '.count($orders).' orders to process.');
            $this->logInfo('Found '.count($orders).' orders to process.');

            $processedCount = 0;
            $errorCount = 0;
            $lastProcessedTimestamp = null;

            foreach ($orders as $order) {
                try {
                    $this->orderProcessor->processOrder($order);
                    $processedCount++;

                    // Keep track of the last successfully processed order's timestamp
                    $lastProcessedTimestamp = $order['created'];

                } catch (\Exception $e) {
                    $errorCount++;
                    $this->error("Error processing order {$order['id']}: ".$e->getMessage());
                    $this->logError('Discogs order processing error', [
                        'order_id' => $order['id'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // Update timestamp only if we successfully processed at least one order
            if ($lastProcessedTimestamp) {
                $this->setLastTimestamp($lastProcessedTimestamp);
            }

            $this->info("Processed {$processedCount} orders successfully, {$errorCount} errors.");
            $this->logInfo("Processed {$processedCount} orders successfully, {$errorCount} errors.", [
                'processed_count' => $processedCount,
                'error_count' => $errorCount,
            ]);

        } catch (\Exception $e) {
            $this->error('Failed to sync Discogs orders: '.$e->getMessage());
            $this->logError('Discogs sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }

        return 0;
    }

    private function fetchDiscogsOrders(?string $lastTimestamp = null): array
    {
        return $this->discogsClient->getOrders([
            'created_after' => $lastTimestamp,
        ]);
    }

    private function getLastTimestamp(): string
    {
        return Setting::get('discogs_last_sync_timestamp', now()->subHours(24)->toISOString());
    }

    private function setLastTimestamp(string $timestamp): void
    {
        Setting::set('discogs_last_sync_timestamp', $timestamp);
    }
}
