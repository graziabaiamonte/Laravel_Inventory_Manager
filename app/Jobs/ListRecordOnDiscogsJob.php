<?php

namespace App\Jobs;

use App\Models\Record;
use App\Services\External\DiscogsListingService;
use App\Traits\LogsToChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * Creates a Discogs marketplace listing for a single record, off the request cycle.
 *
 * Bulk wholesale-in activations dispatch one of these per record so we never fire dozens of
 * Discogs API calls synchronously inside one HTTP request. The "discogs" rate limiter (see
 * AppServiceProvider) keeps us under the Discogs API limit across all queued listings.
 */
class ListRecordOnDiscogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use LogsToChannel;

    protected function logChannel(): string
    {
        return 'discogs';
    }

    public function __construct(public int $recordId)
    {
        $this->onQueue('discogs');
    }

    /**
     * Throttle every listing through the shared "discogs" rate limiter. When the limit is hit the
     * job is released back to the queue and retried later.
     */
    public function middleware(): array
    {
        return [new RateLimited('discogs')];
    }

    /**
     * Keep retrying through throttle windows (and transient errors) for up to an hour rather than
     * failing after the worker's default number of tries.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(DiscogsListingService $service): void
    {
        $record = Record::find($this->recordId);

        if (! $record) {
            return;
        }

        // Eligibility is re-checked here because state can change between dispatch and processing
        // (e.g. the record was sold out, already listed, or unmarked in the meantime).
        if ((int) $record->for_sale_on_discogs !== 1 || $record->discogs_id) {
            $this->logInfo('Skipping queued Discogs listing: record no longer eligible', [
                'record_id' => $record->id,
                'for_sale_on_discogs' => $record->for_sale_on_discogs,
                'discogs_id' => $record->discogs_id,
            ]);

            return;
        }

        // Release ID / stock guards and the for-sale-flag cleanup on failure live in the service.
        // notify:false because there is no session/user to flash to from a queued job.
        $service->listRecordOnDiscogs($record, notify: false);
    }
}
