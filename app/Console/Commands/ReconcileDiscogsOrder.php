<?php

namespace App\Console\Commands;

use App\Services\External\DiscogsClient;
use App\Services\External\DiscogsOrderProcessor;
use App\Traits\LogsToChannel;
use Illuminate\Console\Command;

class ReconcileDiscogsOrder extends Command
{
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'discogs';
    }

    protected $signature = 'discogs:reconcile-order
        {order_id : The Discogs order ID (e.g. 1307226-69202)}
        {--dry-run : Fetch and simulate the order, rolling back all DB writes and skipping any Discogs mutations and admin emails}';

    protected $description = 'Fetch a specific Discogs order and process it (idempotent, safe to re-run)';

    public function __construct(
        private DiscogsClient $discogsClient,
        private DiscogsOrderProcessor $orderProcessor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $orderId = $this->argument('order_id');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN mode: no DB writes will be persisted, no Discogs mutations will happen, no admin emails will be sent');
        }

        $this->info("Reconciling Discogs order #{$orderId}");
        $this->logInfo('Starting Discogs order reconciliation', [
            'order_id' => $orderId,
            'dry_run' => $dryRun,
        ]);

        $result = $this->discogsClient->getOrder($orderId);

        if (! $result['success']) {
            $this->error("Failed to fetch order #{$orderId} from Discogs: {$result['error']}");
            $this->logError('Failed to fetch order for reconciliation', [
                'order_id' => $orderId,
                'error' => $result['error'],
            ]);

            return 1;
        }

        try {
            $this->orderProcessor->processOrder($result['data'], $dryRun);
        } catch (\Throwable $e) {
            $this->error("Failed to process order #{$orderId}: ".$e->getMessage());
            $this->logError('Discogs order reconciliation error', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }

        $this->info(($dryRun ? 'DRY-RUN complete' : 'Reconciliation complete')." for order #{$orderId}");

        return 0;
    }
}
