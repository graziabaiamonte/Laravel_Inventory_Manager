<?php

namespace Tests\Feature\WholesaleIn;

use App\Models\Record;
use App\Models\Stock;
use App\Services\External\DiscogsListingService;
use Illuminate\Support\Facades\Http;

/**
 * The Excel `discogs_id` column (the Discogs listing ID, by convention) must never be
 * persisted from an import. records.discogs_id is written only from Discogs' confirmation
 * when a listing is actually created, so a record can never show as "listed" without a real
 * listing behind it (the cause of the 404 false positives).
 */
class DiscogsImportTest extends WholesaleInTestCase
{
    public function test_import_does_not_persist_discogs_id_but_keeps_for_sale_flag(): void
    {
        // Guard against any real Discogs HTTP call during record creation.
        Http::fake();

        $catNumber = 'DISCOGS-IMPORT-TEST';

        // Inactive load (status 0) so no listing attempt runs; we only assert what gets stored.
        $this->actingAs($this->user)->post(route('wholesale-in.store'), [
            'supplier_id' => $this->supplier->id,
            'area_id' => $this->area1->id,
            'doc_num' => 'DISCOGS-IMPORT',
            'description' => 'Test',
            'status' => 0,
            'records' => [
                [
                    'record_id' => 0, // new record
                    'cat_number' => $catNumber,
                    'title' => 'Import Discogs Rec',
                    'quantity' => 3,
                    'unit_price' => 10.00,
                    'discount' => 0,
                    'total_price' => 30.00,
                    'vat' => 22,
                    'for_sale_on_discogs' => 1,
                    // A Release ID mistakenly placed in the listing-ID column: must be ignored.
                    'discogs_id' => 36098392,
                    'area_quantities' => [
                        ['area_id' => $this->area1->id, 'quantity' => 3],
                    ],
                ],
            ],
        ]);

        $record = Record::where('cat_number', $catNumber)->first();

        $this->assertNotNull($record, 'The imported record should have been created');
        $this->assertNull($record->discogs_id, 'discogs_id must not be persisted from the import');
        $this->assertEquals(1, $record->for_sale_on_discogs, 'for_sale_on_discogs must be kept');
    }

    public function test_does_not_call_discogs_when_record_has_no_release_id(): void
    {
        Http::fake();

        // Stock present, marked for sale, but no Release ID: listing must be skipped cleanly.
        $record = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
            'release_id' => 0,
            'for_sale_on_discogs' => 1,
            'discogs_id' => null,
        ]);

        $result = app(DiscogsListingService::class)->listRecordOnDiscogs($record->fresh());

        $this->assertFalse($result['success']);
        $this->assertEquals('Missing release_id', $result['error']);
        Http::assertNothingSent();
        $this->assertNull($record->fresh()->discogs_id);
        // A record that can never be listed must not stay flagged "for sale on Discogs".
        $this->assertEquals(0, $record->fresh()->for_sale_on_discogs);
    }

    public function test_clears_for_sale_when_the_listing_fails_at_the_api(): void
    {
        // Discogs rejects the listing (e.g. release not found / not sellable).
        Http::fake(['*' => Http::response(['message' => 'Invalid release_id: release not found.'], 400)]);

        $record = Record::factory()->create([
            'format_id' => $this->format->id,
            'label_id' => $this->label->id,
            'artist_id' => $this->artist->id,
            'release_id' => 999999999, // present, so the attempt reaches the API
            'for_sale_on_discogs' => 1,
            'discogs_id' => null,
        ]);
        Stock::factory()->create([
            'record_id' => $record->id,
            'area_id' => $this->area1->id,
            'quantity' => 5,
        ]);

        $result = app(DiscogsListingService::class)->listRecordOnDiscogs($record->fresh());

        $this->assertFalse($result['success']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'marketplace/listings'));
        $this->assertNull($record->fresh()->discogs_id, 'no listing was created');
        // If it is not on Discogs, it must not show as for sale on Discogs.
        $this->assertEquals(0, $record->fresh()->for_sale_on_discogs);
    }
}
