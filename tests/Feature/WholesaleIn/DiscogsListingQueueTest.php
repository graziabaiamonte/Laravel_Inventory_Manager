<?php

namespace Tests\Feature\WholesaleIn;

use App\Jobs\ListRecordOnDiscogsJob;
use App\Models\Record;
use App\Models\Stock;
use App\Models\WholesaleIn;
use App\Services\External\DiscogsListingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Bulk wholesale-in activation must not list on Discogs synchronously: each eligible record is
 * queued as a throttled ListRecordOnDiscogsJob so a large activation can't hit the API rate limit.
 */
class DiscogsListingQueueTest extends WholesaleInTestCase
{
    public function test_activation_queues_a_listing_job_per_eligible_record(): void
    {
        $record = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
            'release_id' => 249504,
            'for_sale_on_discogs' => 0,
            'discogs_id' => null,
        ]);
        Stock::factory()->create(['record_id' => $record->id, 'area_id' => $this->area1->id, 'quantity' => 10]);

        $wholesaleIn = WholesaleIn::factory()->create([
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'status' => 0,
            'doc_num' => 'QUEUE-ACT',
        ]);

        Queue::fake();

        $this->actingAs($this->user)->patch(route('wholesale-in.update', $wholesaleIn), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'QUEUE-ACT',
            'description' => 'Test',
            'status' => 1,
            'records' => [
                [
                    'record_id' => $record->id,
                    'quantity' => 2,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 20.00,
                    'vat' => 22,
                    'for_sale_on_discogs' => 1,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 2],
                    ],
                ],
            ],
        ]);

        Queue::assertPushed(ListRecordOnDiscogsJob::class, fn ($job) => $job->recordId === $record->id);
    }

    public function test_job_lists_an_eligible_record(): void
    {
        Http::fake(['*' => Http::response(['listing_id' => 555], 200)]);

        $record = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
            'release_id' => 249504,
            'for_sale_on_discogs' => 1,
            'discogs_id' => null,
        ]);
        Stock::factory()->create(['record_id' => $record->id, 'area_id' => $this->area1->id, 'quantity' => 3]);

        (new ListRecordOnDiscogsJob($record->id))->handle(app(DiscogsListingService::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'marketplace/listings'));
        $this->assertEquals(555, $record->fresh()->discogs_id);
    }

    public function test_job_skips_a_record_that_is_already_listed(): void
    {
        Http::fake();

        $record = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
            'release_id' => 249504,
            'for_sale_on_discogs' => 1,
            'discogs_id' => 111222, // already listed
        ]);
        Stock::factory()->create(['record_id' => $record->id, 'area_id' => $this->area1->id, 'quantity' => 3]);

        (new ListRecordOnDiscogsJob($record->id))->handle(app(DiscogsListingService::class));

        Http::assertNothingSent();
        $this->assertEquals(111222, $record->fresh()->discogs_id);
    }
}
